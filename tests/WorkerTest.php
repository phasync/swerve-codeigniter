<?php

/*
 * The worker's life: a drain on SIGTERM, and memory over many requests.
 */

test('SIGTERM during a slow request: the request completes, the log has no errors', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    $conn                = raw_request($addr, "GET /slow HTTP/1.1\r\nHost: test\r\n\r\n");
    \usleep(200_000); // the request is waiting now
    \proc_terminate($proc, \SIGTERM);
    $response = read_until($conn, 'slow done');
    expect($response)->toStartWith('HTTP/1.1 200 OK')
        ->and($response)->toContain("Connection: close\r\n")
        ->and($response)->toEndWith('slow done');
    expect(app_stop($proc))->toBe(0)
        ->and(log_errors($log))->toBe([]);
});

test('memory stays flat over 10,000 requests', function () {
    [$proc, $addr, $log] = app_start(workers: 1);
    try {
        $curl = \curl_init();
        \curl_setopt_array($curl, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 10]);
        $run = function (int $n, string $path) use ($curl, $addr) {
            \curl_setopt($curl, \CURLOPT_URL, "http://$addr$path");
            for ($i = 0; $i < $n; ++$i) {
                \curl_exec($curl);
            }
        };
        $memory = function () use ($curl, $addr) {
            \curl_setopt($curl, \CURLOPT_URL, "http://$addr/memory");

            return \json_decode(\curl_exec($curl), true)['memory'];
        };
        \curl_setopt($curl, \CURLOPT_COOKIEFILE, '');
        $run(500, '/json');
        $run(500, '/counter');
        $before = $memory();
        $run(5000, '/json');
        $run(5000, '/counter');
        $after = $memory();
        expect($after - $before)->toBeLessThan(64 * 1024, "grew from $before to $after bytes");
    } finally {
        expect(app_stop($proc))->toBe(0);
    }
    expect(log_errors($log))->toBe([]);
});
