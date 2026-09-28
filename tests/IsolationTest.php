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
