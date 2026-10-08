#!/usr/bin/env bash

set -euo pipefail

DB_QUEUE_RETRY_AFTER=3700 php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=3600 --memory=512 --no-interaction &
queue_listener_pid=$!

php artisan schedule:work &
scheduler_pid=$!

cleanup() {
    kill "$queue_listener_pid" 2>/dev/null || true
    kill "$scheduler_pid" 2>/dev/null || true
    wait "$queue_listener_pid" 2>/dev/null || true
    wait "$scheduler_pid" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

while kill -0 "$queue_listener_pid" 2>/dev/null && kill -0 "$scheduler_pid" 2>/dev/null; do
    sleep 2
done

exit 1
