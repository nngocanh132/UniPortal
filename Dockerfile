FROM php:8.2-apache

# PHP extensions required by the application.
# mod_php only works with the prefork MPM, so any other MPM symlink is removed
# here; loading two MPMs aborts Apache with "AH00534: More than one MPM loaded".
RUN docker-php-ext-install pdo_mysql \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* \
             /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork rewrite headers expires

# Production PHP configuration.
COPY docker/php.ini /usr/local/etc/php/conf.d/uniportal.ini

# Apache virtual host: public/ is the document root.
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY docker/entrypoint.sh /usr/local/bin/uniportal-entrypoint
RUN chmod +x /usr/local/bin/uniportal-entrypoint

WORKDIR /var/www/html

# Application code (storage/ is expected to be a mounted volume).
COPY . /var/www/html

RUN mkdir -p /var/www/html/storage/uploads/assignments \
             /var/www/html/storage/uploads/submissions \
             /var/www/html/storage/uploads/payments \
             /var/www/html/storage/logs \
             /var/www/html/storage/sessions \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R u+rwX,g+rwX /var/www/html/storage

# Fail the image build on a broken Apache config.
RUN apache2ctl -t

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV APP_ENV=production
ENV PORT=80

EXPOSE 80

ENTRYPOINT ["uniportal-entrypoint"]
CMD ["apache2-foreground"]
