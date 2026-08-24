#!/bin/sh
set -eu

interval="${TUGON_WORKER_INTERVAL_SECONDS:-60}"
case "${interval}" in
    ''|*[!0-9]*) echo "TUGON_WORKER_INTERVAL_SECONDS must be a positive integer." >&2; exit 2 ;;
esac
if [ "${interval}" -lt 15 ]; then
    echo "Worker interval must be at least 15 seconds." >&2
    exit 2
fi

cd /var/www/html
echo "TUGON background worker started (interval=${interval}s)."
while true; do
    php database/run-notification-deliveries.php 50 || true
    php database/run-reservation-reminders.php || true
    php database/run-announcement-lifecycle.php || true
    sleep "${interval}"
done
