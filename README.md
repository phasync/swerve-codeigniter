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
composer config minimum-stability alpha   # while swerve is in alpha
composer config prefer-stable true         # everything else stays stable
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

## WebSockets

A WebSocket is a controller method on a GET route. It returns `Swerve\Http\WebSocket` in a
`PsrResponse`; the callback runs on the connection after the handshake:

```php
// app/Config/Routes.php
$routes->get('chat', 'Chat::socket');
```

```php
namespace App\Controllers;

use Swerve\CodeIgniter\PsrResponse;
use Swerve\Http\WebSocket;

class Chat extends BaseController
{
    public function socket()
    {
        return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
            foreach ($ws as $message) {             // ends when the client leaves
                $ws->send("echo: $message");        // sendBinary() for binary messages
            }
        }));
    }
}
```

`service('psrRequest')` is the request as swerve received it. An ordinary GET to the route is
answered 426.

**Server push.** `Swerve::subscribe()` receives what any worker publishes, so an ordinary
controller can reach every open socket:

```php
use Swerve\Swerve;

public function news()                          // GET news, the WebSocket
{
    return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
        foreach (Swerve::subscribe('news') as $message) {
            $ws->send($message);
        }
    }));
}

public function publish()                       // POST news, an ordinary request
{
    Swerve::publish('news', json_encode($this->request->getJSON()));

    return $this->response->setStatusCode(204);
}
```

A callback that only sends, like this one, ends when its client leaves: swerve reads every
connection and cancels the callback. See swerve's [realtime](https://github.com/phasync/swerve/blob/main/docs/realtime.md)
and [publish and subscribe](https://github.com/phasync/swerve/blob/main/docs/publish-subscribe.md) guides.

**Take the user before `WebSocket::from()`.** The callback runs after the request has ended,
while the worker serves other requests; CodeIgniter's `session()`, `$this->request` and the
other request services then belong to whichever request the worker ran last. Read what the
callback needs in the controller and pass it in:

```php
public function socket()
{
    $userId = session('user_id');               // this request's session, now

    return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) use ($userId) {
        foreach (Swerve::subscribe("user:$userId") as $message) {
            $ws->send($message);
        }
    }));
}
```

Calling `session('user_id')` inside the callback instead returns the user of another visitor's
request (the tests show it).

**What the tests show**, with and without phasync-ext: text and binary messages both ways;
every published message reaching every client on every worker, in order; callbacks ending when
clients leave, with a close frame or without a word; 500 sockets open on 2 workers while
ordinary requests are still answered at once, since a worker runs one request at a time but an
open socket doesn't hold that turn; on SIGTERM, clients get a close with 1001 and the workers
exit cleanly.

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

A controller can also return a PSR-7 response in a `Swerve\CodeIgniter\PsrResponse`, sent as it
is produced: Server-Sent Events, a streamed download, a WebSocket.

## How it runs

- **Once per worker:** what `public/index.php` does, then `Boot::bootWorker()`, the boot of
  CodeIgniter's own worker mode (its FrankenPHP worker).
- **Per request:** the PSR-7 request becomes the superglobals, the `Superglobals` service, and
  CodeIgniter's `IncomingRequest` with the request body; `CodeIgniter::run()` returns the
  response, which becomes a PSR-7 response with its cookies and CSP headers (a download streams
  from its file). Then worker mode's resets run, as `app/Config/WorkerMode.php` configures them:
  `Services::resetForWorkerMode()`, `Factories::reset()`, `Events::cleanupForWorkerMode()`,
  open database transactions rolled back, connections checked before the next request.
- **Concurrency:** a worker runs one request at a time (`phasync\Util\Synchronized`); the
  others wait their turn, so size the workers for the requests you serve at once. CodeIgniter
  shares its services, `config()` and `model()` instances, the default locale, `$_SESSION`
  and the database connection among a worker's requests: [why, and what was
  tried](docs/concurrency.md). Streamed responses and WebSockets are sent after their
  request, so they don't hold the worker.
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
- phasync-ext needs 0.5.0-alpha10 or later: with 0.5.0-alpha8 every worker crashes on its
  first request ([phasync/phasync-ext#9](https://github.com/phasync/phasync-ext/issues/9)).
- Every open WebSocket is a connection of one worker; without phasync-ext a worker holds about
  960. Code in a WebSocket callback uses what the controller passed in, not the request's
  services.

## Compatibility

| CodeIgniter | PHP | phasync-ext |
|---|---|---|
| 4.7 | 8.2 – 8.5 | optional, 0.5.0-alpha10 or later; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
