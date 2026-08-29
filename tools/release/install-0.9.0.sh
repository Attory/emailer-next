#!/usr/bin/env bash

set -euo pipefail

version=0.9.0
expected_source='@SOURCE_COMMIT@'
bundle_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
archive="$bundle_directory/emailer-next-$version.zip"
public_key="$bundle_directory/emailer-next-$version-release.pub"
verifier="$bundle_directory/verify-package.php"
inspector="$bundle_directory/PackageInspector.php"
release_directory="/srv/emailer/releases/$version"
runtime_file=/etc/emailer/runtime.env

fail() {
    printf 'Installation failed: %s\n' "$1" >&2
    exit 1
}

prompt() {
    local variable_name="$1"
    local label="$2"
    local default_value="${3:-}"
    local value

    if [ -n "${!variable_name:-}" ]; then
        return
    fi
    if [ -n "$default_value" ]; then
        read -r -p "$label [$default_value]: " value
        printf -v "$variable_name" '%s' "${value:-$default_value}"
    else
        read -r -p "$label: " value
        printf -v "$variable_name" '%s' "$value"
    fi
}

prompt_secret() {
    local variable_name="$1"
    local label="$2"
    local value

    if [ -n "${!variable_name:-}" ]; then
        return
    fi
    read -r -s -p "$label: " value
    printf '\n'
    printf -v "$variable_name" '%s' "$value"
}

[ "$(id -u)" -eq 0 ] || fail 'run this script as root'
[ -f "$archive" ] || fail "missing $archive"
[ -f "$archive.manifest.json" ] || fail 'missing manifest sidecar'
[ -f "$archive.sha256" ] || fail 'missing checksum sidecar'
[ -f "$archive.spdx.json" ] || fail 'missing SBOM sidecar'
[ -f "$archive.sig" ] || fail 'missing signature sidecar'
[ -f "$public_key" ] || fail 'missing release public key'
[ -f "$verifier" ] || fail 'missing package verifier'
[ -f "$inspector" ] || fail 'missing package inspector'

for command in php unzip nginx systemctl openssl; do
    command -v "$command" >/dev/null 2>&1 || fail "required command is unavailable: $command"
done

php -r '
    $version = PHP_VERSION;
    if (version_compare($version, "8.4.0", "<") || version_compare($version, "8.6.0", ">=")) {
        fwrite(STDERR, "PHP 8.4 or 8.5 is required.\n");
        exit(1);
    }
    foreach (["curl", "dom", "gd", "intl", "mbstring", "openssl", "pdo_mysql", "sodium", "zip"] as $extension) {
        if (!extension_loaded($extension)) {
            fwrite(STDERR, "Missing PHP extension: ".$extension."\n");
            exit(1);
        }
    }
' || fail 'PHP runtime preflight failed'

php_minor="$(php -r 'printf("%d.%d", PHP_MAJOR_VERSION, PHP_MINOR_VERSION);')"
fpm_service="php$php_minor-fpm.service"
fpm_pool_directory="/etc/php/$php_minor/fpm/pool.d"
systemctl cat "$fpm_service" >/dev/null 2>&1 || fail "missing systemd service $fpm_service"
[ -d "$fpm_pool_directory" ] || fail "missing PHP-FPM pool directory $fpm_pool_directory"

prompt EMAILER_HOSTNAME 'HTTPS hostname'
case "$EMAILER_HOSTNAME" in
    ''|*[!A-Za-z0-9.-]*) fail 'hostname contains unsupported characters' ;;
esac
prompt_secret EMAILER_DATABASE_URL 'MariaDB DATABASE_URL (password must be percent-encoded)'
case "$EMAILER_DATABASE_URL" in
    mysql://*|mysql2://*) ;;
    *) fail 'DATABASE_URL must use mysql:// or mysql2://' ;;
esac
case "$EMAILER_DATABASE_URL" in
    *$'\n'*|*"'"*) fail 'DATABASE_URL contains unsupported EnvironmentFile characters' ;;
esac

