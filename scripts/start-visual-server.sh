#!/bin/sh
set -eu

project_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
runtime_directory="$(mktemp -d "${TMPDIR:-/tmp}/deskhelper-visual.XXXXXX")"
server_pid=""
cleanup() {
    trap - EXIT
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$runtime_directory"
}
trap cleanup EXIT
trap 'exit 0' HUP INT TERM

export APP_ENV=test
export APP_BASE_PATH=/deskhelper
export APP_DB_PATH="$runtime_directory/deskhelper.sqlite"
export ADMIN_USER=admin
export ADMIN_PASS=admin123
export DESKHELPER_ADMIN_EMAIL=admin@deskhelper.local
mkdir "$runtime_directory/sessions"
php "$project_root/scripts/seed-visual.php"

php -d "session.save_path=$runtime_directory/sessions" \
    -S "127.0.0.1:${VISUAL_SERVER_PORT:-8086}" \
    "$project_root/scripts/visual-router.php" &
server_pid=$!
wait "$server_pid"
