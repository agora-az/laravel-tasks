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

# Start supervisord (required by Azure)
supervisord -c /etc/supervisor/supervisord.conf
