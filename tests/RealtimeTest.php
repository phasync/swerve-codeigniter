<?php

/*
 * A controller returning Swerve\CodeIgniter\PsrResponse: a streamed body (WebSockets: WebSocketTest.php).
 */

beforeEach(function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 1);
});

afterEach(function () {
    expect(app_stop($this->proc))->toBe(0)
        ->and(log_errors($this->log))->toBe([]);
});

test('a streamed response: the first chunk arrives before the last is produced', function () {
    $conn    = raw_request($this->addr, "GET /stream HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $started = \microtime(true);
    $first   = read_until($conn, "first\n");
    $early   = \microtime(true) - $started;
    $rest    = read_until($conn, "last\n");
    $late    = \microtime(true) - $started;
    expect($first)->toContain("Transfer-Encoding: chunked\r\n")
        ->and($first)->toContain("first\n")
        ->and($early)->toBeLessThan(0.5)
        ->and($rest)->toContain("last\n")
        ->and($late)->toBeGreaterThan(0.9);
});

test('a streamed response that starts a session', function () {
    $conn     = raw_request($this->addr, "GET /stream?session=1 HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $response = read_until($conn, "last\n");
    expect($response)->toMatch('/\r\nSet-Cookie: ci_session=[0-9a-f]{32};/i')
        ->and($response)->toContain("first\n")
        ->and($response)->toContain("last\n");
});
