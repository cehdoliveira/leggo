#!/bin/bash
# Verificacao completa: PHPStan (host) + PHPUnit (Docker) para manager e site.
set -e

( cd site && php app/inc/lib/vendor/bin/phpstan analyse )
( cd manager && php app/inc/lib/vendor/bin/phpstan analyse )

for DIR in site manager; do
    echo "-> PHPUnit ($DIR)..."
    docker exec leggo php -d memory_limit=512M \
        "/var/www/leggo/$DIR/app/inc/lib/vendor/bin/phpunit" \
        -c "/var/www/leggo/$DIR/phpunit.xml"
done

echo "Verificacao completa OK"
