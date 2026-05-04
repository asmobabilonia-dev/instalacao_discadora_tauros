#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo "Execute como root: sudo bash install_discadora_tauros.sh"
  exit 1
fi

APP_SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/app"
if [[ ! -f "$APP_SOURCE_DIR/index.php" ]]; then
  echo "Nao encontrei a pasta app/ com a discadora."
  exit 1
fi

bold() { printf "\033[1m%s\033[0m\n" "$*"; }
info() { printf "\033[1;32m==>\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33mATENCAO:\033[0m %s\n" "$*"; }

ask() {
  local var="$1"
  local label="$2"
  local default="${3:-}"
  local secret="${4:-0}"
  local value=""
  if [[ "$secret" == "1" ]]; then
    read -r -s -p "$label${default:+ [$default]}: " value
    echo
  else
    read -r -p "$label${default:+ [$default]}: " value
  fi
  value="${value:-$default}"
  printf -v "$var" '%s' "$value"
}

ask_required() {
  local var="$1"
  local label="$2"
  local default="${3:-}"
  local secret="${4:-0}"
  while true; do
    ask "$var" "$label" "$default" "$secret"
    if [[ -n "${!var}" ]]; then
      break
    fi
    echo "Esse campo e obrigatorio."
  done
}

random_password() {
  openssl rand -base64 32 | tr -d '=+/' | cut -c1-24
}

php_escape() {
  printf "%s" "$1" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g"
}

sql_escape() {
  printf "%s" "$1" | sed -e "s/'/''/g"
}

line() {
  printf '%*s\n' "${COLUMNS:-80}" '' | tr ' ' '-'
}

bold "Instalador Tauros Discadora para VPS Linux"
line
echo "Este instalador vai configurar Nginx, PHP, MariaDB, SSL e a aplicacao."
echo "Tenha o dominio ja apontado para esta VPS antes de emitir SSL."
line

DEFAULT_DOMAIN="$(hostname -f 2>/dev/null || hostname)"
ask_required DOMAIN "Dominio do painel" "$DEFAULT_DOMAIN"
ask_required LETSENCRYPT_EMAIL "Email para certificado SSL/Let's Encrypt" ""
ask_required ADMIN_NAME "Nome do primeiro admin" "Administrador"
ask_required ADMIN_EMAIL "Email/login do primeiro admin" "admin@$DOMAIN"
ask_required ADMIN_PASSWORD "Senha do primeiro admin" "" "1"

ask APP_NAME "Nome do sistema" "Tauros Discadora"
ask INSTALL_DIR "Diretorio de instalacao" "/var/www/tauros-discadora"
ask DB_NAME "Nome do banco MariaDB" "tauros_discadora"
ask DB_USER "Usuario do banco" "tauros_user"
DB_PASSWORD_DEFAULT="$(random_password)"
ask DB_PASSWORD "Senha do usuario do banco" "$DB_PASSWORD_DEFAULT" "1"
ask TIMEZONE "Timezone" "America/Sao_Paulo"

ask AsteriskHost "IP/host do servidor Asterisk (pode deixar vazio e configurar depois)" ""
ask AmiHost "AMI host (normalmente IP do Asterisk)" "$AsteriskHost"
ask AmiPort "AMI porta" "5038"
ask AmiUser "AMI usuario" "discadora_panel"
ask AmiSecret "AMI senha (pode deixar vazio e configurar depois)" "" "1"
ask MagnusHost "IP/host do MagnusBilling (pode deixar vazio e configurar depois)" ""
ask AppPublicUrl "URL publica do painel" "https://$DOMAIN"

ask ENABLE_SSL "Emitir SSL com Certbot agora? (s/n)" "s"
ask INSTALL_FIREWALL "Configurar UFW liberando HTTP/HTTPS/SSH? (s/n)" "s"

