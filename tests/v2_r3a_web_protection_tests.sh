#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
HTTPD="${HTTPD_BIN:-$(command -v httpd || true)}"
CURL="${CURL_BIN:-$(command -v curl || true)}"
PHP_CGI="${PHP_CGI_BIN:-$(command -v php-cgi || true)}"
if [[ -z "$HTTPD" || -z "$CURL" || -z "$PHP_CGI" ]]; then
    echo "BLOCKED: requires httpd, curl, and php-cgi for an effective local HTTP test" >&2
    exit 77
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/checador-r3a-http.XXXXXXXX")"
HTTPD_PID=""
cleanup() {
    if [[ -n "$HTTPD_PID" ]]; then kill "$HTTPD_PID" 2>/dev/null || true; wait "$HTTPD_PID" 2>/dev/null || true; fi
    rm -rf "$TMP"
}
trap cleanup EXIT INT TERM

DOC="$TMP/docroot"
mkdir -p "$DOC/tests/child" "$DOC/modelo"
cp -p "$ROOT/.htaccess" "$DOC/.htaccess"
cp -p "$ROOT/tests/.htaccess" "$DOC/tests/.htaccess"
cp -p "$ROOT/index.php" "$DOC/index.php"
cp -a "$ROOT/modelo/." "$DOC/modelo/"
printf '%s' '<?php echo "R3A_PHP_PROBE_CONTENT"; ?>' > "$DOC/tests/probe.php"
printf '%s' 'R3A_STATIC_PROBE_CONTENT' > "$DOC/tests/probe.txt"
printf '%s' 'R3A_CHILD_PROBE_CONTENT' > "$DOC/tests/child/probe.txt"
printf '%s' 'R3A_API_FRONT_CONTROLLER_OK' > "$DOC/route-probe.php"

PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s) exit(1); echo substr(strrchr(stream_socket_get_name($s,false),":"),1); fclose($s);')"
[[ "$PORT" =~ ^[0-9]+$ ]] || { echo "Could not select loopback port" >&2; exit 2; }

cat > "$TMP/httpd.conf" <<CONF
ServerRoot "/etc/httpd"
DefaultRuntimeDir "$TMP"
Mutex file:$TMP default
PidFile "$TMP/httpd.pid"
Listen 127.0.0.1:$PORT
ServerName 127.0.0.1
LoadModule mpm_event_module modules/mod_mpm_event.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule alias_module modules/mod_alias.so
LoadModule actions_module modules/mod_actions.so
LoadModule dir_module modules/mod_dir.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule setenvif_module modules/mod_setenvif.so
LoadModule env_module modules/mod_env.so
LoadModule cgid_module modules/mod_cgid.so
User $(id -un)
Group $(id -gn)
ErrorLog "$TMP/error.log"
LogLevel warn
TypesConfig /etc/mime.types
DocumentRoot "$DOC"
<Directory "$DOC">
    AllowOverride All
    Options +FollowSymLinks +ExecCGI -Indexes
    Require all granted
</Directory>
<Directory "$DOC/tests">
    AllowOverride All
    Require all granted
</Directory>
ScriptAlias /php-cgi "$PHP_CGI"
Alias /r3a-defense-tests "$DOC/tests"
Action application/x-httpd-php /php-cgi
AddHandler application/x-httpd-php .php
SetEnv REDIRECT_STATUS 200
DirectoryIndex index.php
CONF

"$HTTPD" -f "$TMP/httpd.conf" -t
"$HTTPD" -f "$TMP/httpd.conf" -DFOREGROUND &
HTTPD_PID=$!
for _ in $(seq 1 50); do
    if curl --silent --output /dev/null "http://127.0.0.1:$PORT/" 2>/dev/null; then break; fi
    if ! kill -0 "$HTTPD_PID" 2>/dev/null; then cat "$TMP/error.log" >&2; exit 2; fi
    sleep 0.1
done

assert_denied() {
    local path="$1" status body
    body="$TMP/body"
    status="$(curl --silent --show-error --output "$body" --write-out '%{http_code}' "http://127.0.0.1:$PORT$path")"
    [[ "$status" == 403 ]] || { echo "FAIL $path: expected 403, got $status" >&2; exit 1; }
    ! grep -q 'R3A_.*_PROBE_CONTENT' "$body" || { echo "FAIL $path: probe content exposed" >&2; exit 1; }
    echo "PASS denied $path (HTTP $status, no probe body)"
}

assert_denied /tests
assert_denied /tests/
assert_denied /tests/probe.php
assert_denied /tests/probe.txt
assert_denied /tests/child/probe.txt
assert_denied /TeStS/probe.txt
assert_denied /r3a-defense-tests/probe.php

status="$(curl --silent --show-error --output "$TMP/api-body" --write-out '%{http_code}' \
    --request PATCH "http://127.0.0.1:$PORT/api/v2/punch-events")"
[[ "$status" == 405 ]] || { echo "FAIL API route: expected HTTP 405, got $status" >&2; cat "$TMP/api-body" >&2; exit 1; }
grep -q 'METHOD_NOT_ALLOWED' "$TMP/api-body" || { echo "FAIL API route: response body changed" >&2; exit 1; }
echo "PASS API front-controller route /api/v2/punch-events (HTTP 405, method guard JSON)"

echo "R3A_HTTP_TEST_RESULT=PASS"
