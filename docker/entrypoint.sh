#!/bin/sh
set -eu

data_dir=/data
database_path="${DB_DATABASE:-$data_dir/database.sqlite}"
files_root="${LOCAL_FILESYSTEM_ROOT:-$data_dir/files}"

if [ "${1:-}" = "apache2-foreground" ]; then
    if [ -z "${AUTH_EMAIL:-}" ]; then
        echo "AUTH_EMAIL doit être renseigné dans .env.docker." >&2
        exit 1
    fi
    if [ -z "${AUTH_PASSWORD_HASH:-}" ] || ! php -r '$info = password_get_info(getenv("AUTH_PASSWORD_HASH")); exit(($info["algoName"] ?? "unknown") === "bcrypt" ? 0 : 1);'; then
        echo "AUTH_PASSWORD_HASH est absent ou invalide. Générez-le avec php artisan auth:password-hash." >&2
        exit 1
    fi
fi

mkdir -p "$data_dir" "$files_root" \
    storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    key_file="$data_dir/app.key"
    if [ ! -s "$key_file" ]; then
        umask 077
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
    fi
    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

mkdir -p "$(dirname "$database_path")"
touch "$database_path"

chown -R www-data:www-data "$data_dir" storage bootstrap/cache

php artisan migrate --force --no-interaction

# Les migrations peuvent créer les fichiers SQLite WAL/SHM en root.
chown -R www-data:www-data "$data_dir"

exec "$@"
