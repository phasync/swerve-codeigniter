<?php

/*
 * The skeleton's own page and the test routes of tests/Fixtures/routes: what a CodeIgniter
 * application does under PHP-FPM, it does on swerve.
 */

beforeEach(function () {
    [$this->proc, $this->addr, $this->log] = app_start(workers: 2);
});

afterEach(function () {
    expect(app_stop($this->proc))->toBe(0)
        ->and(log_errors($this->log))->toBe([]);
});

test('the home page of the skeleton', function () {
    $response = http($this->addr, 'GET', '/', ['headers' => ['Accept: text/html']]);
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toBe(['text/html; charset=UTF-8'])
        ->and($response['body'])->toContain('Welcome to CodeIgniter');
});

test('a JSON route', function () {
    $response = http($this->addr, 'GET', '/json');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'])->toBe(['application/json; charset=UTF-8'])
        ->and(\json_decode($response['body'], true))->toMatchArray(['framework' => 'CodeIgniter']);
});

test('a 404, as a page and for an API client', function () {
    $page = http($this->addr, 'GET', '/missing', ['headers' => ['Accept: text/html']]);
    expect($page['status'])->toBe(404)
        ->and($page['body'])->toContain('404');
    $api = http($this->addr, 'GET', '/missing', ['headers' => ['Accept: application/json']]);
    expect($api['status'])->toBe(404);
});

test('an exception answers 500 with the error page, and the worker serves on', function () {
    $error = http($this->addr, 'GET', '/boom', ['headers' => ['Accept: text/html']]);
    expect($error['status'])->toBe(500)
        ->and($error['body'])->toContain('Whoops!');
    expect(http($this->addr, 'GET', '/json')['status'])->toBe(200);
});

test('a form POST with CSRF protection', function () {
    $jar  = cookie_jar();
    $form = http($this->addr, 'GET', '/form', ['jar' => $jar]);
    expect(\preg_match('/name="csrf_test_name" value="([^"]+)"/', $form['body'], $m))->toBe(1);

    $posted = http($this->addr, 'POST', '/form', ['jar' => $jar, 'body' => ['name' => 'Ada', 'csrf_test_name' => $m[1]]]);
    expect($posted['status'])->toBe(200)
        ->and($posted['body'])->toBe('Hello Ada');

    // Without the token CodeIgniter refuses: in production, by redirecting back
    $refused = http($this->addr, 'POST', '/form', ['jar' => $jar, 'body' => ['name' => 'Eve']]);
    expect($refused['status'])->toBeIn([302, 303])
        ->and($refused['body'])->not->toContain('Hello');
});

test('a JSON POST', function () {
    $response = http($this->addr, 'POST', '/json', ['headers' => ['Content-Type: application/json'], 'body' => '{"a":1,"b":["x"]}']);
    expect(\json_decode($response['body'], true))->toBe(['received' => ['a' => 1, 'b' => ['x']]]);
});

test('an upload, stored with UploadedFile::store(), and a download', function () {
    $content = \random_bytes(200_000);
    $file    = \tempnam(\sys_get_temp_dir(), 'upload');
    \file_put_contents($file, $content);
    $response = http($this->addr, 'POST', '/upload', ['body' => [
        'document' => new CURLFile($file, 'application/octet-stream', 'report.bin'),
        'more[0]'  => new CURLFile($file, 'text/plain', 'a.txt'),
        'more[1]'  => new CURLFile($file, 'text/plain', 'b.txt'),
    ]]);
    \unlink($file);
    expect($response['status'])->toBe(200)
        ->and(\json_decode($response['body'], true))->toBe(['name' => 'report.bin', 'md5' => \md5($content), 'nested' => 2]);

    $download = http($this->addr, 'GET', '/download');
    expect($download['headers']['content-disposition'][0])->toContain('attachment; filename="composer.json"')
        ->and($download['body'])->toBe(\file_get_contents(__DIR__ . '/Fixtures/app/composer.json'));
});
