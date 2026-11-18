#!/bin/bash
# Magento auto-install entrypoint
# Checks if Magento is installed; if not, runs setup:install

set -e

echo "[Magento] Starting setup check..."

# Wait for MySQL
echo "[Magento] Waiting for MySQL..."
for i in $(seq 1 60); do
    if mysqladmin ping -h "$MYSQL_HOST" --silent 2>/dev/null; then
        echo "[Magento] MySQL is ready."
        break
    fi
    sleep 3
done

# Check if Magento is installed
if [ -f /var/www/html/app/etc/env.php ] && php -r "
    \$env = include '/var/www/html/app/etc/env.php';
    exit(isset(\$env['install']['date']) ? 0 : 1);
" 2>/dev/null; then
    echo "[Magento] Already installed. Skipping setup."
else
    echo "[Magento] Running setup:install..."
    php -d memory_limit=-1 bin/magento setup:install \
        --db-host="$MYSQL_HOST" \
        --db-name="${MYSQL_DATABASE:-magento}" \
        --db-user="${MYSQL_USER:-magento}" \
        --db-password="${MYSQL_PASSWORD:-magento123}" \
        --admin-firstname="${ADMIN_FIRSTNAME:-Admin}" \
        --admin-lastname="${ADMIN_LASTNAME:-User}" \
        --admin-email="${ADMIN_EMAIL:-admin@pinelabs.com}" \
        --admin-user="${ADMIN_USER:-admin}" \
        --admin-password="${ADMIN_PASSWORD:-admin123}" \
        --base-url="${BASE_URL:-https://magento-demo-ipg-apacuat.creditpluspinelabs.com}" \
        --backend-frontname=admin \
        --use-rewrites=1 \
        --search-engine=elasticsearch7 \
        --elasticsearch-host="${ES_HOST:-magento-es-svc}" \
        --no-interaction

    echo "[Magento] Install complete. Enabling PinePG module..."
    php bin/magento module:enable Pinelabs_PinePGGateway
    php bin/magento deploy:mode:set developer
    php bin/magento cache:flush
    echo "[Magento] Setup complete."
fi

# Fix permissions
chown -R www-data:www-data /var/www/html/var /var/www/html/pub /var/www/html/generated /var/www/html/app/etc

echo "[Magento] Starting Apache..."
exec apache2-foreground