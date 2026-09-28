<?php

/*
 * The tests run the CodeIgniter application in tests/Fixtures/app (made by tests/create-app.sh)
 * on a real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it.
 */

/**
 * Start swerve on a free port with the fixture application and wait until it answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    $socket = \stream_socket_server('tcp://127.0.0.1:0');
    $addr   = \stream_socket_get_name($socket, false);
    \fclose($socket);
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $app      = __DIR__ . '/Fixtures/app';
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg("$app/vendor/bin/swerve") . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg("$app/swerve.php");
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $app, $env + \getenv());
    $deadline = \microtime(true) + 20;
    $curl     = \curl_init("http://$addr/json");
    \curl_setopt_array($curl, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 1]);
    while (false === \curl_exec($curl)) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);
    $deadline = \microtime(true) + 10;
    while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    $code = \proc_close($proc);

    // Before PHP 8.3 only the first proc_get_status() after the exit has the exit code
    return $status['running'] ? $code : $status['exitcode'];
}

/** The lines of swerve's log that report errors. */
function log_errors(string $log): array
{
    return \array_values(\preg_grep('/\b(error|critical|alert|emergency)\b|fatal|exception/i', \file($log)) ?: []);
}

/**
 * One HTTP request with curl, on a connection of its own (so requests spread over the workers).
 *
 * Options: headers (list of "Name: value"), body (a string, or an array: a form, multipart when
 * a value is a CURLFile), jar (a cookie file, read and written), timeout (seconds).
 *
 * @return array{status: int, headers: array<string, string[]>, body: string}
 */
function http(string $addr, string $method, string $path, array $options = []): array
{
    $curl     = http_handle($addr, $method, $path, $options);
    $response = \curl_exec($curl);
    if (false === $response) {
        throw new RuntimeException(\curl_error($curl));
    }

    return http_result($curl, $response);
}

/**
 * Several requests at once, each sent $stagger seconds after the one before.
 *
 * @param list<array{0: string, 1: string, 2?: array}> $requests method, path and options as for http()
 *
 * @return list<array{status: int, headers: array<string, string[]>, body: string}>
 */
function http_all(string $addr, array $requests, float $stagger = 0.02): array
{
    $multi   = \curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $request) {
        $handles[$i] = http_handle($addr, $request[0], $request[1], $request[2] ?? []);
        \curl_multi_add_handle($multi, $handles[$i]);
        $until = \microtime(true) + $stagger;
        do {
            \curl_multi_exec($multi, $running);
            \curl_multi_select($multi, 0.005);
        } while (\microtime(true) < $until);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
    } while ($running > 0);

    return \array_map(fn ($curl) => http_result($curl, (string) \curl_multi_getcontent($curl)), $handles);
}

function http_handle(string $addr, string $method, string $path, array $options): CurlHandle
{
    $curl = \curl_init("http://$addr$path");
    \curl_setopt_array($curl, [
        \CURLOPT_CUSTOMREQUEST  => $method,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_HEADER         => true,
        \CURLOPT_FORBID_REUSE   => true,
        \CURLOPT_FRESH_CONNECT  => true,
        \CURLOPT_TIMEOUT_MS     => (int) (($options['timeout'] ?? 10) * 1000),
        \CURLOPT_HTTPHEADER     => $options['headers'] ?? [],
    ]);
    if (isset($options['body'])) {
        \curl_setopt($curl, \CURLOPT_POSTFIELDS, $options['body']);
    }
    if (isset($options['jar'])) {
        \curl_setopt($curl, \CURLOPT_COOKIEFILE, $options['jar']);
        \curl_setopt($curl, \CURLOPT_COOKIEJAR, $options['jar']);
    }

    return $curl;
}

function http_result(CurlHandle $curl, string $response): array
{
    $size    = \curl_getinfo($curl, \CURLINFO_HEADER_SIZE);
    $headers = [];
    foreach (\explode("\r\n", \substr($response, 0, $size)) as $line) {
        if (\str_contains($line, ':')) {
            [$name, $value]                = \explode(':', $line, 2);
            $headers[\strtolower($name)][] = \trim($value);
        }
    }

    return ['status' => \curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => \substr($response, $size)];
}

/** A new, empty cookie file. */
function cookie_jar(): string
{
    return \tempnam(\sys_get_temp_dir(), 'swerve-jar');
}

/** A raw connection to swerve, with $request sent on it. */
function raw_request(string $addr, string $request)
{
    $conn = \stream_socket_client("tcp://$addr", $errno, $error, 5);
    \stream_set_timeout($conn, 10);
    \fwrite($conn, $request);

    return $conn;
}

/** Read from $conn until $needle has arrived, or the connection ended; returns all that was read. */
function read_until($conn, string $needle): string
{
    $data = '';
    while (!\str_contains($data, $needle) && !\feof($conn)) {
        $data .= \fread($conn, 65536);
    }

    return $data;
}

/** A WebSocket handshake on $path; returns the connection. */
function ws_connect(string $addr, string $path)
{
    $key  = \base64_encode(\random_bytes(16));
    $conn = raw_request($addr, "GET $path HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!\str_contains($head, "\r\n\r\n") && !\feof($conn)) {
        $head .= \fread($conn, 1);
    }
    expect($head)->toStartWith('HTTP/1.1 101')
        ->and($head)->toContain('Sec-WebSocket-Accept: ' . \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    return $conn;
}

/** Send one frame, masked as a client must. */
function ws_send($conn, int $opcode, string $payload): void
{
    $n    = \strlen($payload);
    $mask = \random_bytes(4);
    $head = \chr(0x80 | $opcode) . ($n < 126 ? \chr(0x80 | $n) : \chr(0x80 | 126) . \pack('n', $n));
    \fwrite($conn, $head . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n)));
}

/**
 * The next frame from the server: [opcode, payload], or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($conn): ?array
{
    $head = read_exactly($conn, 2);
    if (null === $head) {
        return null;
    }
    $length = \ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = \unpack('n', read_exactly($conn, 2))[1];
    }

    return [\ord($head[0]) & 0x0F, $length > 0 ? read_exactly($conn, $length) : ''];
}

function read_exactly($conn, int $n): ?string
{
    $data = '';
    while (\strlen($data) < $n && !\feof($conn)) {
        $data .= \fread($conn, $n - \strlen($data));
    }

    return \strlen($data) === $n ? $data : null;
}
