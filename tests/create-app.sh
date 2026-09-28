#!/bin/sh
# Create the CodeIgniter test application in tests/Fixtures/app, for CodeIgniter version $1:
# the framework's own skeleton (codeigniter4/appstarter), with this package installed from the
# checkout and the test routes of tests/Fixtures/routes added. Idempotent.
# SWERVE_PATH, when set, installs swerve from that directory instead of Packagist.
set -eu
cd "$(dirname "$0")/Fixtures"
if [ ! -f app/composer.json ]; then
    composer create-project "codeigniter4/appstarter:~$1.0" app --no-interaction --no-progress --no-install
fi
cd app
composer config minimum-stability dev
composer config prefer-stable true
# The adapter from the checkout, by its autoload rule: a path repository's symlink would make the
# tests directory contain itself
php -r '$c = json_decode(file_get_contents("composer.json"), true); $c["autoload"]["psr-4"]["Swerve\\CodeIgniter\\"] = "../../../src/"; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
if [ -n "${SWERVE_PATH:-}" ]; then
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": false, \"versions\": {\"phasync/swerve\": \"0.1.0-alpha12\"}}}"
fi
composer require --no-interaction --no-progress 'phasync/swerve:^0.1.0-alpha12'

cp ../routes/SwerveTest.php app/Controllers/SwerveTest.php
grep -q SwerveTest app/Config/Routes.php || cat ../routes/routes.php >> app/Config/Routes.php
cat > swerve.php <<'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

return new Swerve\CodeIgniter\Handler(__DIR__);
PHP
