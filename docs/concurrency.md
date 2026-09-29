# Concurrency

A worker runs one CodeIgniter request at a time: `Handler::handle()` holds a
`phasync\Util\Synchronized` lock for the whole request (`src/Handler.php:94`). A request that
waits (a query, an HTTP call, `usleep()` with phasync-ext) keeps its worker, and the next request
waits for it. Size `--workers` for the requests you want served at once: on the `/usleep` route
(a 10 ms wait) one worker with phasync-ext serves 69 req/s (`wrk -t2 -c32 -d5s`).

## Why

Each reason was shown with the lock removed, by the named test of `tests/IsolationTest.php`:
six requests overlapping in one worker, each setting its own value, waiting 0.2 s, and reading
it back. With the lock, all these tests pass. Framework paths are CodeIgniter 4.7.4's `system/`;
`app/` is the appstarter. Columns: without phasync-ext, with it, and with each request run
through `Swerve\Http\Virtual::run()` (phasync-ext's `virtualize()`). With the shared services
and CodeIgniter instance, requests get each other's responses, which hides the other places:
the virtualize column below those two rows was read with both made per request, as on the
branch `concurrent`.

| State | Where | What leaked (test place) | no ext | ext | virtualize |
|---|---|---|:-:|:-:|:-:|
| Output buffers | `app/Config/Events.php:33-38` | output, status | leaks | leaks | - |
| Superglobals | `src/Handler.php:149` | get | leaks | leaks | - |
| Shared services | `Config/BaseService.php:155` | request, service, superglobals, segment, route, renderer, locale, language | leaks | leaks | leaks |
| The CodeIgniter instance | `CodeIgniter.php:93-107`, `:1064` | header | leaks | leaks | leaks |
| Config and models | `Config/Factories.php:82` | config | leaks | leaks | leaks |
| The default locale | `HTTP/IncomingRequest.php:327` | intl | leaks | leaks | leaks |
| `$_SESSION` | `Session/Session.php:296-311` | session, nativeSession | leaks | leaks | leaks |
| The session id | PHP's session module | sid | leaks | leaks | - |
| The database connection | `Database/Config.php:31` | the database test | - | fails | fails |

- **Output buffers.** The appstarter's `pre_system` event ends every output buffer and starts
  its own. A request that starts while another has echoed flushes that output, PHP counts the
  headers as sent, and the next `session_id()` throws: those requests answer 500
  ("request, response, output…"). The 500s hide the other places in that test without
  `virtualize()`.
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
  read another's session value. `virtualize()` gives each request its own session id and
  status, not `$_SESSION` (phasync-ext's README: "`$_SESSION` is a global variable").
- **The database connection.** CodeIgniter keeps one connection per group for the worker,
  pings it as a request starts (`Database/Config.php:162`) and rolls back open transactions as
  one ends (`:180`). With phasync-ext, one request's query waits while another uses the
  connection: "Commands out of sync", "Another coroutine is already waiting to read from this
  stream", and the worker dies (SIGSEGV). Without phasync-ext a query blocks the worker, so
  queries never overlap.

## What was tried

The attempt is on the branch `concurrent` (switches in the environment, see
`Handler::handle()` there).

- **Request-scoped services.** An `ArrayAccess` object in place of `BaseService::$instances`
  keeps each request's instances on its phasync context (the persistent services of
  `app/Config/WorkerMode.php` stay shared), and each request gets its own `CodeIgniter`
  instance. With `virtualize()` this leaves config, intl, `$_SESSION` and the database; on
  `/usleep`, one worker with phasync-ext then serves 969 req/s instead of 69, with those leaks.
- **Request-scoped database connections**, the same way in `Database\Config::$instances`:
  every request answers 500. `Database\Config::getConnections()` must return an array
  (`Database/Config.php:94-96`), and the toolbar filter, required after every request by
  `app/Config/Filters.php:60`, calls it (`Filters/DebugToolbar.php:44`).
- **Config and models.** `Factories::$instances` is a `private static array`: no object can take
  its place, and nothing else reaches the instances.
- **The default locale and `$_SESSION`** are process-wide; nothing runs when a coroutine
  switches that could swap them.
- **A pool of application instances.** Static properties are per process: a second
  `Boot::bootWorker()` shares the services, `Factories`, events and connections of the first. A
  pool only separates the `CodeIgniter` instance, which the scoped attempt creates per request.
- **A narrower lock.** The shared state is used from controller code (`config()`, `model()`,
  `session()`, the connection) for the whole request: a lock around it is a lock around the
  request.
- **Uploads.** `Handler::$uploads` is cleared as each request ends, so an overlapping upload
  became invalid (400, "an upload read after another request has ended"). Removing only the
  request's own files fixes it; the branch does.

## What `virtualize()` removes

With `Swerve\Http\Virtual::run()`, each request has its own output buffers, superglobals, session
id and status. Services, `Factories`, the default locale, `$_SESSION` and the database connection
stay shared by the worker's requests, so the lock stays.
