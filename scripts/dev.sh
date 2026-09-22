#!/usr/bin/env bash

set -euo pipefail

php artisan queue:listen --queue=default --sleep=1 --tries=1 --timeout=120 &
queue_listener_pid=$!

cleanup() {
    kill "$queue_listener_pid" 2>/dev/null || true
    wait "$queue_listener_pid" 2>/dev/null || true
}

trap cleanup EXIT INT TERM

php artisan serve "$@"
