#!/bin/sh
# Bind Apache to the port the platform asks for.
#
# Fly.io, Render and Railway all inject $PORT (Render and Railway pick it, Fly reads
# internal_port from fly.toml). Both sed expressions are idempotent, so restarting the same
# container is safe.
set -e

PORT="${PORT:-8080}"
case "$PORT" in
    ''|*[!0-9]*) echo "off-lab: PORT must be a number, got '$PORT'" >&2; exit 1 ;;
esac

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "off-lab: listening on ${PORT}"
exec "$@"
