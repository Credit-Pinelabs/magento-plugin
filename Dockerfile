FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libicu-dev libpng-dev libjpeg-dev libxml2-dev libzip-dev libxslt-dev \
    libfreetype6-dev unzip git curl cron default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl gd pdo_mysql mysqli bcmath soap xsl zip opcache sockets \
    && a2enmod rewrite \
    && apt-get clean

COPY auth.json /tmp/auth.json

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN cd /var/www && rm -rf html && \
    COMPOSER_AUTH="$(cat /tmp/auth.json)" COMPOSER_POLICY_ADVISORIES_BLOCK=false composer create-project \
    --repository-url=https://repo.magento.com/ \
    magento/project-community-edition:2.4.7-p3 html --no-interaction

COPY Pinelabs/ /var/www/html/app/code/Pinelabs/

# Enable PinePG module and clear generated code — DB install will handle the rest
RUN cd /var/www/html && \
    php bin/magento module:enable Pinelabs_PinePGGateway || true && \
    rm -rf generated/code/* generated/metadata/* var/cache/* && \
    chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

WORKDIR /var/www/html