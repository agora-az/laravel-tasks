#!/bin/bash

# Copy your custom nginx site config
cp /home/site/wwwroot/default /etc/nginx/sites-available/default
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Reload nginx to apply the new config
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
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
