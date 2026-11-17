#!/bin/sh
set -e

PORT="${PORT:-80}"

# Railway injects PORT; make Apache listen on it.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/storage/uploads/assignments \
         /var/www/html/storage/uploads/submissions \
         /var/www/html/storage/uploads/payments \
         /var/www/html/storage/logs \
         /var/www/html/storage/sessions

chown -R www-data:www-data /var/www/html/storage
chmod -R u+rwX,g+rwX /var/www/html/storage

exec apache2-foreground
