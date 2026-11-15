#!/usr/bin/env bash
#
# iskra.sh — автоустановка и автообновление сайта «Искра»
#
#   Первый запуск:   sudo bash iskra.sh install
#   Обновление:      sudo bash iskra.sh update     (запускается таймером сам)
#
# Скрипт сам себя обновляет: если в репозитории появилась новая версия
# iskraAI/iskra.sh — он перезапускается новой версией.
#
# Исходники сайта берутся из  iskraAI/site/  в этом репозитории
# и раскладываются в /var/www/iskra. Файл inc/config.php (пароли, ключи)
# НИКОГДА не перезаписывается.

set -euo pipefail

# ───────── настройки ─────────
REPO="https://github.com/Winnigames2024-Original/cloud.git"
BRANCH="main"
SRC_DIR="/opt/iskra-src"          # сюда клонируется репозиторий
SITE_SRC="$SRC_DIR/iskraAI/site"  # папка с кодом сайта внутри репозитория
WEB_DIR="/var/www/iskra"          # куда разворачивается сайт
DOMAIN="iskra.winnigames2024.ru"
LE_EMAIL="winnigames2024@gmail.com"

SELF="$(readlink -f "$0")"
ARGS=("$@")
MODE="${1:-update}"

log(){ echo "[iskra $(date '+%H:%M:%S')] $*"; }
die(){ echo "[iskra ОШИБКА] $*" >&2; exit 1; }
[ "$(id -u)" = "0" ] || die "запускать от root (sudo)"

# ───────── репозиторий ─────────
ensure_repo(){
  if [ -d "$SRC_DIR/.git" ]; then
    git -C "$SRC_DIR" fetch --quiet origin "$BRANCH"
    git -C "$SRC_DIR" reset --hard --quiet "origin/$BRANCH"
  else
    rm -rf "$SRC_DIR"
    git clone --quiet --depth 1 --branch "$BRANCH" "$REPO" "$SRC_DIR"
  fi
}

# ───────── самообновление скрипта ─────────
self_update(){
  local repo_self="$SRC_DIR/iskraAI/iskra.sh"
  if [ -f "$repo_self" ] && ! cmp -s "$repo_self" "$SELF"; then
    log "найдена новая версия скрипта — перезапуск"
    cp "$repo_self" "$SELF"; chmod +x "$SELF"
    exec "$SELF" "${ARGS[@]}"
  fi
}

# ───────── развернуть файлы сайта ─────────
deploy_site(){
  [ -d "$SITE_SRC" ] || { log "папки $SITE_SRC нет — код сайта ещё не загружен, пропускаю"; return 0; }
  mkdir -p "$WEB_DIR"
  # config.php с паролями не трогаем
  rsync -a --delete \
        --exclude 'inc/config.php' \
        --exclude 'schema.sql' \
        --exclude 'config.sample.php' \
        --exclude 'gen/' \
        --exclude 'files/' \
        --exclude 'uploads/' \
        "$SITE_SRC"/ "$WEB_DIR"/
  chown -R www-data:www-data "$WEB_DIR"
  [ -f "$WEB_DIR/inc/config.php" ] && chmod 640 "$WEB_DIR/inc/config.php"
  systemctl reload php*-fpm 2>/dev/null || true
  systemctl reload nginx 2>/dev/null || true
  log "файлы сайта обновлены"
}

# ───────── первичная установка ─────────
do_install(){
  log "установка пакетов"
  apt-get update -qq
  apt-get install -y -qq git rsync nginx mariadb-server \
       php-fpm php-mysql php-mbstring php-curl certbot python3-certbot-nginx

  # база и config.php — только если ещё нет
  if [ ! -f "$WEB_DIR/inc/config.php" ]; then
    log "создаю базу данных и config.php"
    local dbpass; dbpass="$(openssl rand -base64 18)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS iskra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iskra'@'localhost' IDENTIFIED BY '$dbpass';
ALTER USER 'iskra'@'localhost' IDENTIFIED BY '$dbpass';
GRANT ALL PRIVILEGES ON iskra.* TO 'iskra'@'localhost';
FLUSH PRIVILEGES;
SQL
    mkdir -p "$WEB_DIR/inc"
    cat > "$WEB_DIR/inc/config.php" <<EOF
<?php
define('SITE_NAME', 'Искра');
define('SITE_URL',  'https://$DOMAIN');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'iskra');
define('DB_USER', 'iskra');
define('DB_PASS', '$dbpass');
define('SMTPBZ_KEY', '');
define('MAIL_FROM', 'noreply@winnigames2024.ru');
define('MAIL_NAME', 'Искра');
define('MODEL_URL', 'http://127.0.0.1:2446');
EOF
    chown -R www-data:www-data "$WEB_DIR"
    chmod 640 "$WEB_DIR/inc/config.php"
    log "пароль БД записан в $WEB_DIR/inc/config.php"
  fi

  # схема БД — применяется всегда, безопасна для повторного запуска
  if [ -f "$SITE_SRC/schema.sql" ]; then
    mysql iskra < "$SITE_SRC/schema.sql" && log "схема БД применена"
  fi

  # nginx
  local sock; sock="$(ls /run/php/php*-fpm.sock | head -1)"
  cat > /etc/nginx/sites-available/iskra <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $WEB_DIR;
    index index.php;
    client_max_body_size 16m;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:$sock; }
    location /api/ { try_files \$uri \$uri/ /api/v1/index.php?\$query_string; }
    location ~ ^/chats/([A-Za-z0-9-]+) { rewrite ^ /share.php?c=\$1 last; }
    location = /manifest.webmanifest { default_type application/manifest+json; }
    location = /sw.js { default_type application/javascript; add_header Cache-Control "no-cache"; }
    location ~ /\.(?!well-known) { deny all; }
    location ^~ /inc/    { deny all; }
    location ^~ /vendor/ { deny all; }
}
EOF
  ln -sf /etc/nginx/sites-available/iskra /etc/nginx/sites-enabled/iskra
  nginx -t && systemctl reload nginx

  deploy_site

  # сертификат (не фатально, если DNS ещё не разошёлся)
  certbot --nginx -d "$DOMAIN" --agree-tos -m "$LE_EMAIL" --redirect -n || \
    log "certbot не прошёл — проверь DNS и запусти его позже вручную"

  setup_extras
  install_timer
  log "установка завершена"
}

