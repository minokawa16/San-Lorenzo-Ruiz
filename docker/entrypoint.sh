#!/bin/sh
set -eu

port="${PORT:-8080}"
data_root="${TUGON_DATA_DIR:-${RAILWAY_VOLUME_MOUNT_PATH:-/var/www/tugon-data}}"

sed -ri "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

# Some Debian package combinations compile an MPM into Apache while also
# leaving an MPM load file enabled. Apache refuses to start when both exist.
compiled_mpm="$(apache2 -l 2>/dev/null | grep -E '(prefork|worker|event)\.c' || true)"
if [ -n "${compiled_mpm}" ]; then
    rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
else
    rm -f \
        /etc/apache2/mods-enabled/mpm_event.load \
        /etc/apache2/mods-enabled/mpm_event.conf \
        /etc/apache2/mods-enabled/mpm_worker.load \
        /etc/apache2/mods-enabled/mpm_worker.conf
    ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
fi

mkdir -p "${data_root}" "${data_root}/sessions"

for directory in uploads storage backups cache logs; do
    destination="${data_root}/${directory}"
    seed="/opt/tugon-seed/${directory}"
    web_path="/var/www/html/${directory}"

    mkdir -p "${destination}"
    if [ -d "${seed}" ]; then
        cp -an "${seed}/." "${destination}/" 2>/dev/null || true
    fi
    rm -rf "${web_path}"
    ln -s "${destination}" "${web_path}"
done

chown -R www-data:www-data "${data_root}"
chmod 0750 "${data_root}"
find "${data_root}" -type d -exec chmod 0750 {} \;
find "${data_root}" -type f -exec chmod 0640 {} \;
printf '%s\n' \
    "session.save_path=\"${data_root}/sessions\"" \
    'session.cookie_secure=1' \
    'session.cookie_httponly=1' \
    'session.cookie_samesite="Lax"' \
    'session.use_strict_mode=1' \
    > /usr/local/etc/php/conf.d/tugon-session.ini

if [ "${APP_ENV:-local}" = "production" ]; then
    php /var/www/html/database/migrate.php up || true
    php /var/www/html/database/production-readiness.php --startup || true
fi

# Small testing deployments may run the queue worker beside Apache when the
# hosting plan cannot provision a second service. Production can keep this
# disabled and run the same worker command as a dedicated singleton service.
if [ "${TUGON_RUN_EMBEDDED_WORKER:-false}" = "true" ] && [ "${1:-}" != "tugon-worker" ]; then
    gosu www-data tugon-worker &
fi

if [ "$(id -u)" = "0" ] && [ "${1:-}" = "tugon-worker" ]; then
    exec gosu www-data "$@"
fi

exec "$@"
