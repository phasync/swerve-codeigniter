<?php

/*
 * CodeIgniter's session library (PHP's native sessions, the file driver) across requests that
 * land on different workers.
 */

beforeEach(function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
});

afterEach(function () {
    expect(app_stop($this->proc))->toBe(0)
        ->and(log_errors($this->log))->toBe([]);
});

test('a counter counts across requests on different workers', function () {
    $jar  = cookie_jar();
    $pids = [];
    for ($i = 1; $i <= 20; ++$i) {
        $data = \json_decode(http($this->addr, 'GET', '/counter', ['jar' => $jar])['body'], true);
        expect($data['count'])->toBe($i);
        $pids[$data['pid']] = true;
    }
    expect(\count($pids))->toBe(2);
});

test('the session cookie is set as PHP sets it, and read back', function () {
    $first = http($this->addr, 'GET', '/counter');
    expect($first['headers']['set-cookie'][0])->toMatch('/^ci_session=[0-9a-f]{32}; Expires=.*; Path=\/; HttpOnly; SameSite=Lax$/');

    $cookie = \explode(';', $first['headers']['set-cookie'][0])[0];
    $again  = http($this->addr, 'GET', '/counter', ['headers' => ["Cookie: $cookie"]]);
    expect(\json_decode($again['body'], true)['count'])->toBe(2);
});

test('a flash message is shown once', function () {
    $jar      = cookie_jar();
    $redirect = http($this->addr, 'POST', '/flash', ['jar' => $jar, 'body' => ['message' => 'Saved!']]);
    expect($redirect['status'])->toBeIn([302, 303])
        ->and($redirect['headers']['location'][0])->toEndWith('/flash');
    expect(http($this->addr, 'GET', '/flash', ['jar' => $jar])['body'])->toBe('message: Saved!');
    expect(http($this->addr, 'GET', '/flash', ['jar' => $jar])['body'])->toBe('message: (none)');
});

test('a request that does not use the session starts none', function () {
    expect(http($this->addr, 'GET', '/', ['headers' => ['Accept: text/html']])['headers'])->not->toHaveKey('set-cookie');
    expect(http($this->addr, 'GET', '/json')['headers'])->not->toHaveKey('set-cookie');
});
