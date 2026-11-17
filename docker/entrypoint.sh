#!/bin/sh
set -e

PORT="${PORT:-80}"

# mod_php requires the prefork MPM. Strip the other MPM symlinks in case the
# runtime layer re-adds them (AH00534: More than one MPM loaded) and make sure
# prefork itself is present.
rm -f /etc/apache2/mods-enabled/mpm_event.* \
      /etc/apache2/mods-enabled/mpm_worker.*
ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Railway injects PORT; make Apache listen on it.
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/run/apache2 /var/lock/apache2

mkdir -p /var/www/html/storage/uploads/assignments \
         /var/www/html/storage/uploads/submissions \
         /var/www/html/storage/uploads/payments \
         /var/www/html/storage/logs \
         /var/www/html/storage/sessions

chown -R www-data:www-data /var/www/html/storage
chmod -R u+rwX,g+rwX /var/www/html/storage

# Fail fast with a readable config error instead of a crash loop.
apache2ctl -t

exec apache2-foreground
