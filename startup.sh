#!/bin/bash

STARTUP_LOG_PATH=/home/site/wwwroot/storage/logs/startup.log
SUPERVISOR_DEPENDENCY_PATH=/tmp/python3-pkg-resources.deb
SUPERVISOR_DEPENDENCY_URL='https://snapshot.debian.org/archive/debian-security/20260831T000000Z/pool/updates/main/s/setuptools/python3-pkg-resources_52.0.0-4%2Bdeb11u2_all.deb'
SUPERVISOR_DEPENDENCY_SHA256='02a86666b1e705e27eaaca2a379c1009c37a0d1f1db2f75ba4a4bde123f83d52'

mkdir -p /home/site/wwwroot/storage/logs

log_startup() {
    printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1" >> "$STARTUP_LOG_PATH"
}

install_supervisor() {
    if command -v supervisord >/dev/null 2>&1; then
        return 0
    fi

    log_startup "Installing Supervisor from the configured Debian repositories..."

    if apt-get -o Acquire::Retries=3 update >> "$STARTUP_LOG_PATH" 2>&1 && \
       DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install -y supervisor >> "$STARTUP_LOG_PATH" 2>&1; then
        return 0
    fi

    # Debian 11 LTS ended on 2026-08-31. During its archive transition,
    # bullseye-security indexes can reference this dependency after its package
    # has been removed from the live mirror. Use Debian's immutable snapshot and
    # verify the published checksum before installing it.
    log_startup "Live Debian installation failed; installing the pinned Supervisor dependency from snapshot.debian.org..."

    curl -fL --retry 3 "$SUPERVISOR_DEPENDENCY_URL" \
        -o "$SUPERVISOR_DEPENDENCY_PATH" >> "$STARTUP_LOG_PATH" 2>&1 || return 1

    printf '%s  %s\n' "$SUPERVISOR_DEPENDENCY_SHA256" "$SUPERVISOR_DEPENDENCY_PATH" \
        | sha256sum -c - >> "$STARTUP_LOG_PATH" 2>&1 || return 1

    dpkg -i "$SUPERVISOR_DEPENDENCY_PATH" >> "$STARTUP_LOG_PATH" 2>&1 || return 1

    DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install -y supervisor \
        >> "$STARTUP_LOG_PATH" 2>&1 || return 1

    command -v supervisord >/dev/null 2>&1
}

log_startup "Launching startup script..."

# Copy custom nginx config
cp /home/site/wwwroot/default /etc/nginx/sites-available/default
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Apply PHP overrides (.user.ini)
if [ -f /home/site/wwwroot/.user.ini ]; then
    cp /home/site/wwwroot/.user.ini /usr/local/etc/php/conf.d/user.ini
fi

# Reload nginx to apply new limits
service nginx reload

# Laravel optimizations
cd /home/site/wwwroot

# Ensure Laravel storage directories exist
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/cache
mkdir -p storage/logs

# Fix permissions
chmod -R 755 storage bootstrap/cache

# Remove stale manifests
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# Run database migrations
bash /home/site/wwwroot/artisan-safe.sh migrate --force || true

# Cache Laravel config/routes/views
bash /home/site/wwwroot/artisan-safe.sh config:cache || true
bash /home/site/wwwroot/artisan-safe.sh route:cache || true
bash /home/site/wwwroot/artisan-safe.sh view:cache || true

# Install Supervisor
if ! install_supervisor; then
    log_startup "ERROR: Supervisor installation failed. See the preceding log output."
    exit 1
fi

# Ensure Supervisor directories exist
mkdir -p /etc/supervisor/conf.d

# Install a deterministic main configuration. The RPC and socket sections make
# supervisorctl available for production health checks and worker restarts.
cat << 'EOF' > /etc/supervisor/supervisord.conf
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0700

[supervisord]
nodaemon=true
logfile=/home/site/wwwroot/storage/logs/supervisord.log
logfile_maxbytes=10MB
logfile_backups=5
pidfile=/var/run/supervisord.pid
childlogdir=/home/site/wwwroot/storage/logs

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[include]
files = /etc/supervisor/conf.d/*.conf
EOF

# Install the Laravel scheduler configuration
cp /home/site/wwwroot/supervisor/laravel-scheduler.conf \
   /etc/supervisor/conf.d/laravel-scheduler.conf

# Install the Laravel queue worker configuration
cp /home/site/wwwroot/supervisor/laravel-queue.conf \
   /etc/supervisor/conf.d/laravel-queue.conf

# Azure starts nginx before invoking the custom startup command, but PHP-FPM
# still needs to be running before Supervisor takes over the foreground process.
if ! pgrep -x php-fpm >/dev/null 2>&1; then
    log_startup "Starting PHP-FPM..."

    if ! /usr/local/sbin/php-fpm -D >> "$STARTUP_LOG_PATH" 2>&1; then
        log_startup "ERROR: PHP-FPM failed to start."
        exit 1
    fi
fi

log_startup "Starting supervisord..."

# Start Supervisor in foreground mode
exec supervisord -c /etc/supervisor/supervisord.conf
