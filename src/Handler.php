<?php

namespace Swerve\CodeIgniter;

use CodeIgniter\Boot;
use CodeIgniter\CodeIgniter;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Database\Config as DatabaseConfig;
use CodeIgniter\Debug\ExceptionHandler as CodeIgniterExceptionHandler;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\HTTPExceptionInterface;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\ResponseInterface as CodeIgniterResponse;
use Config\App;
use Config\Exceptions as ExceptionsConfig;
use Config\Paths;
use Config\Session as SessionConfig;
use Config\WorkerMode;
use phasync\Psr\StringStream;
use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;
use Swerve\Http\Message\Stream;

/**
 * A CodeIgniter 4 application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\CodeIgniter\Handler(__DIR__);
 *
 * Once per worker: CodeIgniter's worker mode boot, Boot::bootWorker(), as its FrankenPHP worker
 * does. Per request: the PSR-7 request becomes the superglobals and CodeIgniter's
 * IncomingRequest; CodeIgniter::run() returns its response, which becomes a PSR-7 response; then
 * the session is written, PHP's session id cleared, and worker mode's resets run
 * (Services::resetForWorkerMode() with app/Config/WorkerMode.php, Factories, Events, database
 * transactions). Concurrency: a worker runs one request at a time (phasync\Util\Synchronized);
 * the others wait their turn. CodeIgniter shares its services, config() and model() instances,
 * the default locale, $_SESSION and the database connection among a worker's requests:
 * docs/concurrency.md.
 */
final class Handler implements RequestHandlerInterface
{
    /**
     * The temporary files of the current request's uploads, for CodeIgniter's UploadedFile.
     *
     * @internal see functions.php
     *
     * @var array<string, true>
     */
    public static array $uploads = [];

    private CodeIgniter $app;
    private WorkerMode $workerMode;

    /** $_SERVER after the boot (the environment and .env), under each request's own values */
    private array $server;
    private int $obLevel;

