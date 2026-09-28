<?php

namespace App\Controllers;

use phasync\Psr\UnbufferedStream;
use Swerve\CodeIgniter\PsrResponse;
use Swerve\Http\Message\Response;
use Swerve\Http\WebSocket;

/** swerve-codeigniter's test routes (tests/Fixtures/routes/SwerveTest.php) */
class SwerveTest extends BaseController
{
    public function json()
    {
        return $this->response->setJSON(['framework' => 'CodeIgniter', 'version' => \CodeIgniter\CodeIgniter::CI_VERSION]);
    }

    public function jsonEcho()
    {
        return $this->response->setJSON(['received' => $this->request->getJSON(true)]);
    }

    public function form()
    {
        helper('form');

        return form_open('form') . form_input('name') . form_close();
    }

    public function submit()
    {
        return 'Hello ' . esc($this->request->getPost('name'));
    }

    public function upload()
    {
        $file = $this->request->getFile('document');
        if (null === $file || !$file->isValid()) {
            return $this->response->setStatusCode(400)->setBody('invalid upload');
        }
        $name = $file->getClientName();
        $path = $file->store('swerve-test');

        return $this->response->setJSON(['name' => $name, 'md5' => \md5_file(WRITEPATH . 'uploads/' . $path), 'nested' => $this->request->getFileMultiple('more') ? \count($this->request->getFileMultiple('more')) : 0]);
    }

    public function download()
    {
        return $this->response->download(ROOTPATH . 'composer.json', null);
    }

    /** Stores the value in the request-scoped places, waits, and reads them back. */
    public function isolation(string $value)
    {
        $session = session();
        $before  = $session->get('value');
        $session->set('value', $value);
        $renderer = service('renderer');
        $renderer->setVar('value', $value);

        $this->wait((float) ($this->request->getGet('wait') ?? 0.2));

        return $this->response->setJSON([
            'before'   => $before,
            'session'  => $session->get('value'),
            'request'  => $this->request->getGet('v'),
            'segment'  => $this->request->getUri()->getSegment(2),
            'route'    => service('router')->params(),
            'renderer' => $renderer->getData()['value'] ?? null,
            'sid'      => \session_id(),
            'pid'      => \getmypid(),
        ]);
    }

    public function counter()
    {
        $count = (session('count') ?? 0) + 1;
        session()->set('count', $count);

        return $this->response->setJSON(['count' => $count, 'pid' => \getmypid()]);
    }

    public function setFlash()
    {
        return redirect()->to('/flash')->with('message', $this->request->getPost('message'));
    }

    public function flash()
    {
        return 'message: ' . (session()->getFlashdata('message') ?? '(none)');
    }

    public function stream()
    {
        if ($this->request->getGet('session')) {
            session()->set('streamed', true);
        }
        $out = new UnbufferedStream(1, 60);
        \phasync::go(function () use ($out) {
            try {
                $out->append("first\n");
                \phasync::sleep(1);
                $out->append("last\n");
            } finally {
                $out->end();
            }
        });

        return new PsrResponse(new Response($out, ['Content-Type' => 'text/plain']));
    }

    public function ws()
    {
        return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->send("echo: $message");
            }
        }));
    }

    public function slow()
    {
        $this->wait(0.6);

        return 'slow done';
    }

    public function boom()
    {
        throw new \RuntimeException('boom');
    }

    public function memory()
    {
        \gc_collect_cycles();

        return $this->response->setJSON(['memory' => \memory_get_usage()]);
    }

    /** A wait that lets the worker run other requests: sleep() with phasync-ext, phasync::sleep() without. */
    private function wait(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : \phasync::sleep($seconds);
    }
}
