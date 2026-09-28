<?php

namespace Swerve\CodeIgniter;

use CodeIgniter\HTTP\Response;
use Config\App;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-7 response for a controller to return, sent as it is: a streamed body, Server-Sent
 * Events, a WebSocket.
 *
 *     return new PsrResponse(WebSocket::from(service('psrRequest'), function (WebSocket $ws) {
 *         foreach ($ws as $message) {
 *             $ws->send("echo: $message");
 *         }
 *     }));
 *
 * Its status, headers and body are the PSR-7 response's; cookies set on this object are added.
 * The body is sent after the request has ended: code that produces it must not use the
 * request's services (session, request, response), which then belong to the next request.
 */
class PsrResponse extends Response
{
    public function __construct(public readonly ResponseInterface $psr)
    {
        parent::__construct(config(App::class));
    }
}
