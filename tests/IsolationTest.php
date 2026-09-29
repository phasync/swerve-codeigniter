<?php

/*
 * One worker, requests overlapping: each stores its own value in CodeIgniter's request-scoped
 * places (the request, the URI and route, the session, a shared service), waits (sleep() with
 * phasync-ext, which lets the worker run the others, phasync::sleep() without), and reads them
 * back. Visitors without a session cookie arrive after ones with.
 */

test('overlapping requests each see only their own request, route, session and services', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    try {
        // Five visitors with a session
        $jars = [];
        for ($i = 0; $i < 5; ++$i) {
            $jars[$i] = cookie_jar();
            $seeded   = \json_decode(http($addr, 'GET', "/isolation/seed$i?v=seed$i&wait=0", ['jar' => $jars[$i]])['body'], true);
            expect($seeded['session'])->toBe("seed$i");
        }

        // Ten at once: those five, each followed by a visitor without a cookie
        $requests = [];
        for ($i = 0; $i < 5; ++$i) {
            $requests[] = ['GET', "/isolation/cookie$i?v=cookie$i", ['jar' => $jars[$i]]];
            $requests[] = ['GET', "/isolation/fresh$i?v=fresh$i"];
        }
        $started   = \microtime(true);
        $responses = http_all($addr, $requests);
        $elapsed   = \microtime(true) - $started;

        $sessions = [];
        foreach ($responses as $n => $response) {
            $value = \explode('/', \explode('?', $requests[$n][1])[0])[2];
            $data  = \json_decode($response['body'], true);
            expect($response['status'])->toBe(200)
                ->and($data['request'])->toBe($value)
                ->and($data['segment'])->toBe($value)
                ->and($data['route'])->toBe([$value])
                ->and($data['renderer'])->toBe($value)
                ->and($data['session'])->toBe($value)
                ->and($data['before'])->toBe(\str_starts_with($value, 'cookie') ? 'seed' . \substr($value, 6) : null);
            $sessions[] = $data['sid'];
        }
        expect(\array_unique($sessions))->toHaveCount(10);
        // One at a time: the worker keeps CodeIgniter's request in static services
        expect($elapsed)->toBeGreaterThan(10 * 0.2);
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
    expect(log_errors($log))->toBe([]);
});

/*
 * The places of docs/concurrency.md, one test each group: requests overlapping in one worker, each
 * setting its own value, waiting 0.2 s, and reading it back. overlap() names the places where a
 * request found another's value.
 */

/**
 * @return list<string> the places where one of $n overlapping requests to /overlap saw another's value
 */
function overlap(string $addr, int $n, bool $session): array
{
    $locales  = ['en', 'nb', 'de', 'fr', 'sv'];
    $requests = [];
    for ($i = 0; $i < $n; ++$i) {
        $requests[] = ['GET', "/overlap/v$i?v=v$i&locale=" . $locales[$i % 5] . ($session ? '&session=1' : ''), ['jar' => cookie_jar()]];
    }
    $leaks = [];
    $sids  = [];
    foreach (http_all($addr, $requests) as $i => $response) {
        [$v, $locale] = ["v$i", $locales[$i % 5]];
        $body         = $response['body'];
        $data         = \json_decode(\substr($body, (int) \strpos($body, '{')), true);
        $data         = \is_array($data) ? $data : [];
        $sids[]       = $data['sid'] ?? null;
        $expected     = $session ? ['session' => $v, 'nativeSession' => $v] : [
            'request'  => $v, 'service' => $v, 'get' => $v, 'superglobals' => $v, 'segment' => $v, 'route' => [$v],
            'renderer' => $v, 'config' => $v, 'locale' => $locale, 'language' => $locale, 'intl' => $locale,
        ];
        foreach ($expected as $place => $value) {
            if (($data[$place] ?? null) !== $value) {
                $leaks[] = $place;
            }
        }
        if (200 !== $response['status']) {
            $leaks[] = 'status';
        }
        if (!$session && !\str_starts_with($body, "<$v>{")) {
            $leaks[] = 'output';
        }
        if (!$session && ($response['headers']['x-value'] ?? null) !== [$v]) {
            $leaks[] = 'header';
        }
    }
    if ($session && \count(\array_unique($sids)) !== $n) {
        $leaks[] = 'sid';
    }

    return \array_values(\array_unique($leaks));
}

test('overlapping requests: request, response, output, services, config and locale', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    try {
        expect(overlap($addr, 6, false))->toBe([]);
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
    expect(log_errors($log))->toBe([]);
});

test('overlapping requests: sessions', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    try {
        expect(overlap($addr, 6, true))->toBe([]);
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
    expect(log_errors($log))->toBe([]);
});

test('overlapping requests: an upload read after another request has ended', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    try {
        $file = \tempnam(\sys_get_temp_dir(), 'upload');
        \file_put_contents($file, 'hello');
        $responses = http_all($addr, [
            ['POST', '/upload?wait=0.2', ['body' => ['document' => new CURLFile($file, 'text/plain', 'hello.txt')]]],
            ['GET', '/json'],
        ]);
        expect($responses[0]['status'])->toBe(200)
            ->and(\json_decode($responses[0]['body'], true)['md5'])->toBe(\md5('hello'));
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
});

/*
 * Needs a MySQL or MariaDB database, as a URL in SWERVE_TEST_DATABASE (for example
 * mysql://root:test@127.0.0.1:3306/swerve_ci): the queries wait in the database, SLEEP(0.2).
 */
test('overlapping requests: the database connection, its transactions and insert ids', function () {
    $url = \parse_url((string) \getenv('SWERVE_TEST_DATABASE'));
    $env = ['DBDriver'                                                                         => 'MySQLi', 'hostname' => $url['host'], 'port' => $url['port'] ?? 3306, 'username' => $url['user'],
        'password'                                                                             => $url['pass'] ?? '', 'database' => \ltrim($url['path'], '/')];
    [$proc, $addr, $log] = app_start(workers: 1, env: \array_combine(\array_map(static fn ($k) => "database_default_$k", \array_keys($env)), $env));
    try {
        $run       = \bin2hex(\random_bytes(4));
        $responses = http_all($addr, \array_map(static fn ($i) => ['GET', "/overlap-db/$run-$i"], \range(0, 3)));
        foreach ($responses as $i => $response) {
            expect($response['status'])->toBe(200);
            $data = \json_decode($response['body'], true);
            expect($data['stored'])->toBe([$data['id']])
                ->and($data['model'])->toBe(["$run-$i"]);
        }
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
    expect(log_errors($log))->toBe([]);
})->skip(!\getenv('SWERVE_TEST_DATABASE'), 'needs SWERVE_TEST_DATABASE');
