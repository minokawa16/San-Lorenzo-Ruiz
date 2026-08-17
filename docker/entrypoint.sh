#!/bin/sh
set -eu

port="${PORT:-8080}"
data_root="${TUGON_DATA_DIR:-${RAILWAY_VOLUME_MOUNT_PATH:-/var/www/tugon-data}}"

sed -ri "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

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
printf 'session.save_path="%s"\n' "${data_root}/sessions" > /usr/local/etc/php/conf.d/tugon-session.ini

exec "$@"
