<?php

/*
 * Loaded by Handler's constructor, before CodeIgniter boots, and only in swerve's workers (not
 * through Composer, which would load it for spark too).
 */

namespace {
    /*
     * CodeIgniter defines is_cli() only when it isn't defined yet. A swerve worker runs in the
     * CLI but serves web requests: with CodeIgniter's own, sessions would not start, and errors
     * would render for the terminal.
     */
    function is_cli(): bool
    {
        return false;
    }
}

namespace CodeIgniter\HTTP\Files {
    use Swerve\CodeIgniter\Handler;

    /*
     * CodeIgniter's UploadedFile calls these unqualified, so these take their place in its
     * namespace. PHP only knows the uploads its own SAPI received: swerve's are the temporary
     * files of the current request, in Handler::$uploads.
     */
    function is_uploaded_file(string $filename): bool
    {
        return isset(Handler::$uploads[$filename]) || \is_uploaded_file($filename);
    }

    function move_uploaded_file(string $from, string $to): bool
    {
        if (!isset(Handler::$uploads[$from])) {
            return \move_uploaded_file($from, $to);
        }
        unset(Handler::$uploads[$from]);

        return \rename($from, $to);
    }
}