# ───────── доп-настройка: анти-лаг, права, часовой пояс, главный админ ─────────
setup_extras(){
  timedatectl set-timezone Europe/Moscow 2>/dev/null || true

  # постоянные сессии (чтобы аккаунт не вылетал)
  mkdir -p /var/www/iskra-sessions && chown www-data:www-data /var/www/iskra-sessions && chmod 770 /var/www/iskra-sessions
  # папки пользовательских данных (не стираются автодеплоем)
  mkdir -p "$WEB_DIR/gen" "$WEB_DIR/files" "$WEB_DIR/uploads"
  chown -R www-data:www-data "$WEB_DIR/gen" "$WEB_DIR/files" "$WEB_DIR/uploads"

  cat > /usr/local/bin/iskra-antilag.sh <<'ALEOF'
#!/bin/bash
# снять нагрузку: сбросить кэш памяти и перезапустить тяжёлые GPU-сервисы
sync
echo 1 > /proc/sys/vm/drop_caches 2>/dev/null
systemctl restart iskra-image iskra-llm 2>/dev/null
exit 0
ALEOF
  chmod +x /usr/local/bin/iskra-antilag.sh

  cat > /etc/sudoers.d/iskra <<'SUEOF'
www-data ALL=(root) NOPASSWD: /sbin/reboot, /sbin/poweroff, /usr/local/bin/iskra-antilag.sh
SUEOF
  chmod 440 /etc/sudoers.d/iskra

  # главный админ по почте владельца
  local cfg="$WEB_DIR/inc/config.php"
  if [ -f "$cfg" ]; then
    grep -q TTS_URL "$cfg"   || echo "define('TTS_URL', 'http://127.0.0.1:2449');"   >> "$cfg"
    grep -q IMAGE_URL "$cfg" || echo "define('IMAGE_URL', 'http://127.0.0.1:2447');" >> "$cfg"
    if grep -q OWNER_EMAIL "$cfg"; then
      sed -i "s|define('OWNER_EMAIL'.*|define('OWNER_EMAIL', 'winnigames2024@gmail.com');|" "$cfg"
    else
      echo "define('OWNER_EMAIL', 'winnigames2024@gmail.com');" >> "$cfg"
    fi
  fi
  mysql iskra -e "UPDATE users SET role='admin', banned=0 WHERE nick='Winnigames2024' OR email='winnigames2024@gmail.com';" 2>/dev/null || true
  systemctl reload php*-fpm 2>/dev/null || true
  log "доп-настройка: анти-лаг, права, часовой пояс МСК, главный админ"
}

# ───────── таймер автообновления (каждые 5 минут) ─────────
install_timer(){
  if [ "$SELF" != "/usr/local/bin/iskra.sh" ]; then
    cp "$SELF" /usr/local/bin/iskra.sh
  fi
  chmod +x /usr/local/bin/iskra.sh
  cat > /etc/systemd/system/iskra-update.service <<EOF
[Unit]
Description=Iskra auto-update
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/bin/iskra.sh update
EOF
  cat > /etc/systemd/system/iskra-update.timer <<EOF
[Unit]
Description=Iskra auto-update every 5 min
[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl enable --now iskra-update.timer
  log "таймер автообновления включён (каждые 5 мин)"
}

# ───────── точка входа ─────────
ensure_repo
self_update

apply_schema(){
  if [ -f "$SITE_SRC/schema.sql" ] && mysql -e "USE iskra" 2>/dev/null; then
    mysql iskra < "$SITE_SRC/schema.sql" 2>/dev/null && log "схема БД синхронизирована"
  fi
}

repo_version(){ grep -o '"version"[^,}]*' "$SRC_DIR/iskraAI/updates.json" 2>/dev/null | grep -o '[0-9]\+' | head -1; }

case "$MODE" in
  install)
    do_install
    repo_version > "$WEB_DIR/.version" 2>/dev/null || true
    ;;
  update)
    new="$(repo_version)"
    cur="$(cat "$WEB_DIR/.version" 2>/dev/null || echo 0)"
    if [ -n "$new" ] && [ "$new" != "$cur" ]; then
      deploy_site
      apply_schema
      echo "$new" > "$WEB_DIR/.version"
      log "обновлено до версии $new"
    fi
    ;;
  *)       die "режим: install | update" ;;
esac
