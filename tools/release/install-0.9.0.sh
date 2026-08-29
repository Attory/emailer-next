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
mailpit_version=1.30.5
mailpit_service_installed=false
generated_database_credentials=false
temporary_database_client=''
temporary_mariadb_repo_setup=''
temporary_mailpit_directory=''
temporary_php_keyring=''
temporary_runtime=''

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

cleanup() {
    [ -z "$temporary_database_client" ] || rm -f "$temporary_database_client"
    [ -z "$temporary_mariadb_repo_setup" ] || rm -f "$temporary_mariadb_repo_setup"
    [ -z "$temporary_mailpit_directory" ] || rm -rf "$temporary_mailpit_directory"
    [ -z "$temporary_php_keyring" ] || rm -f "$temporary_php_keyring"
    [ -z "$temporary_runtime" ] || rm -f "$temporary_runtime"
}

apt_install() {
    if [ "${apt_updated:-false}" = false ]; then
        apt-get update
        apt_updated=true
    fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

apt_has_candidate() {
    apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ { found = ($2 != "(none)") } END { exit !found }'
}

add_php_repository() {
    case "${ID:-}" in
        ubuntu)
            apt_install ca-certificates software-properties-common
            add-apt-repository --yes ppa:ondrej/php >/dev/null
            ;;
        debian)
            apt_install ca-certificates curl lsb-release
            temporary_php_keyring="$(mktemp /tmp/debsuryorg-archive-keyring.XXXXXX.deb)"
            curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
                --output "$temporary_php_keyring" \
                https://packages.sury.org/debsuryorg-archive-keyring.deb
            dpkg -i "$temporary_php_keyring"
            rm -f "$temporary_php_keyring"
            temporary_php_keyring=''
            printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' \
                "$(lsb_release -sc)" >/etc/apt/sources.list.d/emailer-next-php.list
            ;;
        *) fail 'automatic PHP repository setup supports Debian and Ubuntu only' ;;
    esac
    apt-get update
    apt_updated=true
}

mariadb_candidate_is_supported() {
    apt-cache policy mariadb-server 2>/dev/null \
        | awk '/Candidate:/ { print $2 }' \
        | grep -Eq '(^|:)10\.11\.|(^|:)11\.8\.'
}

add_mariadb_repository() {
    temporary_mariadb_repo_setup="$(mktemp /tmp/mariadb_repo_setup.XXXXXX)"
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
        --output "$temporary_mariadb_repo_setup" \
        https://r.mariadb.com/downloads/mariadb_repo_setup
    printf '%s  %s\n' \
        7325ac7755809ca3312b446bd832542421699298f25b701f9a111bb42df0c7c1 \
        "$temporary_mariadb_repo_setup" | sha256sum -c - >/dev/null \
        || fail 'MariaDB repository setup download verification failed'
    chmod 0700 "$temporary_mariadb_repo_setup"
    "$temporary_mariadb_repo_setup" \
        --mariadb-server-version=mariadb-11.8 \
        --skip-maxscale \
        --skip-tools >/dev/null
    rm -f "$temporary_mariadb_repo_setup"
    temporary_mariadb_repo_setup=''
    apt_updated=true
}

ensure_mariadb_packages() {
    if [ "${apt_updated:-false}" = false ]; then
        apt-get update
        apt_updated=true
    fi
    if ! mariadb_candidate_is_supported; then
        add_mariadb_repository
    fi
    mariadb_candidate_is_supported \
        || fail 'MariaDB 10.11 or 11.8 remained unavailable after automatic repository setup'
    apt_install mariadb-client mariadb-server
}

install_base_packages() {
    local packages=()

    [ -f /etc/ssl/certs/ca-certificates.crt ] || packages+=(ca-certificates)
    command -v curl >/dev/null 2>&1 || packages+=(curl)
    command -v nginx >/dev/null 2>&1 || packages+=(nginx)
    command -v openssl >/dev/null 2>&1 || packages+=(openssl)
    command -v runuser >/dev/null 2>&1 || packages+=(util-linux)
    command -v ss >/dev/null 2>&1 || packages+=(iproute2)
    command -v tar >/dev/null 2>&1 || packages+=(tar)
    command -v unzip >/dev/null 2>&1 || packages+=(unzip)

    if [ "${#packages[@]}" -gt 0 ]; then
        printf 'Installing required server packages: %s\n' "${packages[*]}"
        apt_install "${packages[@]}"
    fi
}

