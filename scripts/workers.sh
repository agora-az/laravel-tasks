#!/usr/bin/env bash

set -uo pipefail

start_general_queue() {
    DB_QUEUE_RETRY_AFTER=3700 php artisan queue:work database --queue=imports,default --sleep=1 --tries=1 --timeout=3600 --memory=512 --no-interaction &
    general_queue_pid=$!
}

start_cache_queue() {
    DB_VIEFUND_CACHE_QUEUE_RETRY_AFTER=300 php artisan queue:work database_viefund_cache --queue=viefund-cache --sleep=1 --tries=2 --timeout=240 --memory=512 --no-interaction &
    cache_queue_pid=$!
}

start_report_queue() {
    php artisan queue:work database_reports --queue=cashagch-reports --sleep=1 --tries=3 --timeout=600 --memory=512 --no-interaction &
    report_queue_pid=$!
}

start_scheduler() {
    php artisan schedule:work &
    scheduler_pid=$!
}

start_general_queue
start_cache_queue
start_report_queue
start_scheduler

cleanup() {
    for pid in "$general_queue_pid" "$cache_queue_pid" "$report_queue_pid" "$scheduler_pid"; do
        kill "$pid" 2>/dev/null || true
    done
    for pid in "$general_queue_pid" "$cache_queue_pid" "$report_queue_pid" "$scheduler_pid"; do
        wait "$pid" 2>/dev/null || true
    done
}

trap cleanup EXIT
trap 'exit 0' INT TERM

while true; do
    if ! kill -0 "$general_queue_pid" 2>/dev/null; then
        wait "$general_queue_pid" 2>/dev/null || true
        echo "General queue worker exited; restarting." >&2
        start_general_queue
    fi
    if ! kill -0 "$cache_queue_pid" 2>/dev/null; then
        wait "$cache_queue_pid" 2>/dev/null || true
        echo "VieFund cache worker exited; restarting." >&2
        start_cache_queue
    fi
    if ! kill -0 "$scheduler_pid" 2>/dev/null; then
        wait "$scheduler_pid" 2>/dev/null || true
        echo "Scheduler exited; restarting." >&2
        start_scheduler
    fi
    if ! kill -0 "$report_queue_pid" 2>/dev/null; then
        wait "$report_queue_pid" 2>/dev/null || true
        echo "CASHagch report worker exited; restarting." >&2
        start_report_queue
    fi
    sleep 2
done
