<?php

namespace App\Controllers;

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Swerve\CodeIgniter\PsrResponse;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

/** swerve-codeigniter's test routes (tests/Fixtures/routes/SwerveTest.php) */
class SwerveTest extends BaseController
{
    /** The WebSocket callbacks of /news and /me running in this worker */
    public static int $open = 0;

    public function json()
    {
        return $this->response->setJSON(['framework' => 'CodeIgniter', 'version' => \CodeIgniter\CodeIgniter::CI_VERSION]);
    }

    /** A wait of ?ms= (default 10) in usleep(), as a database query waits: it blocks the worker without phasync-ext. */
    public function usleep()
    {
        $ms = (int) ($this->request->getGet('ms') ?? 10);
        \usleep(1000 * $ms);

        return $this->response->setJSON(['waited' => $ms]);
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
        if (null !== $wait = $this->request->getGet('wait')) {
            $this->wait((float) $wait);
        }
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

    /**
     * Each of CodeIgniter's request-scoped places set to $value, a wait, and each read back: the
     * body is what was echoed, then the JSON (a string, as a controller returns a view).
     */
    public function overlap(string $value)
    {
        $locale = $this->request->getGet('locale') ?? 'en';
        $this->request->setValidLocales(['en', 'nb', 'de', 'fr', 'sv'])->setLocale($locale);
        if ($session = (bool) $this->request->getGet('session')) {
            session()->set('value', $value);
        }
        service('renderer')->setVar('value', $value);
        config(\Config\Email::class)->fromName = $value;
        $this->response->setHeader('X-Value', $value);
        echo "<$value>";

        $this->wait((float) ($this->request->getGet('wait') ?? 0.2));

        return \json_encode([
            'session'       => $session ? session('value') : null,
            'nativeSession' => $_SESSION['value'] ?? null,
            'sid'           => \session_id(),
            'request'       => $this->request->getGet('v'),
            'service'       => service('request')->getGet('v'),
            'get'           => $_GET['v'] ?? null,
            'superglobals'  => service('superglobals')->get('v'),
            'segment'       => service('request')->getUri()->getSegment(2),
            'route'         => service('router')->params(),
            'renderer'      => service('renderer')->getData()['value'] ?? null,
            'config'        => config(\Config\Email::class)->fromName,
            'locale'        => $this->request->getLocale(),
            'language'      => service('language')->getLocale(),
            'intl'          => \Locale::getDefault(),
        ]);
    }

    /**
     * The database, CodeIgniter's one connection per worker: a slow insert (the wait, as the
     * database takes it) in a transaction, its id, the commit; then a model's slow query.
     */
    public function overlapDb(string $value)
    {
        $db = db_connect();
        $db->query('CREATE TABLE IF NOT EXISTS overlap (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(64), KEY (v))');
        $db->transBegin();
        $db->query('INSERT INTO overlap (v) SELECT ? FROM (SELECT SLEEP(?)) AS wait', [$value, (float) ($this->request->getGet('wait') ?? 0.2)]);
        $id = $db->insertID();
        $db->transCommit();
        $mine  = $db->query('SELECT id FROM overlap WHERE v = ?', [$value])->getResultArray();
        $model = model(OverlapModel::class);
        $rows  = $model->select('v, SLEEP(0.1) AS s')->where('v', $value)->findAll();

        return $this->response->setJSON([
            'id'      => (int) $id,
            'stored'  => \array_map('intval', \array_column($mine, 'id')),
            'model'   => \array_values(\array_unique(\array_column($rows, 'v'))),
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

        return new PsrResponse(new Response(200, ['Content-Type' => 'text/plain'], $out));
    }

    public function ws()
    {
        return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
            }
        }));
    }

    /** Server push: forwards the topic news, after saying it has subscribed (and in which worker). */
    public function news()
    {
        return new PsrResponse(WebSocket::from(service('psrRequest'), static function (WebSocket $ws) {
            ++self::$open;
            try {
                $subscription = Swerve::subscribe('news');
                $ws->send('subscribed ' . \getmypid());
                foreach ($subscription as $message) {
                    $ws->send($message);
                }
            } finally {
                --self::$open;
            }
        }));
    }

    public function publish()
    {
        Swerve::publish('news', (string) $this->request->getBody());

        return $this->response->setStatusCode(204);
    }

    public function open()
    {
        return $this->response->setJSON(['pid' => \getmypid(), 'open' => self::$open]);
    }

    public function login()
    {
        session()->set('user', $this->request->getPost('user'));

        return $this->response->setStatusCode(204);
    }

    /** The session's user, taken from the request before the callback, in every message. */
    public function me()
    {
        $user = session('user') ?? 'guest';

        return new PsrResponse(WebSocket::from(service('psrRequest'), static function (WebSocket $ws) use ($user) {
            ++self::$open;
            try {
                $subscription = Swerve::subscribe('news');
                $ws->send("hello $user");
                foreach ($subscription as $message) {
                    $ws->send("$user: $message");
                }
            } finally {
                --self::$open;
            }
        }));
    }

    /** Wrong: reads the session inside the callback, after the request has ended. */
    public function meLate()
    {
        return new PsrResponse(WebSocket::from(service('psrRequest'), static function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->send('user: ' . (session('user') ?? 'guest'));
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

/** A model on the table of SwerveTest::overlapDb() */
class OverlapModel extends \CodeIgniter\Model
{
    protected $table         = 'overlap';
    protected $allowedFields = ['v'];
}
