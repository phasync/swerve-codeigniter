#!/bin/bash
# CodeIgniter's skeleton (tests/Fixtures/app, made by tests/create-app.sh) under PHP-FPM behind
# nginx and under swerve, 4 processes each, opcache on, CI_ENVIRONMENT=production (the skeleton's
# default, debug off), app/Config/WorkerMode.php as the skeleton ships it.
#
#   benchmarks/run.sh [phasync.so]    (phasync-ext for the third run; without it, that run is skipped)
#
# Raw wrk output goes to benchmarks/results/. Only one benchmark runs at a time on the machine
# (flock on fpm-bench's lock).
set -eu
cd "$(dirname "$0")/.."
app=$PWD/tests/Fixtures/app
out=$PWD/benchmarks/results
ext=${1:-}
fpm=/home/frode/dev/fpm-bench
port=18960
mkdir -p "$out"

# 256 sessions, one cookie each, for the page with a session
cookies=$(mktemp)
sessions() {
    : > "$cookies"
    for i in $(seq 256); do
        curl -s -o /dev/null -D - "http://127.0.0.1:$port/counter" | sed -n 's/^Set-Cookie: \(ci_session=[0-9a-f]*\);.*/\1/p' >> "$cookies"
    done
}

bench() { # name
    local name=$1
    until curl -s -o /dev/null "http://127.0.0.1:$port/json"; do sleep 0.2; done
    sessions
    for path in json counter; do
        local lua=() # the cookie script for the session page
        [ "$path" = counter ] && lua=(-s benchmarks/cookies.lua)
        wrk -t4 -c64 -d3s "${lua[@]}" "http://127.0.0.1:$port/$path" ${lua:+-- "$cookies"} > /dev/null # warm-up
        flock "$fpm/bench.lock" wrk -t4 -c64 -d10s "${lua[@]}" "http://127.0.0.1:$port/$path" ${lua:+-- "$cookies"} > "$out/$name-$path.txt"
        grep -H 'Requests/sec' "$out/$name-$path.txt"
    done
}

stop() {
    kill "$1"
    wait "$1" 2>/dev/null || true
}

"$fpm/serve.sh" "$app/public" $port 4 index.php > /dev/null 2>&1 &
pid=$!
bench fpm-4
stop $pid

swerve() { # name, PHP options
    local name=$1
    shift
    (cd "$app" && exec php -d opcache.enable_cli=1 "$@" vendor/bin/swerve --workers=4 --no-access-log --http=127.0.0.1:$port -q swerve.php) &
    pid=$!
    bench "$name"
    stop $pid
}
swerve swerve-4
if [ -n "$ext" ]; then
    swerve swerve-4-ext -d extension="$ext"
fi
rm -f "$cookies"
rm -f "$app"/writable/session/ci_session*
{
    echo "$(date -u +%FT%TZ) $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) threads"
    php -v | head -1
    [ -n "$ext" ] && php -d extension="$ext" -r 'echo "phasync-ext ", phpversion("phasync"), "\n";'
    (cd "$app" && php -r 'require "vendor/autoload.php"; echo "CodeIgniter ", CodeIgniter\CodeIgniter::CI_VERSION, "\n";')
    (cd "$app" && composer show phasync/swerve 2>/dev/null | sed -n 's/^versions *: /swerve /p')
} > "$out/machine.txt"