if ! [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "Dominio invalido: use apenas letras, numeros, pontos e hifens."
  exit 1
fi
if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Nome do banco invalido: use apenas letras, numeros e _."
  exit 1
fi
if ! [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Usuario do banco invalido: use apenas letras, numeros e _."
  exit 1
fi

APP_NAME_PHP="$(php_escape "$APP_NAME")"
TIMEZONE_PHP="$(php_escape "$TIMEZONE")"
DB_NAME_PHP="$(php_escape "$DB_NAME")"
DB_USER_PHP="$(php_escape "$DB_USER")"
DB_PASSWORD_PHP="$(php_escape "$DB_PASSWORD")"
ADMIN_NAME_PHP="$(php_escape "$ADMIN_NAME")"
ADMIN_EMAIL_PHP="$(php_escape "$ADMIN_EMAIL")"
ADMIN_PASSWORD_PHP="$(php_escape "$ADMIN_PASSWORD")"
APP_PUBLIC_URL_PHP="$(php_escape "$AppPublicUrl")"
AsteriskHost_PHP="$(php_escape "$AsteriskHost")"
AmiHost_PHP="$(php_escape "$AmiHost")"
AmiPort_PHP="$(php_escape "$AmiPort")"
AmiUser_PHP="$(php_escape "$AmiUser")"
AmiSecret_PHP="$(php_escape "$AmiSecret")"
MagnusHost_PHP="$(php_escape "$MagnusHost")"
DB_PASSWORD_SQL="$(sql_escape "$DB_PASSWORD")"

info "Instalando pacotes"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y curl ca-certificates unzip git openssl rsync lsb-release apt-transport-https gnupg2

PHP_PREFIX="php"
OS_ID=""
OS_VERSION=""
if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  source /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION="${VERSION_ID:-}"
fi

if [[ "$OS_ID" == "debian" && "${OS_VERSION%%.*}" -le 11 ]]; then
  info "Debian ${OS_VERSION} detectado. Ativando repositorio Sury para PHP 8.2"
  rm -f /usr/share/keyrings/sury-php.gpg
  curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
  echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
  apt-get update
  PHP_PREFIX="php8.2"
fi

apt-get install -y nginx mariadb-server \
  "${PHP_PREFIX}-fpm" "${PHP_PREFIX}-cli" "${PHP_PREFIX}-mysql" "${PHP_PREFIX}-curl" \
  "${PHP_PREFIX}-mbstring" "${PHP_PREFIX}-xml" "${PHP_PREFIX}-zip" "${PHP_PREFIX}-gd" \
  "${PHP_PREFIX}-intl" "${PHP_PREFIX}-bcmath" "${PHP_PREFIX}-soap" "${PHP_PREFIX}-sqlite3"

if [[ "$ENABLE_SSL" =~ ^[sS]$ ]]; then
  apt-get install -y certbot python3-certbot-nginx
fi
if [[ "$INSTALL_FIREWALL" =~ ^[sS]$ ]]; then
  apt-get install -y ufw
fi

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$PHP_FPM_SOCK" ]]; then
  PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' | head -n1)"
fi
if [[ -z "$PHP_FPM_SOCK" ]]; then
  echo "Nao encontrei socket do PHP-FPM."
  exit 1
fi

info "Preparando banco MariaDB"
systemctl enable --now mariadb
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_SQL}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

info "Copiando aplicacao para $INSTALL_DIR"
mkdir -p "$INSTALL_DIR"
rsync -a --delete \
  --exclude 'config/config.php' \
  --exclude 'data/*' \
  --exclude 'uploads/*' \
  "$APP_SOURCE_DIR/" "$INSTALL_DIR/"
mkdir -p "$INSTALL_DIR/config" "$INSTALL_DIR/data" "$INSTALL_DIR/uploads/logos" "$INSTALL_DIR/uploads/audios"

info "Gerando config/config.php"
cat > "$INSTALL_DIR/config/config.php" <<PHP
<?php

return [
    'app_name' => '${APP_NAME_PHP}',
    'timezone' => '${TIMEZONE_PHP}',
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => '${DB_NAME_PHP}',
        'user' => '${DB_USER_PHP}',
        'password' => '${DB_PASSWORD_PHP}',
        'charset' => 'utf8mb4',
        'sqlite_path' => __DIR__ . '/../data/app.sqlite',
    ],
    'default_admin' => [
        'name' => '${ADMIN_NAME_PHP}',
        'email' => '${ADMIN_EMAIL_PHP}',
        'password' => '${ADMIN_PASSWORD_PHP}',
    ],
];
PHP

