<?php

/*
 * Swerve\Http\WebSocket from CodeIgniter controllers (tests/Fixtures/routes/SwerveTest.php): an
 * echo, /news forwarding the topic news, POST /news publishing to it, /me with the session's
 * user. The adapter runs one request at a time per worker; these show that open sockets and
 * their subscriptions don't hold that turn.
 */

afterEach(function () {
    if (isset($this->proc)) {
        expect(app_stop($this->proc))->toBe(0)
            ->and(log_errors($this->log))->toBe([]);
    }
});

/** Connect to /news until $count clients are, on every one of $workers workers; returns them by pid. */
function news_clients(string $addr, int $count, int $workers): array
{
    $clients = [];
    for ($i = 0; $i < 10 * $count && (\count($clients, \COUNT_RECURSIVE) - \count($clients) < $count || \count($clients) < $workers); ++$i) {
        $conn                                      = ws_connect($addr, '/news');
        [, $hello]                                 = ws_read($conn);
        $clients[(int) \explode(' ', $hello)[1]][] = $conn;
    }
    expect($clients)->toHaveCount($workers);

    return $clients;
}

test('a WebSocket from a controller: text and binary, both ways', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 1);
    $conn                                  = ws_connect($this->addr, '/ws');
    for ($i = 0; $i < 5; ++$i) {
        ws_send($conn, 1, "hello $i");
        expect(ws_read($conn))->toBe([1, "echo: hello $i"]);
    }
    $binary = \random_bytes(1000);
    ws_send($conn, 2, $binary);
    expect(ws_read($conn))->toBe([2, $binary]);
    ws_send($conn, 1, 'ÆØÅ');
    expect(ws_read($conn))->toBe([1, 'echo: ÆØÅ']);
    ws_send($conn, 8, \pack('n', 1000));
    expect(ws_read($conn))->toBe([8, \pack('n', 1000)]);
});

test('an ordinary GET to a WebSocket route is answered 426', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 1);
    expect(http($this->addr, 'GET', '/ws')['status'])->toBe(426)
        ->and(http($this->addr, 'GET', '/news')['status'])->toBe(426)
        ->and(http($this->addr, 'GET', '/json')['status'])->toBe(200);
});

test('server push: every client on both workers gets every message, in order', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
    $clients                               = \array_merge(...\array_values(news_clients($this->addr, 10, 2)));

    $sent = [];
    for ($i = 0; $i < 20; ++$i) {
        $sent[] = "news $i";
        expect(http($this->addr, 'POST', '/news', ['body' => "news $i"])['status'])->toBe(204);
    }
    foreach ($clients as $conn) {
        $received = [];
        foreach ($sent as $_) {
            $received[] = ws_read($conn)[1] ?? null;
        }
        expect($received)->toBe($sent);
    }
});

test('clients leaving, with a close frame or without a word: every callback ends', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
    $clients                               = \array_merge(...\array_values(news_clients($this->addr, 12, 2)));
    expect(\array_sum(ws_open($this->addr, 2, 0)))->toBeGreaterThanOrEqual(12);

    foreach ($clients as $i => $conn) {
        if (0 === $i % 2) {
            \fclose($conn);
        } else {
            ws_send($conn, 8, \pack('n', 1000));
            expect(ws_read($conn))->toBe([8, \pack('n', 1000)]);
            \fclose($conn);
        }
    }
    // Nothing published meanwhile: the callbacks wait in their subscription, and still end
    expect(ws_open($this->addr, 2))->each->toBe(0);
});

test('the session, taken before WebSocket::from(): two users each see their own', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
    $ann                                   = session_cookie(http($this->addr, 'POST', '/login', ['body' => ['user' => 'ann']]));
    $bob                                   = session_cookie(http($this->addr, 'POST', '/login', ['body' => ['user' => 'bob']]));

    $sockets = [];
    for ($i = 0; $i < 4; ++$i) {
        foreach (['ann' => $ann, 'bob' => $bob] as $user => $cookie) {
            $conn = ws_connect($this->addr, '/me', [$cookie]);
            expect(ws_read($conn))->toBe([1, "hello $user"]);
            $sockets[] = [$user, $conn];
        }
    }
    $guest = ws_connect($this->addr, '/me');
    expect(ws_read($guest))->toBe([1, 'hello guest']);

    http($this->addr, 'POST', '/news', ['body' => 'hi']);
    foreach ($sockets as [$user, $conn]) {
        expect(ws_read($conn))->toBe([1, "$user: hi"]);
    }
    expect(ws_read($guest))->toBe([1, 'guest: hi']);
});

test('the session read inside the callback is not the socket\'s: it belongs to whichever request ran last', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 1);
    $ann                                   = session_cookie(http($this->addr, 'POST', '/login', ['body' => ['user' => 'ann']]));
    $bob                                   = session_cookie(http($this->addr, 'POST', '/login', ['body' => ['user' => 'bob']]));

    $conn = ws_connect($this->addr, '/me/late', [$ann]);
    http($this->addr, 'GET', '/json', ['headers' => [$bob]]);
    ws_send($conn, 1, 'who am I?');
    expect(ws_read($conn))->toBe([1, 'user: bob']);
    ws_send($conn, 8, \pack('n', 1000));
    expect(ws_read($conn))->toBe([8, \pack('n', 1000)]);
});

test('the worker keeps serving: 500 sockets open over 2 workers, ordinary requests are answered promptly', function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
    $clients                               = news_clients($this->addr, 500, 2);
    $open                                  = ws_open($this->addr, 2, 0);
    expect($open)->toHaveCount(2)->each->toBeGreaterThanOrEqual(200);

    $slowest = 0;
    for ($i = 0; $i < 40; ++$i) {
        $started = \microtime(true);
        expect(http($this->addr, 'GET', '/json')['status'])->toBe(200);
        $slowest = \max($slowest, \microtime(true) - $started);
    }
    expect($slowest)->toBeLessThan(0.5);

    // The sockets still get what is published
    http($this->addr, 'POST', '/news', ['body' => 'still here']);
    foreach ($clients as $conns) {
        foreach ($conns as $conn) {
            expect(ws_read($conn))->toBe([1, 'still here']);
        }
    }
});

test('SIGTERM with sockets open: clients get 1001, callbacks end, exit code 0', function () {
    [$proc, $addr, $log] = app_start(workers: 2);
    $clients             = \array_merge(...\array_values(news_clients($addr, 6, 2)));
    $clients[]           = ws_connect($addr, '/ws');
    $clients[]           = ws_connect($addr, '/ws');

    \proc_terminate($proc, \SIGTERM);
    foreach ($clients as $conn) {
        expect(ws_read($conn))->toBe([8, \pack('n', 1001)]);
    }
    expect(app_exit($proc))->toBe(0)
        ->and(log_errors($log))->toBe([]);
});
