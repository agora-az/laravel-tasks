#!/bin/bash

echo "Launching startup script..." >> /home/site/wwwroot/storage/logs/startup.log

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
echo "Installing Supervisor..." >> /home/site/wwwroot/storage/logs/startup.log
apt-get update
apt-get install -y supervisor

# Ensure Supervisor directories exist
mkdir -p /etc/supervisor/conf.d

# Ensure Supervisor has a main config file
if [ ! -f /etc/supervisor/supervisord.conf ]; then
cat << 'EOF' > /etc/supervisor/supervisord.conf
[supervisord]
nodaemon=true
logfile=/home/site/wwwroot/storage/logs/supervisord.log
pidfile=/home/site/wwwroot/storage/logs/supervisord.pid

[include]
files = /etc/supervisor/conf.d/*.conf
EOF
fi

echo "Starting supervisord..." >> /home/site/wwwroot/storage/logs/startup.log

# Start Supervisor in foreground mode
exec supervisord -c /etc/supervisor/supervisord.conf
