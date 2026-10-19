# swerve for CodeIgniter

[![CI](https://github.com/phasync/swerve-codeigniter/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-codeigniter/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-codeigniter)](https://packagist.org/packages/phasync/swerve-codeigniter)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-codeigniter/php)](https://packagist.org/packages/phasync/swerve-codeigniter)
![License](https://img.shields.io/github/license/phasync/swerve-codeigniter)

**Your CodeIgniter application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a CodeIgniter 4 application unchanged, on CodeIgniter's own worker mode.

**One request at a time per worker.** CodeIgniter keeps a request's state in shared services,
`config()`/`model()` instances, the default locale, `$_SESSION` and one database connection per
worker, so the handler holds a lock for the whole request and the next request waits
([why](docs/concurrency.md)). Capacity comes from running more workers, not from concurrency
inside one: a request that waits (a slow query, an HTTP call) holds its worker until it
finishes, so size `--workers` for the requests you expect waiting at once. A streamed response,
Server-Sent Events or a WebSocket is sent after its request ends, so once under way it no
longer holds the worker. `Scope` and `phasync::onScopeChange()` could take some of that shared
state out of the lock without this adapter using either yet: [details](docs/concurrency.md).

The framework running correctly on swerve does not mean your application does: state your own
code keeps in statics, globals or shared services, and connections shared between requests, need
the same care. See [Making your application safe for concurrent
requests](https://phasync.github.io/guides/concurrent-requests-in-your-application/).
[phasync/doctor](https://github.com/phasync/doctor) lists the candidates in your code:
`composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`.

```bash
composer require phasync/swerve-codeigniter
```

```bash
vendor/bin/swerve --adapter=codeigniter --http=0.0.0.0:8080 --public=public
```

That's the whole setup. `--adapter=codeigniter` picks this adapter over swerve-psr15's, which
comes with it (or put `"extra": {"swerve": {"adapter": "codeigniter"}}` in your `composer.json`).
`public/index.php` stays as it is, so the same application still runs under PHP-FPM. The handler
is a PSR-15 request handler, served by [swerve-psr15](https://github.com/phasync/swerve-psr15)'s
`Bridge`; a `swerve.php` run with `--adapter=swerve` may return
`Swerve\Psr15\Bridge::wrap(new Swerve\CodeIgniter\Handler(__DIR__))`.

## WebSockets

A WebSocket is a controller method on a GET route. It returns swerve-psr15's `WebSocketResponse`
in a `PsrResponse`; the callback gets swerve's `WebSocket` and runs on the connection after the
handshake:

```php
// app/Config/Routes.php
$routes->get('chat', 'Chat::socket');
```

```php
namespace App\Controllers;

use Swerve\CodeIgniter\PsrResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\WebSocket;

class Chat extends BaseController
{
    public function socket()
    {
        return new PsrResponse(WebSocketResponse::from(service('psrRequest'), function (WebSocket $ws) {
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
    return new PsrResponse(WebSocketResponse::from(service('psrRequest'), function (WebSocket $ws) {
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

**Take the user before `WebSocketResponse::from()`.** The callback runs after the request has ended,
while the worker serves other requests; CodeIgniter's `session()`, `$this->request` and the
other request services then belong to whichever request the worker ran last. Read what the
callback needs in the controller and pass it in:

```php
public function socket()
{
    $userId = session('user_id');               // this request's session, now

    return new PsrResponse(WebSocketResponse::from(service('psrRequest'), function (WebSocket $ws) use ($userId) {
        foreach (Swerve::subscribe("user:$userId") as $message) {
            $ws->send($message);
        }
    }));
}
```

Calling `session('user_id')` inside the callback instead returns the user of another visitor's
request (the tests show it).

**What the tests show**, with and without ext-phasync: text and binary messages both ways;
every published message reaching every client on every worker, in order; callbacks ending when
clients leave, with a close frame or without a word; 500 sockets open on 2 workers while
ordinary requests are still answered at once, since a worker runs one request at a time but an
open socket doesn't hold that turn; on SIGTERM, clients get a close with 1001 and the workers
exit cleanly.

## What changes

CodeIgniter's application is booted once per worker instead of once per request; worker mode
still builds every config object and most services again for each request, as it does under
PHP-FPM.

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
  and the database connection among a worker's requests: [why](docs/concurrency.md). Streamed
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
- **A stop ends the requests in flight.** On a shutdown or reload swerve stops a worker at once:
  a request still waiting then ends, answered `503` by swerve when nothing was sent yet (no error
  page, nothing logged); clients retry.
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
- Every open WebSocket is a connection of one worker; without ext-phasync a worker holds about
  960. Code in a WebSocket callback uses what the controller passed in, not the request's
  services.

## Compatibility

| CodeIgniter | PHP | ext-phasync |
|---|---|---|
| 4.7 | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