prompt EMAILER_TLS_CERT 'TLS certificate path (leave blank to create a test certificate)' ''
prompt EMAILER_TLS_KEY 'TLS private-key path (leave blank to create a test key)' ''
if [ -z "${EMAILER_TLS_CERT:-}" ] && [ -z "${EMAILER_TLS_KEY:-}" ]; then
    install -d -o root -g root -m 0700 /etc/emailer/tls
    EMAILER_TLS_CERT=/etc/emailer/tls/acceptance.crt
    EMAILER_TLS_KEY=/etc/emailer/tls/acceptance.key
    openssl req -x509 -newkey rsa:3072 -nodes -days 30 \
        -subj "/CN=$EMAILER_HOSTNAME" \
        -addext "subjectAltName=DNS:$EMAILER_HOSTNAME" \
        -keyout "$EMAILER_TLS_KEY" -out "$EMAILER_TLS_CERT" >/dev/null 2>&1
    chmod 0600 "$EMAILER_TLS_KEY"
    chmod 0644 "$EMAILER_TLS_CERT"
elif [ -z "${EMAILER_TLS_CERT:-}" ] || [ -z "${EMAILER_TLS_KEY:-}" ]; then
    fail 'supply both TLS paths or leave both blank'
fi
[ -f "$EMAILER_TLS_CERT" ] || fail 'TLS certificate does not exist'
[ -f "$EMAILER_TLS_KEY" ] || fail 'TLS private key does not exist'

if ! getent group emailer >/dev/null; then
    groupadd --system emailer
fi
if ! id emailer >/dev/null 2>&1; then
    useradd --system --gid emailer --home-dir /var/lib/emailer --shell /usr/sbin/nologin emailer
fi

install -d -o root -g root -m 0755 /srv/emailer /srv/emailer/releases
install -d -o emailer -g emailer -m 0700 /var/lib/emailer/private /var/lib/emailer/keys /var/backups/emailer
install -d -o emailer -g emailer -m 0700 /var/lib/emailer/private/locks
install -d -o root -g emailer -m 0750 /etc/emailer
install -o root -g root -m 0644 "$public_key" /etc/emailer/release-signing.pub

(cd "$bundle_directory" && sha256sum -c "$(basename "$archive").sha256") >/dev/null \
    || fail 'archive checksum verification failed'
sudo_user=emailer
if ! command -v runuser >/dev/null 2>&1; then
    fail 'runuser is required for least-privilege package verification'
fi
runuser -u "$sudo_user" -- php "$verifier" \
    --archive="$archive" \
    --public-key=/etc/emailer/release-signing.pub \
    --current-version=0.1.0 \
    --expected-version="$version" \
    --expected-source="$expected_source" >/dev/null \
    || fail 'signed package verification failed'

[ ! -e "$release_directory" ] || fail "$release_directory already exists"
install -d -o root -g root -m 0755 "$release_directory"
unzip -q "$archive" -d "$release_directory"
chown -R root:root "$release_directory"
find "$release_directory" -type d -exec chmod 0555 {} +
find "$release_directory" -type f -exec chmod 0444 {} +

temporary_link="/srv/emailer/releases/.current-$version"
rm -f "$temporary_link"
ln -s "$release_directory" "$temporary_link"
mv -Tf "$temporary_link" /srv/emailer/releases/current

app_secret="$(php -r 'echo bin2hex(random_bytes(32));')"
trusted_host="$(printf '%s' "$EMAILER_HOSTNAME" | sed 's/\./\\./g')"
temporary_runtime="$(mktemp /etc/emailer/.runtime.env.XXXXXX)"
trap 'rm -f "${temporary_runtime:-}"' EXIT
chmod 0640 "$temporary_runtime"
chown root:emailer "$temporary_runtime"
{
    printf 'APP_ENV=prod\n'
    printf 'APP_DEBUG=0\n'
    printf 'APP_SECRET=%s\n' "$app_secret"
    printf 'DEFAULT_URI=https://%s\n' "$EMAILER_HOSTNAME"
    printf "DATABASE_URL='%s'\n" "$EMAILER_DATABASE_URL"
    printf 'EMAILER_PRIVATE_DIR=/var/lib/emailer/private\n'
    printf 'EMAILER_RUNTIME_DIR=/var/lib/emailer/private\n'
    printf 'EMAILER_SECRET_KEY_FILE=/var/lib/emailer/keys/master.key\n'
    printf 'EMAILER_RECOVERY_KEY_FILE=/var/lib/emailer/keys/recovery-key.enxkey\n'
    printf 'EMAILER_RELEASE_PUBLIC_KEY_FILE=/etc/emailer/release-signing.pub\n'
    printf 'EMAILER_RELEASES_DIR=/srv/emailer/releases\n'
    printf 'EMAILER_BACKUP_DESTINATION_DIR=\n'
    printf 'TRUSTED_PROXIES=127.0.0.1,::1\n'
    printf "TRUSTED_HOSTS='^%s$'\n" "$trusted_host"
    printf 'MAILER_DSN=smtp://127.0.0.1:1025\n'
    printf 'MESSENGER_TRANSPORT_DSN=doctrine://default\n'
    printf 'LOCK_DSN=flock:///var/lib/emailer/private/locks\n'
} >"$temporary_runtime"
mv -f "$temporary_runtime" "$runtime_file"
trap - EXIT

