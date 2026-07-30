#!/bin/sh
set -eu

GIT_HTTP_BACKEND_BIN="${GIT_HTTP_BACKEND_BIN:-/usr/lib/git-core/git-http-backend}"
APP_ROOT="${APP_ROOT:-/var/www/html}"
AUTH_HELPER="${APP_ROOT}/bin/git-http-auth.php"
RESTORE_HELPER="${APP_ROOT}/bin/git-repository-restore.php"
PATH_INFO_VALUE="${PATH_INFO:-}"
QUERY_STRING_VALUE="${QUERY_STRING:-}"
PROJECT_ROOT="${GIT_PROJECT_ROOT:-${APP_ROOT}/git}"

if [ ! -x "$GIT_HTTP_BACKEND_BIN" ]; then
    echo "Status: 500 Internal Server Error"
    echo "Content-Type: text/plain"
    echo
    echo "git-http-backend is not available."
    exit 0
fi

repo_path_part=$(printf '%s' "$PATH_INFO_VALUE" | sed -n 's#^\(/\([^/][^/]*\)\.git\)\(/.*\)\?$#\1#p')
repo_slug=$(printf '%s' "$PATH_INFO_VALUE" | sed -n 's#^/\([^/][^/]*\)\.git\(/.*\)\?$#\1#p')

if [ -z "$repo_path_part" ] || [ -z "$repo_slug" ]; then
    echo "Status: 404 Not Found"
    echo "Content-Type: text/plain"
    echo
    echo "Repository not found."
    exit 0
fi

repo_dir="${PROJECT_ROOT}/${repo_slug}.git"
if [ ! -d "$repo_dir" ] && [ -f "$RESTORE_HELPER" ]; then
    php "$RESTORE_HELPER" "$repo_slug" >/dev/null 2>&1 || true
fi
if [ ! -d "$repo_dir" ]; then
    echo "Status: 404 Not Found"
    echo "Content-Type: text/plain"
    echo
    echo "Repository not found."
    exit 0
fi

require_push_auth=0
case "$QUERY_STRING_VALUE" in
    *service=git-receive-pack*) require_push_auth=1 ;;
esac
case "$PATH_INFO_VALUE" in
    */git-receive-pack) require_push_auth=1 ;;
esac

if [ "$require_push_auth" = "1" ]; then
    auth_header="${HTTP_AUTHORIZATION:-}"
    if [ -z "$auth_header" ]; then
        echo "Status: 401 Unauthorized"
        echo 'WWW-Authenticate: Basic realm="ERNI PMS Git Push"'
        echo "Content-Type: text/plain"
        echo
        echo "Use your repository token as the HTTP password for Git push."
        exit 0
    fi

    if [ ! -f "$AUTH_HELPER" ]; then
        echo "Status: 500 Internal Server Error"
        echo "Content-Type: text/plain"
        echo
        echo "Git authentication helper is missing."
        exit 0
    fi

    if ! remote_user=$(php "$AUTH_HELPER" "$PATH_INFO_VALUE" "$auth_header"); then
        echo "Status: 401 Unauthorized"
        echo 'WWW-Authenticate: Basic realm="ERNI PMS Git Push"'
        echo "Content-Type: text/plain"
        echo
        echo "Invalid Git credentials."
        exit 0
    fi

    export REMOTE_USER="$remote_user"
    export AUTH_TYPE="Basic"

    git --git-dir="$repo_dir" config http.receivepack true >/dev/null 2>&1 || true
fi

git --git-dir="$repo_dir" config http.uploadpack true >/dev/null 2>&1 || true

exec "$GIT_HTTP_BACKEND_BIN"
