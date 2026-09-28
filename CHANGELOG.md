# Changelog

## 0.1.0-alpha1

- First release: CodeIgniter 4.7 on swerve, on CodeIgniter's worker mode.
- WebSockets from controllers, tested: both ways, server push with `Swerve::subscribe()` over
  workers, clients leaving, the session taken before the callback, workers serving while
  hundreds of sockets are open, and a drain closing them with 1001.
- Requires swerve 0.1.0-alpha15; phasync-ext, when loaded, 0.5.0-alpha10 or later.