info "Aplicando permissoes"
chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 0755 {} \;
find "$INSTALL_DIR" -type f -exec chmod 0644 {} \;
chmod 0750 "$INSTALL_DIR/config" "$INSTALL_DIR/data" "$INSTALL_DIR/uploads"
chmod 0640 "$INSTALL_DIR/config/config.php"

info "Rodando migrations e configuracoes iniciais"
sudo -u www-data php -r "
require '${INSTALL_DIR}/src/bootstrap.php';
\$db = Database::conn();
\$hash = password_hash('${ADMIN_PASSWORD_PHP}', PASSWORD_DEFAULT);
\$stmt = \$db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
\$stmt->execute(['${ADMIN_EMAIL_PHP}']);
\$id = (int)(\$stmt->fetchColumn() ?: 0);
if (\$id > 0) {
    \$up = \$db->prepare(\"UPDATE users SET name=?, password_hash=?, role='admin', active=1 WHERE id=?\");
    \$up->execute(['${ADMIN_NAME_PHP}', \$hash, \$id]);
} else {
    \$ins = \$db->prepare(\"INSERT INTO users(name, email, password_hash, role, active) VALUES(?,?,?,?,1)\");
    \$ins->execute(['${ADMIN_NAME_PHP}', '${ADMIN_EMAIL_PHP}', \$hash, 'admin']);
}
\$settings = [
    'app_public_url' => '${APP_PUBLIC_URL_PHP}',
    'brand_name' => '${APP_NAME_PHP}',
    'ami_host' => '${AmiHost_PHP}',
    'ami_port' => '${AmiPort_PHP}',
    'ami_user' => '${AmiUser_PHP}',
    'ami_secret' => '${AmiSecret_PHP}',
    'asterisk_sync_host' => '${AsteriskHost_PHP}',
    'asterisk_sync_external_ip' => '${AsteriskHost_PHP}',
    'magnus_sync_host' => '${MagnusHost_PHP}',
];
foreach (\$settings as \$key => \$value) {
    \$sql = Database::isMysql() ? 'INSERT INTO settings(\`key\`, value) VALUES(?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)' : 'INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)';
    \$db->prepare(\$sql)->execute([\$key, \$value]);
}
echo 'OK'.PHP_EOL;
"

info "Configurando Nginx"
cat > "/etc/nginx/sites-available/tauros-discadora" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${INSTALL_DIR};
    index index.php index.html;

    client_max_body_size 100M;

    access_log /var/log/nginx/tauros-discadora.access.log;
    error_log /var/log/nginx/tauros-discadora.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~* /(config|data)/ {
        deny all;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX

ln -sfn /etc/nginx/sites-available/tauros-discadora /etc/nginx/sites-enabled/tauros-discadora
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now php${PHP_VERSION}-fpm || true
systemctl reload nginx

if [[ "$INSTALL_FIREWALL" =~ ^[sS]$ ]]; then
  info "Configurando firewall"
  ufw allow OpenSSH || ufw allow 22/tcp
  ufw allow 80/tcp
  ufw allow 443/tcp
  ufw --force enable
fi

if [[ "$ENABLE_SSL" =~ ^[sS]$ ]]; then
  info "Emitindo certificado SSL"
  warn "Se o Cloudflare estiver com proxy laranja e falhar, deixe DNS Only temporariamente e rode: certbot --nginx -d ${DOMAIN}"
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" --redirect || {
    warn "SSL nao foi emitido automaticamente. O painel esta no HTTP; rode o certbot manualmente depois."
  }
fi

info "Teste local da aplicacao"
php -l "$INSTALL_DIR/index.php"

line
bold "Instalacao concluida"
echo "URL: ${AppPublicUrl}"
echo "Admin: ${ADMIN_EMAIL}"
echo "Senha: a senha informada no instalador"
echo
echo "Arquivos importantes:"
echo "- Aplicacao: ${INSTALL_DIR}"
echo "- Config: ${INSTALL_DIR}/config/config.php"
echo "- Nginx: /etc/nginx/sites-available/tauros-discadora"
echo
echo "Proximos passos:"
echo "1. Acesse ${AppPublicUrl}"
echo "2. Entre com o admin criado"
echo "3. Abra Configuracoes e complete Asterisk, AMI e Magnus se deixou algum campo vazio"
echo "4. Sincronize ramais, filas e URAs"
line
