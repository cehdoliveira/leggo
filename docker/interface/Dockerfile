FROM php:8.4-apache
COPY manager.conf /etc/apache2/sites-available/manager.conf
COPY site.conf /etc/apache2/sites-available/site.conf
RUN a2ensite manager.conf
RUN a2ensite site.conf
RUN apt-get update
RUN apt-get install -y apt-utils
RUN apt-get install -y libzip-dev
RUN apt-get install -y zlib1g-dev
RUN apt-get install -y git unzip

# Instalar librdkafka (dependência do rdkafka PHP)
RUN apt-get install -y librdkafka-dev

RUN apt-get update && apt-get install -y libgd-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd mbstring

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Instalar RdKafka extension
RUN pecl install rdkafka && docker-php-ext-enable rdkafka

# Instalar cron
RUN apt-get update && apt-get install -y cron && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

# Copiar php.ini customizado
COPY php.ini /usr/local/etc/php/php.ini

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Criar diretórios lib caso não existam
RUN mkdir -p /var/www/leggo/site/app/inc/lib \
    && mkdir -p /var/www/leggo/manager/app/inc/lib

# Definir permissões adequadas
RUN chown -R www-data:www-data /var/www/leggo

# Copiar crontab e configurar o script de entrypoint
COPY crontab /etc/cron.txt
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Definir o entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
