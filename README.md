# swerve for CodeIgniter

[![CI](https://github.com/phasync/swerve-codeigniter/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-codeigniter/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-codeigniter)](https://packagist.org/packages/phasync/swerve-codeigniter)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-codeigniter/php)](https://packagist.org/packages/phasync/swerve-codeigniter)
![License](https://img.shields.io/github/license/phasync/swerve-codeigniter)

**Your CodeIgniter application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a CodeIgniter 4 application unchanged, on CodeIgniter's own worker mode.

```bash
composer require phasync/swerve-codeigniter
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\CodeIgniter\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

That's the whole setup. `public/index.php` stays as it is, so the same application still runs
under PHP-FPM.

## What changes

| CodeIgniter 4.7 skeleton, 4 processes | PHP-FPM | swerve | |
|---|---:|---:|---:|
| JSON route | [1,547 req/s](benchmarks/results/fpm-4-json.txt) | [2,493 req/s](benchmarks/results/swerve-4-json.txt) | 1.6× |
| Page with a session | [1,405 req/s](benchmarks/results/fpm-4-counter.txt) | [2,200 req/s](benchmarks/results/swerve-4-counter.txt) | 1.6× |

PHP-FPM behind nginx, `wrk -t4 -c64 -d10s`, opcache on, production environment, on a 2-socket
Xeon E5-2697 v3 shared with other work. With phasync-ext loaded: [2,149](benchmarks/results/swerve-4-ext-json.txt)
and [1,822 req/s](benchmarks/results/swerve-4-ext-counter.txt). CodeIgniter's worker mode
builds every config object and most services again for each request; that, not the server, is
most of a request's time. [Method and raw results](benchmarks/).

A controller can also return a PSR-7 response, sent as it is produced: Server-Sent Events, a
streamed download, a WebSocket.

```php
use Swerve\CodeIgniter\PsrResponse;
use Swerve\Http\WebSocket;

public function chat()
{
    return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
        foreach ($ws as $message) {
            $ws->send("echo: $message");
        }
    }));
}
```

`service('psrRequest')` is the request as swerve received it. The body of a `PsrResponse` is
produced after the request has ended, so the code producing it uses what it captured, not the
request's services (session, request, response).

## How it runs

- **Once per worker:** what `public/index.php` does, then `Boot::bootWorker()`, the boot of
  CodeIgniter's own worker mode (its FrankenPHP worker).
- **Per request:** the PSR-7 request becomes the superglobals, the `Superglobals` service, and
  CodeIgniter's `IncomingRequest` with the request body; `CodeIgniter::run()` returns the
  response, which becomes a PSR-7 response with its cookies and CSP headers (a download streams
  from its file). Then worker mode's resets run, as `app/Config/WorkerMode.php` configures them:
  `Services::resetForWorkerMode()`, `Factories::reset()`, `Events::cleanupForWorkerMode()`,
  open database transactions rolled back, connections checked before the next request.
- **Concurrency:** CodeIgniter keeps the current request in static services, so a worker runs
  one request at a time (`phasync\Util\Synchronized`); the others wait their turn. Streamed
  responses and WebSockets are sent after their request, so they don't hold the worker.
- **Sessions:** CodeIgniter's session library with any of its drivers. The session is written
  at the end of each request, PHP's session id is set from the request's cookie (PHP would
  otherwise keep the previous visitor's), and the session cookie PHP sends itself is added to
  the response.
- **Errors:** logged and rendered as CodeIgniter's exception handler does, with the views of
  `app/Views/errors/html`, without the `exit()` that would end the worker.
- **Uploads:** `UploadedFile::isValid()`, `move()` and `store()` work on swerve's uploads,
  which PHP's `is_uploaded_file()` doesn't know.

## Before you deploy

- `exit`, `die()` and `dd()` end the worker, and the requests it is serving with it.
- `app/Config/WorkerMode.php` must exist: the CodeIgniter 4.7 skeleton has it; an application
  upgraded from an older version copies it from the skeleton. Services listed in its
  `$persistentServices` live as long as the worker: don't add any that hold request data.
- `configCacheEnabled` and `locatorCacheEnabled` in `app/Config/Optimize.php` must be off, as
  for any CodeIgniter worker mode: the worker refuses to start otherwise.
- A custom exception handler (`Config\Exceptions::handler()` returning your own) must not call
  `exit()`, as CodeIgniter's own does; this package replaces only CodeIgniter's.
- A request that waits (a slow query, an API call) holds its worker: run enough workers for the
  requests that wait at the same time.
- `is_cli()` is `false` in the workers, as under PHP-FPM; `spark` is unchanged.
- With phasync-ext 0.5.0-alpha8, every worker crashes on its first request:
  [phasync/phasync-ext#9](https://github.com/phasync/phasync-ext/issues/9). Run without the
  extension until a release fixes it.

## Compatibility

| CodeIgniter | PHP | phasync-ext |
|---|---|---|
| 4.7 | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