    /**
     * @param string $root the application's root directory, where composer.json is
     */
    public function __construct(string $root)
    {
        require_once __DIR__ . '/functions.php';

        // Output outside a request still goes to the terminal, but never through PHP's output:
        // once anything has, the CLI counts headers as sent, and session_start() refuses to run
        \ob_start(static function (string $output): string {
            \fwrite(\STDOUT, $output);

            return '';
        }, 1);
        $this->obLevel = \ob_get_level();

        // What public/index.php does before booting
        \defined('FCPATH') || \define('FCPATH', $root . \DIRECTORY_SEPARATOR . 'public' . \DIRECTORY_SEPARATOR);
        \chdir(FCPATH);
        require_once $root . '/app/Config/Paths.php';
        $paths = new Paths();
        require_once $paths->systemDirectory . '/Boot.php';

        $this->app = Boot::bootWorker($paths);
        $this->app->setContext('web');
        $this->workerMode = config(WorkerMode::class);
        $this->server     = $_SERVER;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, fn () => $this->run($request));
    }

    private function run(ServerRequestInterface $request): ResponseInterface
    {
        try {
            DatabaseConfig::reconnectForWorkerMode();
            Services::reconnectCacheForWorkerMode();
            $this->app->resetForWorkerMode();
            $this->globals($request);
            Services::override('psrRequest', $request);

            // PHP keeps the last request's session id, and would start the next session with it
            // instead of looking at the cookie: the next visitor without one would get this one's
            // session. So this request's id is given as PHP would take it from the cookie.
            $id = $_COOKIE[config(SessionConfig::class)->cookieName] ?? '';
            \session_id(\is_string($id) && \preg_match('/\A[a-zA-Z0-9,-]{1,256}\z/', $id) ? $id : '');

            // The request CodeIgniter::run() would create, with the body it would read from php://input
            Services::createRequest(config(App::class));
            if (!$request->hasHeader('Upgrade') && !\str_contains($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
                $body = (string) $request->getBody();
                if ('' !== $body) {
                    Services::request()->setBody($body);
                }
            }

            try {
                $response = $this->app->run(null, true);
            } catch (\Throwable $e) {
                $response = $this->error($e);
            }

            return $this->response($response);
        } finally {
            if (Services::has('session')) {
                Services::session()->close();
            }
            unset($_SESSION);
            self::$uploads = [];

            DatabaseConfig::cleanupForWorkerMode();
            Factories::reset();
            Services::resetForWorkerMode($this->workerMode);
            Events::cleanupForWorkerMode($this->workerMode->resetEventListeners);
            if (CI_DEBUG) {
                Services::toolbar()->reset();
            }
            if ($this->workerMode->forceGarbageCollection) {
                \gc_collect_cycles();
            }
        }
    }

    /** The superglobals, and CodeIgniter's Superglobals service, as PHP-FPM fills them for public/index.php. */
    private function globals(ServerRequestInterface $request): void
    {
        $uri    = $request->getUri();
        $server = [
            'SCRIPT_NAME'     => '/index.php',
            'PHP_SELF'        => '/index.php',
            'SCRIPT_FILENAME' => FCPATH . 'index.php',
            'DOCUMENT_ROOT'   => \rtrim(FCPATH, \DIRECTORY_SEPARATOR),
            'REQUEST_METHOD'  => $request->getMethod(),
            'REQUEST_URI'     => $request->getRequestTarget(),
            'QUERY_STRING'    => $uri->getQuery(),
            'SERVER_NAME'     => $uri->getHost(),
            'SERVER_PORT'     => (string) ($uri->getPort() ?? ('https' === $uri->getScheme() ? 443 : 80)),
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getProtocolVersion(),
        ];
        if ('https' === $uri->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        foreach ($request->getHeaders() as $name => $values) {
            $key          = \strtoupper(\str_replace('-', '_', $name));
            $key          = 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ? $key : "HTTP_$key";
            $server[$key] = \implode(', ', $values);
        }

        $_SERVER  = $request->getServerParams() + $server + $this->server;
        $_GET     = $request->getQueryParams();
        $_POST    = \is_array($post = $request->getParsedBody()) ? $post : [];
        $_COOKIE  = $request->getCookieParams();
        $_FILES   = [];
        $_REQUEST = $_POST + $_GET;
        foreach ($request->getUploadedFiles() as $field => $node) {
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $_FILES[$field][$key] = $this->file($node, $key);
            }
        }

        Services::superglobals()
            ->setServerArray($_SERVER)
            ->setGetArray($_GET)
            ->setPostArray($_POST)
            ->setCookieArray($_COOKIE)
            ->setFilesArray($_FILES)
            ->setRequestArray($_REQUEST);
    }

    /** One of $_FILES' keys for an uploaded file, or for each file of a tree of them, as PHP nests them. */
    private function file(array|UploadedFileInterface $node, string $key): mixed
    {
        if (\is_array($node)) {
            return \array_map(fn ($n) => $this->file($n, $key), $node);
        }
        if ('tmp_name' === $key) {
            if (\UPLOAD_ERR_OK !== $node->getError()) {
                return '';
            }
            $path                 = $node->getStream()->getMetadata('uri');
            self::$uploads[$path] = true;

            return $path;
        }

        return match ($key) {
            'name'  => $node->getClientFilename() ?? '',
            'type'  => $node->getClientMediaType() ?? '',
            'error' => $node->getError(),
            'size'  => $node->getSize() ?? 0,
        };
    }

    /**
     * What CodeIgniter's exception handler answers, without its exit(): logged, and rendered by
     * Config\Exceptions::handler(), with a copy of CodeIgniter's own that doesn't exit.
     */
    private function error(\Throwable $e): CodeIgniterResponse
    {
        $config  = config(ExceptionsConfig::class);
        $status  = $e instanceof HTTPExceptionInterface ? $e->getCode() : 500;
        $request = Services::request();

        if ($config->log && !\in_array($status, $config->ignoreCodes, true)) {
            $route = '' === $request->getPath() ? '/' : $request->getPath();
            $cause = $e;
            $first = true;
            do {
                log_message('critical', ($first ? '' : '[Caused by] ') . $cause::class . ": {message}\n" . ($first ? "[Method: {$request->getMethod()}, Route: $route]\n" : '') . "in {exFile} on line {exLine}.\n{trace}", [
                    'message' => $cause->getMessage(),
                    'exFile'  => clean_path($cause->getFile()),
                    'exLine'  => $cause->getLine(),
                    'trace'   => render_backtrace($cause->getTrace()),
                ]);
                $first = false;
            } while ($cause = $cause->getPrevious());
        }

        $handler = $config->handler($status, $e);
        if ($handler instanceof CodeIgniterExceptionHandler) {
            $handler = new ExceptionHandler($config);
        }
        while (\ob_get_level() > $this->obLevel) {
            \ob_end_clean();
        }
        $response = Services::response();
        \ob_start();
        try {
            $handler->handle($e, $request, $response, $status, EXIT_ERROR);
        } finally {
            $output = \ob_get_clean();
        }
        if ('' !== $output) {
            $response->setBody($output);
        }

        return $response;
    }

    /** CodeIgniter's response as PSR-7, with what Response::send() adds: CSP headers and cookies. */
    private function response(CodeIgniterResponse $response): ResponseInterface
    {
        if ($response instanceof PsrResponse) {
            $psr = $response->psr;
            foreach ($this->cookies($response) as $cookie) {
                $psr = $psr->withAddedHeader('Set-Cookie', $cookie);
            }

            return $psr;
        }

        if ($response instanceof DownloadResponse) {
            $response->buildHeaders();
            [$file, $binary] = (fn () => [$this->file, $this->binary])->call($response);
            $body            = null !== $binary ? new StringStream($binary) : new Stream(\fopen($file->getRealPath(), 'r'));
        } else {
            $response->getCSP()->finalize($response);
            $body = new StringStream((string) $response->getBody());
        }

        $headers = [];
        foreach ($response->headers() as $name => $header) {
            $headers[$name] = \is_array($header) ? \array_map(static fn ($h) => $h->getValueLine(), $header) : [$header->getValueLine()];
        }
        if ([] !== $cookies = $this->cookies($response)) {
            $headers['Set-Cookie'] = $cookies;
        }

        return new Response($body, $headers, $response->getStatusCode(), $response->getReasonPhrase(), $response->getProtocolVersion());
    }

    /**
     * The response's cookies, and the session cookie that PHP sends itself when a session starts
     * or gets a new id, as Set-Cookie values.
     *
     * @return list<string>
     */
    private function cookies(CodeIgniterResponse $response): array
    {
        $cookies = [];
        foreach ($response->getCookieStore()->display() as $cookie) {
            $cookies[$cookie->getPrefixedName()] = $cookie->toHeaderString();
        }
        $name = \session_name();
        if (\PHP_SESSION_ACTIVE === \session_status() && ($_COOKIE[$name] ?? null) !== \session_id() && !isset($cookies[$name])) {
            $params         = \session_get_cookie_params();
            $cookies[$name] = (new Cookie($name, \session_id(), [
                'expires'  => 0 === $params['lifetime'] ? 0 : \time() + $params['lifetime'],
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
                'prefix'   => '',
                'raw'      => true,
            ]))->toHeaderString();
        }

        return \array_values($cookies);
    }
}