install -d -o root -g root -m 0755 "/etc/systemd/system/$fpm_service.d"
cat >"/etc/systemd/system/$fpm_service.d/emailer-environment.conf" <<EOF
[Service]
EnvironmentFile=$runtime_file
EOF
chmod 0644 "/etc/systemd/system/$fpm_service.d/emailer-environment.conf"

cat >"$fpm_pool_directory/emailer.conf" <<'EOF'
[emailer]
user = emailer
group = emailer
listen = /run/php/emailer.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
clear_env = no
chdir = /srv/emailer/releases/current
catch_workers_output = yes
EOF
chmod 0644 "$fpm_pool_directory/emailer.conf"

cat >/etc/nginx/sites-available/emailer-next.conf <<EOF
server {
    listen 443 ssl;
    server_name $EMAILER_HOSTNAME;
    root /srv/emailer/releases/current/public;
    index index.php;
    ssl_certificate $EMAILER_TLS_CERT;
    ssl_certificate_key $EMAILER_TLS_KEY;

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTPS on;
        fastcgi_param REQUEST_SCHEME https;
        fastcgi_pass unix:/run/php/emailer.sock;
    }
    location / { try_files \$uri /index.php\$is_args\$args; }
    location ~ \.php\$ { return 404; }
    location ~ (^|/)\. { return 404; }
    location ~* \.(?:env|log|sql|dump|backup|bak|key|pem|p12|pfx)\$ { return 404; }
}
EOF
chmod 0644 /etc/nginx/sites-available/emailer-next.conf
ln -sfn /etc/nginx/sites-available/emailer-next.conf /etc/nginx/sites-enabled/emailer-next.conf

for unit in emailer-worker.service emailer-scheduler.service emailer-scheduler.timer emailer-operations.service emailer-operations.timer; do
    install -o root -g root -m 0644 "$release_directory/deployment/$unit.example" "/etc/systemd/system/$unit"
done

cat >/usr/local/sbin/emailer-setup-proof <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { printf 'Run as root.\n' >&2; exit 1; }
read -r -p 'Paste the ownership code displayed at /setup: ' code
temporary="$(mktemp /srv/emailer/releases/current/.emailer-install-proof.XXXXXX)"
trap 'rm -f "$temporary"' EXIT
chmod 0400 "$temporary"
printf '%s\n' "$code" >"$temporary"
mv -f "$temporary" /srv/emailer/releases/current/.emailer-install-proof
trap - EXIT
printf 'Proof installed. Continue in the same browser session.\n'
EOF
chmod 0755 /usr/local/sbin/emailer-setup-proof

systemctl daemon-reload
systemctl restart "$fpm_service"
nginx -t
systemctl reload nginx
systemctl enable --now emailer-worker.service emailer-scheduler.timer emailer-operations.timer

printf '\nEmailer Next %s is installed.\n' "$version"
printf '1. Ensure a local Mailpit instance is listening on 127.0.0.1:1025.\n'
printf '2. Open https://%s/setup in a browser.\n' "$EMAILER_HOSTNAME"
printf '3. In this root terminal run: emailer-setup-proof\n'
printf '4. Paste the browser ownership code, then finish setup in that browser.\n'
printf 'The temporary self-signed certificate, when selected, is for acceptance testing only.\n'

