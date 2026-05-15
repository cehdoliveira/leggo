#!/bin/bash
set -e

ENABLE_CRON="${ENABLE_CRON:-false}"

echo "Executando composer install nas pastas lib..."

# Instalar dependências do composer para site
if [ -f "/var/www/leggo/site/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do site..."
    cd /var/www/leggo/site/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ -f "/var/www/leggo/manager/app/inc/lib/composer.json" ]; then
    echo "Instalando dependências do manager..."
    cd /var/www/leggo/manager/app/inc/lib
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "Composer install concluído!"

# Garantir permissão de escrita nos diretórios de upload
chmod 777 /var/www/leggo/manager/public_html/assets/upload/ 2>/dev/null || true
chmod 777 /var/www/leggo/site/public_html/assets/upload/ 2>/dev/null || true

# Instalar crontab e iniciar cron apenas no container app
if [ "$ENABLE_CRON" = "true" ]; then
    if [ -f "/etc/cron.txt" ]; then
        echo "Instalando crontab..."
        crontab /etc/cron.txt || true
    fi

    echo "Iniciando cron..."
    service cron start || cron || true
else
    echo "Cron desabilitado para este container."
fi

if [ "$#" -gt 0 ]; then
    echo "Executando comando customizado: $*"
    exec "$@"
fi

# Iniciar PHP-FPM em background
echo "Iniciando PHP-FPM..."
php-fpm -D

# Iniciar Kafka Email Worker em background
echo "Iniciando Kafka Email Worker..."
php /var/www/leggo/manager/cgi-bin/kafka_email_worker.php >> /var/log/kafka_email_worker_manager.log 2>&1 &
MANAGER_PID=$!
php /var/www/leggo/site/cgi-bin/kafka_email_worker.php >> /var/log/kafka_email_worker_site.log 2>&1 &
SITE_PID=$!
echo "Kafka Email Workers iniciados (manager PID $MANAGER_PID, site PID $SITE_PID)"

# Iniciar Nginx no foreground
echo "Iniciando Nginx..."
exec nginx -g "daemon off;"