select_or_install_php() {
    local minor
    local packages
    local missing
    local package
    local repository_needed

    for minor in 8.5 8.4; do
        if command -v "php$minor" >/dev/null 2>&1; then
            php_command="$(command -v "php$minor")"
            php_minor="$minor"
            packages=(
                "php$minor-cli" "php$minor-fpm" "php$minor-curl"
                "php$minor-gd" "php$minor-intl" "php$minor-mbstring"
                "php$minor-mysql" "php$minor-xml" "php$minor-zip"
            )
            missing=()
            for package in "${packages[@]}"; do
                dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null | grep -qx installed \
                    || missing+=("$package")
            done
            if [ "${#missing[@]}" -gt 0 ]; then
                repository_needed=false
                for package in "${missing[@]}"; do
                    apt_has_candidate "$package" || repository_needed=true
                done
                if [ "$repository_needed" = true ]; then
                    add_php_repository
                fi
                printf 'Installing missing PHP %s FPM components and extensions.\n' "$minor"
                apt_install "${missing[@]}"
            fi
            return
        fi
    done

    if [ "${apt_updated:-false}" = false ]; then
        apt-get update
        apt_updated=true
    fi
    if ! apt_has_candidate php8.5-cli && ! apt_has_candidate php8.4-cli; then
        add_php_repository
    fi
    for minor in 8.5 8.4; do
        if apt_has_candidate "php$minor-cli" && apt_has_candidate "php$minor-fpm"; then
            printf 'Installing PHP %s and required extensions.\n' "$minor"
            apt_install \
                "php$minor-cli" "php$minor-fpm" "php$minor-curl" \
                "php$minor-gd" "php$minor-intl" "php$minor-mbstring" \
                "php$minor-mysql" "php$minor-xml" "php$minor-zip"
            php_command="$(command -v "php$minor")"
            php_minor="$minor"
            return
        fi
    done

    fail 'PHP-FPM 8.4 or 8.5 remained unavailable after automatic repository setup'
}

verify_php_runtime() {
    # The embedded program is PHP, not shell.
    # shellcheck disable=SC2016
    "$php_command" -r '
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
}

