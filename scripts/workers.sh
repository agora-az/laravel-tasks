#!/usr/bin/env bash

set -euo pipefail

DB_QUEUE_RETRY_AFTER=3700 php artisan queue:work database --queue=imports,default --sleep=1 --tries=1 --timeout=3600 --memory=512 --no-interaction &
general_queue_pid=$!

DB_VIEFUND_CACHE_QUEUE_RETRY_AFTER=300 php artisan queue:work database_viefund_cache --queue=viefund-cache --sleep=1 --tries=2 --timeout=240 --memory=512 --no-interaction &
cache_queue_pid=$!

php artisan schedule:work &
scheduler_pid=$!

cleanup() {
    kill "$general_queue_pid" 2>/dev/null || true
    kill "$cache_queue_pid" 2>/dev/null || true
    kill "$scheduler_pid" 2>/dev/null || true
    wait "$general_queue_pid" 2>/dev/null || true
    wait "$cache_queue_pid" 2>/dev/null || true
    wait "$scheduler_pid" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

while kill -0 "$general_queue_pid" 2>/dev/null \
    && kill -0 "$cache_queue_pid" 2>/dev/null \
    && kill -0 "$scheduler_pid" 2>/dev/null; do
    sleep 2
done

exit 1
