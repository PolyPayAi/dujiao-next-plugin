FROM php:8.3-apache
RUN docker-php-ext-install bcmath \
    && php -r 'foreach (["curl","json","bcmath","sodium"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing extension: $e\n"); exit(1); } }'
WORKDIR /opt/polypay
COPY bootstrap.php ./
COPY src/ ./src/
COPY public/ ./public/
COPY vendor/ ./vendor/
COPY bin/ ./bin/
RUN sed -i 's|/var/www/html|/opt/polypay/public|g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /opt/polypay/public>\nRequire all granted\nAllowOverride None\nOptions -Indexes\n</Directory>\n' > /etc/apache2/conf-available/polypay.conf \
    && a2enconf polypay \
    && install -d -m 0700 -o www-data -g www-data /var/lib/polypay-dujiao-next
ENV POLYPAY_BRIDGE_CONFIG=/run/secrets/bridge.php
VOLUME /var/lib/polypay-dujiao-next