create_local_database() {
    local database_exists
    local database_tables
    local database_password
    local database_user_suffix

    database_exists="$(mariadb --protocol=socket --batch --skip-column-names \
        -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='emailer_next'" 2>/dev/null)" \
        || fail 'cannot administer local MariaDB through its root socket; supply EMAILER_DATABASE_URL instead'
    if [ "$database_exists" = 1 ]; then
        database_tables="$(mariadb --protocol=socket --batch --skip-column-names \
            -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='emailer_next'")"
        [ "$database_tables" = 0 ] || fail 'local database emailer_next already contains tables'
    fi

    database_password="$("$php_command" -r 'echo bin2hex(random_bytes(24));')"
    database_user_suffix="$("$php_command" -r 'echo bin2hex(random_bytes(6));')"
    EMAILER_DATABASE_USER="emailer_$database_user_suffix"
    EMAILER_DATABASE_PASSWORD="$database_password"
    mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`emailer_next\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$EMAILER_DATABASE_USER'@'127.0.0.1' IDENTIFIED BY '$EMAILER_DATABASE_PASSWORD';
GRANT ALL PRIVILEGES ON \`emailer_next\`.* TO '$EMAILER_DATABASE_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    EMAILER_DATABASE_URL="mysql://$EMAILER_DATABASE_USER:$EMAILER_DATABASE_PASSWORD@127.0.0.1:3306/emailer_next"
    generated_database_credentials=true
}

verify_empty_database() {
    temporary_database_client="$(mktemp /tmp/emailer-mariadb.XXXXXX)"
    chmod 0600 "$temporary_database_client"
    # The embedded program is PHP, not shell.
    # shellcheck disable=SC2016
    database_name="$(EMAILER_DATABASE_URL="$EMAILER_DATABASE_URL" "$php_command" -r '
        $url = parse_url((string) getenv("EMAILER_DATABASE_URL"));
        if (!is_array($url) || !in_array($url["scheme"] ?? "", ["mysql", "mysql2"], true)) {
            fwrite(STDERR, "DATABASE_URL must use mysql:// or mysql2://\n"); exit(1);
        }
        $database = rawurldecode(ltrim((string) ($url["path"] ?? ""), "/"));
        $host = (string) ($url["host"] ?? "");
        $user = rawurldecode((string) ($url["user"] ?? ""));
        $password = rawurldecode((string) ($url["pass"] ?? ""));
        $port = (int) ($url["port"] ?? 3306);
        foreach ([$database, $host, $user, $password] as $value) {
            if ($value === "" || preg_match("/[\\x00-\\x1f\\x7f]/", $value)) {
                fwrite(STDERR, "DATABASE_URL contains a missing or unsafe field\n"); exit(1);
            }
        }
        if ($port < 1 || $port > 65535) { fwrite(STDERR, "Invalid database port\n"); exit(1); }
        $quote = static fn (string $value): string => chr(34).addcslashes($value, "\\\\\\\"").chr(34);
        $config = "[client]\nprotocol=TCP\nhost=".$quote($host)."\nport=".$port."\nuser=".$quote($user)."\npassword=".$quote($password)."\n";
        if (false === file_put_contents($argv[1], $config)) { exit(1); }
        echo $database;
    ' "$temporary_database_client")" || fail 'DATABASE_URL could not be parsed safely'

    database_version="$(mariadb --defaults-extra-file="$temporary_database_client" \
        --batch --skip-column-names "$database_name" -e 'SELECT VERSION()' 2>/dev/null)" \
        || fail 'MariaDB application database is unreachable with the supplied credentials'
    case "$database_version" in
        10.11.*-MariaDB*|11.8.*-MariaDB*) ;;
        *) fail "MariaDB 10.11 or 11.8 is required; server reported $database_version" ;;
    esac
    database_tables="$(mariadb --defaults-extra-file="$temporary_database_client" \
        --batch --skip-column-names "$database_name" \
        -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')"
    [ "$database_tables" = 0 ] || fail "MariaDB database $database_name is not empty"
    rm -f "$temporary_database_client"
    temporary_database_client=''
}

mailpit_is_local() {
    curl --fail --silent --show-error --max-time 3 http://127.0.0.1:8025/api/v1/info >/dev/null 2>&1 \
        && ss -H -ltn 'sport = :1025' | awk '$4 == "127.0.0.1:1025" { found=1 } $4 != "127.0.0.1:1025" { unsafe=1 } END { exit !(found && !unsafe) }'
}

install_mailpit() {
    local architecture
    local asset
    local checksum
    local download_url

    if mailpit_is_local; then
        printf 'Using the existing loopback-only Mailpit service on port 1025.\n'
        return
    fi
    if ss -H -ltn 'sport = :1025' | grep -q .; then
        fail 'port 1025 is already occupied by a service that is not loopback-only Mailpit'
    fi

    case "$(uname -m)" in
        x86_64|amd64)
            architecture=amd64
            checksum=b1499b23e6207d2728a0f154fd27370d0f608dd8ae1a63b734d089dc7f7a8d6f
            ;;
        aarch64|arm64)
            architecture=arm64
            checksum=c4c0a587770af1d5fb192e7b580aebfc03115c93da2af308a4b2e227c7d06cd2
            ;;
        *) fail 'automatic Mailpit installation supports only amd64 and arm64 Linux' ;;
    esac
    asset="mailpit-linux-$architecture.tar.gz"
    download_url="https://github.com/axllent/mailpit/releases/download/v$mailpit_version/$asset"
    temporary_mailpit_directory="$(mktemp -d /tmp/emailer-mailpit.XXXXXX)"
    printf 'Installing verified Mailpit %s for %s.\n' "$mailpit_version" "$architecture"
    curl --fail --location --proto '=https' --tlsv1.2 \
        --output "$temporary_mailpit_directory/$asset" "$download_url"
    printf '%s  %s\n' "$checksum" "$temporary_mailpit_directory/$asset" | sha256sum -c - >/dev/null \
        || fail 'Mailpit download checksum verification failed'
    tar -xzf "$temporary_mailpit_directory/$asset" -C "$temporary_mailpit_directory"
    [ -f "$temporary_mailpit_directory/mailpit" ] || fail 'Mailpit archive did not contain the expected binary'
    install -o root -g root -m 0755 "$temporary_mailpit_directory/mailpit" /usr/local/bin/mailpit
    if ! getent group mailpit >/dev/null; then groupadd --system mailpit; fi
    if ! id mailpit >/dev/null 2>&1; then
        useradd --system --gid mailpit --home-dir /var/lib/mailpit --shell /usr/sbin/nologin mailpit
    fi
    install -d -o mailpit -g mailpit -m 0700 /var/lib/mailpit
    cat >/etc/systemd/system/mailpit.service <<EOF
[Unit]
Description=Local Mailpit acceptance SMTP service
After=network.target

[Service]
Type=simple
User=mailpit
Group=mailpit
ExecStart=/usr/local/bin/mailpit --disable-version-check --database /var/lib/mailpit/mailpit.db --listen 127.0.0.1:8025 --smtp 127.0.0.1:1025
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/var/lib/mailpit

[Install]
WantedBy=multi-user.target
EOF
    chmod 0644 /etc/systemd/system/mailpit.service
    mailpit_service_installed=true
    rm -rf "$temporary_mailpit_directory"
    temporary_mailpit_directory=''
}

[ "$(id -u)" -eq 0 ] || fail 'run this script as root'
trap cleanup EXIT
[ -f "$archive" ] || fail "missing $archive"
[ -f "$archive.manifest.json" ] || fail 'missing manifest sidecar'
[ -f "$archive.sha256" ] || fail 'missing checksum sidecar'
[ -f "$archive.spdx.json" ] || fail 'missing SBOM sidecar'
[ -f "$archive.sig" ] || fail 'missing signature sidecar'
[ -f "$public_key" ] || fail 'missing release public key'
[ -f "$verifier" ] || fail 'missing package verifier'
[ -f "$inspector" ] || fail 'missing package inspector'

command -v apt-get >/dev/null 2>&1 || fail 'automatic provisioning requires Debian or Ubuntu with APT'
command -v systemctl >/dev/null 2>&1 || fail 'automatic provisioning requires systemd'
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
fi
case "${ID:-} ${ID_LIKE:-}" in
    *debian*|*ubuntu*) ;;
    *) fail 'automatic provisioning supports Debian and Ubuntu servers only' ;;
esac

apt_updated=false
install_base_packages
ensure_mariadb_packages
select_or_install_php
verify_php_runtime

for command in curl mariadb nginx openssl runuser ss systemctl tar unzip; do
    command -v "$command" >/dev/null 2>&1 || fail "required command is unavailable after package provisioning: $command"
done

fpm_service="php$php_minor-fpm.service"
fpm_pool_directory="/etc/php/$php_minor/fpm/pool.d"
systemctl cat "$fpm_service" >/dev/null 2>&1 || fail "missing systemd service $fpm_service"
[ -d "$fpm_pool_directory" ] || fail "missing PHP-FPM pool directory $fpm_pool_directory"
systemctl enable --now mariadb.service
install_mailpit
systemctl daemon-reload
if [ "$mailpit_service_installed" = true ]; then
    systemctl enable --now mailpit.service
    for attempt in 1 2 3 4 5; do
        : "$attempt"
        mailpit_is_local && break
        sleep 1
    done
fi
mailpit_is_local || fail 'Mailpit did not become healthy on loopback port 1025'

prompt EMAILER_HOSTNAME 'HTTPS hostname'
case "$EMAILER_HOSTNAME" in
    ''|*[!A-Za-z0-9.-]*) fail 'hostname contains unsupported characters' ;;
esac
prompt_secret EMAILER_DATABASE_URL 'Existing MariaDB DATABASE_URL, or press Enter to create a local empty database'
if [ -z "$EMAILER_DATABASE_URL" ]; then
    create_local_database
fi
case "$EMAILER_DATABASE_URL" in
    mysql://*|mysql2://*) ;;
    *) fail 'DATABASE_URL must use mysql:// or mysql2://' ;;
esac
case "$EMAILER_DATABASE_URL" in
    *$'\n'*|*"'"*) fail 'DATABASE_URL contains unsupported EnvironmentFile characters' ;;
esac
verify_empty_database

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
runuser -u "$sudo_user" -- "$php_command" "$verifier" \
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

app_secret="$("$php_command" -r 'echo bin2hex(random_bytes(32));')"
trusted_host="$(printf '%s' "$EMAILER_HOSTNAME" | sed 's/\./\\./g')"
temporary_runtime="$(mktemp /etc/emailer/.runtime.env.XXXXXX)"
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
temporary_runtime=''

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
    sed "s#/usr/bin/php#$php_command#g" "$release_directory/deployment/$unit.example" >"/etc/systemd/system/$unit"
    chown root:root "/etc/systemd/system/$unit"
    chmod 0644 "/etc/systemd/system/$unit"
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
systemctl enable --now "$fpm_service" nginx.service
systemctl restart "$fpm_service"
nginx -t
systemctl reload nginx
systemctl enable --now emailer-worker.service emailer-scheduler.timer emailer-operations.timer

printf '\nEmailer Next %s is installed.\n' "$version"
printf 'Nginx, PHP-FPM %s, MariaDB, and loopback-only Mailpit passed their checks.\n' "$php_minor"
if [ "$generated_database_credentials" = true ]; then
    printf '\nEnter these generated database values in the browser setup page:\n'
    printf '  Host: 127.0.0.1\n'
    printf '  Port: 3306\n'
    printf '  Database: emailer_next\n'
    printf '  Username: %s\n' "$EMAILER_DATABASE_USER"
    printf '  Password: %s\n' "$EMAILER_DATABASE_PASSWORD"
    printf 'Keep this protected terminal private; the password is also stored in %s.\n' "$runtime_file"
fi
printf '1. Open https://%s/setup in a browser.\n' "$EMAILER_HOSTNAME"
printf '2. In this root terminal run: emailer-setup-proof\n'
printf '3. Paste the browser ownership code, then finish setup in that browser.\n'
printf 'The temporary self-signed certificate, when selected, is for acceptance testing only.\n'
trap - EXIT
