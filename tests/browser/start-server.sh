#!/usr/bin/env bash
set -euo pipefail

database="/tmp/yohan-compta-e2e.sqlite"
rm -f "${database}" "${database}-wal" "${database}-shm"
touch "${database}"

export APP_ENV=testing
export APP_DEBUG=false
export APP_URL=http://127.0.0.1:8010
export SESSION_SECURE_COOKIE=false
export DB_CONNECTION=sqlite
export DB_DATABASE="${database}"
export AUTH_EMAIL=e2e@example.com
export AUTH_PASSWORD_HASH
AUTH_PASSWORD_HASH="$(php -r 'echo password_hash("e2e-password", PASSWORD_BCRYPT);')"

php artisan migrate:fresh --force >/dev/null
exec php artisan serve --host=127.0.0.1 --port=8010
