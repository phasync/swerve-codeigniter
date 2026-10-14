# Changelog

## Unreleased

- Requires phasync and swerve `dev-main`, and swerve-psr15 (from its GitHub repository, until it is on
  Packagist): swerve serves an exchange with a `Swerve\RequestHandler` over a `Swerve\ClientRequest`,
  no longer a PSR-15 handler. `Handler` stays a PSR-15 handler, served through swerve-psr15's `Bridge`.
- The `codeigniter` adapter: `vendor/bin/swerve --adapter=codeigniter` serves the application with no
  `swerve.php`.
- WebSockets: `new PsrResponse(Swerve\Psr15\WebSocketResponse::from(service('psrRequest'), ...))`;
  the callback gets swerve's `Swerve\WebSocket` (`Swerve\Http\WebSocket` is gone from swerve).
- The handler loads the application's Composer autoloader: swerve's worker loads only swerve's own
  dependencies.
- A stopping worker ends its requests at once (swerve's new stop): a request in flight is answered
  `503` by swerve, not CodeIgniter's error page.

## 0.1.0-alpha1

- First release: CodeIgniter 4.7 on swerve, on CodeIgniter's worker mode.
- WebSockets from controllers, tested: both ways, server push with `Swerve::subscribe()` over
  workers, clients leaving, the session taken before the callback, workers serving while
  hundreds of sockets are open, and a drain closing them with 1001.
- Requires swerve 0.1.0-alpha15; phasync-ext, when loaded, 0.5.0-alpha10 or later.
