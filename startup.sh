#!/bin/bash

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

# Remove stale manifests so production can run artisan without dev providers.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# Run database migrations
bash ./artisan-safe.sh migrate --force || true

# Cache Laravel config/routes/views (ignore errors if DB not ready)
bash ./artisan-safe.sh config:cache || true
bash ./artisan-safe.sh route:cache || true
bash ./artisan-safe.sh view:cache || true

# Install repo-managed Supervisor programs if present.
# This lets us version process definitions (for example, Laravel scheduler).
if [ -d /home/site/wwwroot/supervisor ]; then
    mkdir -p /etc/supervisor/conf.d
    cp /home/site/wwwroot/supervisor/*.conf /etc/supervisor/conf.d/ 2>/dev/null || true
fi

# Start process manager if available, otherwise start Laravel scheduler directly.
if command -v supervisord >/dev/null 2>&1; then
    echo "Starting supervisord"
    supervisord -c /etc/supervisor/supervisord.conf
else
    echo "supervisord not found; starting Laravel scheduler worker directly"

    # Avoid duplicate scheduler workers on container restarts.
    if ! pgrep -f "artisan schedule:work" >/dev/null 2>&1; then
        nohup /bin/bash -lc 'cd /home/site/wwwroot && php artisan schedule:work --no-interaction' \
            >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1 &
    fi
fi
