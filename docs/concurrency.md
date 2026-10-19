# Concurrency

A worker runs one CodeIgniter request at a time: `Handler::handle()` holds a
`phasync\Util\Synchronized` lock for the whole request (`src/Handler.php:94`). A request that
waits (a query, an HTTP call, `usleep()` with ext-phasync) keeps its worker, and the next request
waits for it. Size `--workers` for the requests you want served at once.

## Why

Each reason was shown with the lock removed, by the named test of `tests/IsolationTest.php`:
six requests overlapping in one worker, each setting its own value, waiting 0.2 s, and reading
it back. With the lock, all these tests pass. Framework paths are CodeIgniter 4.7.4's `system/`;
`app/` is the appstarter. With the shared services and CodeIgniter instance, requests get each
other's responses, which hides the other places.

| State | Where | What leaked (test place) | no ext | ext |
|---|---|---|:-:|:-:|
| Output buffers | `app/Config/Events.php:33-38` | output, status | leaks | leaks |
| Superglobals | `src/Handler.php:149` | get | leaks | leaks |
| Shared services | `Config/BaseService.php:155` | request, service, superglobals, segment, route, renderer, locale, language | leaks | leaks |
| The CodeIgniter instance | `CodeIgniter.php:93-107`, `:1064` | header | leaks | leaks |
| Config and models | `Config/Factories.php:82` | config | leaks | leaks |
| The default locale | `HTTP/IncomingRequest.php:327` | intl | leaks | leaks |
| `$_SESSION` | `Session/Session.php:296-311` | session, nativeSession | leaks | leaks |
| The session id | PHP's session module | sid | leaks | leaks |
| The database connection | `Database/Config.php:31` | the database test | - | fails |

- **Output buffers.** The appstarter's `pre_system` event ends every output buffer and starts
  its own. A request that starts while another has echoed flushes that output, PHP counts the
  headers as sent, and the next `session_id()` throws: those requests answer 500
  ("request, response, output…"). The 500s hide the other places in that test.
- **Superglobals.** The adapter fills `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and `$_SERVER`
  for each request. The last request to start owns them.
- **Shared services.** `service()` returns one instance per name for the worker: the request,
  the response, the router, the renderer, the language, the session. A request reads the one
  the last request created.
- **The CodeIgniter instance.** `CodeIgniter::run()` keeps the request, response and router in
  fields, and a controller's returned string goes into `$this->response`: a request answered
  with another's response headers.
- **Config and models.** `config()` and `model()` return one instance per class, kept in
  `Factories`. A value one request sets on a config is read by the others.
- **The default locale.** `IncomingRequest::setLocale()` sets intl's default locale for the
  process, which `Time` (`I18n/TimeTrait.php:75`) and the `language` service
  (`Config/Services.php:384`) fall back to.
- **`$_SESSION`.** CodeIgniter's `Session` reads and writes `$_SESSION`, a global: a request
  read another's session value.
- **The database connection.** CodeIgniter keeps one connection per group for the worker,
  pings it as a request starts (`Database/Config.php:162`) and rolls back open transactions as
  one ends (`:180`). With ext-phasync, one request's query waits while another uses the
  connection: "Commands out of sync", "Another coroutine is already waiting to read from this
  stream", and the worker dies (SIGSEGV). Without ext-phasync a query blocks the worker, so
  queries never overlap.

## What this means

Capacity comes from the number of workers, not from concurrency inside one: a request that
waits (a slow query, an HTTP call) holds its worker for the whole wait, and every other request
to that worker waits behind it. Run `--workers` for the number of requests you expect waiting
at the same time.

The lock is unconditional: nothing in this adapter runs part of a request outside it, because
every piece of state above is read or written from ordinary controller code (`config()`,
`model()`, `session()`, the database connection) anywhere during the request, not only at its
edges. A streamed response, Server-Sent Events or a WebSocket is the one exception that does
run unlocked: `WebSocketResponse` and a `PsrResponse`'s stream are sent after `handle()`
returns, while the worker takes its next request, so a long-lived connection should be opened
there instead of held open inside a request.

Two mechanisms could take process-wide state out of the lock, neither wired into this adapter.
`Scope` (the [Scope RFC](https://wiki.php.net/rfc/scope), as fiber-interop/ext-scope, bundled in
the phasync extension, or its userland polyfill) already makes `$_GET`, `$_POST`, `$_COOKIE`,
`$_FILES`, `$_REQUEST` and the default locale and timezone per-scope: running each request in
its own `Scope` would cover the superglobals and default-locale rows above (it leaves
`$_SESSION` untouched). `phasync::onScopeChange(callable $fn)` runs `$fn(Scope $new, ?Scope
$old)` on every scope switch, in the event loop; it is the general way to save and restore a
process-wide static per scope (a `WeakMap<Scope, …>`), and could do the same for the shared
services, `config()`/`model()` instances, `$_SESSION` and the database connection.
