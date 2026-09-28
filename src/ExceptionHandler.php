<?php

namespace Swerve\CodeIgniter;

use CodeIgniter\API\ResponseTrait;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Paths;

/**
 * CodeIgniter's ExceptionHandler (final) as it answers a web request, without its header() and
 * exit(): the error goes into the response, or is output for Handler to put there.
 *
 * @internal
 */
final class ExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    use ResponseTrait;

    /** ResponseTrait needs this. */
    private ?RequestInterface $request = null;

    /** ResponseTrait needs this. */
    private ?ResponseInterface $response = null;

    public function handle(\Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        $this->request  = $request;
        $this->response = $response;

        try {
            $response->setStatusCode($statusCode);
        } catch (HTTPException) {
            $statusCode = 500;
            $response->setStatusCode($statusCode);
        }

        if (!\str_contains($request->getHeaderLine('accept'), 'text/html')) {
            $this->respond($this->isDisplayErrorsEnabled() ? $this->sanitizeData($this->collectVars($exception, $statusCode)) : '', $statusCode);

            return;
        }

        $path    = $this->viewPath . 'html' . \DIRECTORY_SEPARATOR;
        $altPath = \rtrim((new Paths())->viewDirectory, '\\/ ') . \DIRECTORY_SEPARATOR . 'errors' . \DIRECTORY_SEPARATOR . 'html' . \DIRECTORY_SEPARATOR;
        $view    = $this->determineView($exception, $path, $statusCode);
        $altView = $this->determineView($exception, $altPath, $statusCode);

        $this->render($exception, $statusCode, \is_file($path . $view) ? $path . $view : (\is_file($altPath . $altView) ? $altPath . $altView : null));
    }

    private function determineView(\Throwable $exception, string $templatePath, int $statusCode): string
    {
        if ($exception instanceof PageNotFoundException) {
            return 'error_404.php';
        }
        if (\is_file(\rtrim($templatePath, '\\/ ') . \DIRECTORY_SEPARATOR . 'error_' . $statusCode . '.php')) {
            return 'error_' . $statusCode . '.php';
        }

        return $this->isDisplayErrorsEnabled() ? 'error_exception.php' : 'production.php';
    }

    private function isDisplayErrorsEnabled(): bool
    {
        return \in_array(\strtolower(\ini_get('display_errors')), ['1', 'true', 'on', 'yes'], true);
    }

    /** What JSON and XML can hold: resources and closures named, objects as arrays, recursion cut. */
    private function sanitizeData(mixed $data, array &$seen = []): mixed
    {
        if (\is_resource($data) || 'resource (closed)' === \gettype($data)) {
            return '[Resource #' . (int) $data . ']';
        }
        if (\is_array($data)) {
            return \array_map(fn ($value) => $this->sanitizeData($value, $seen), $data);
        }
        if (!\is_object($data)) {
            return $data;
        }
        if (isset($seen[\spl_object_id($data)])) {
            return '[' . $data::class . ' Object *RECURSION*]';
        }
        $seen[\spl_object_id($data)] = true;
        if ($data instanceof \Closure) {
            return '[Closure]';
        }
        $result = [];
        foreach ((array) $data as $key => $value) {
            $result[\preg_replace('/^\x00.*\x00/', '', (string) $key)] = $this->sanitizeData($value, $seen);
        }

        return $result;
    }
}
