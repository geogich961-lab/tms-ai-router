#!/data/data/com.termux/files/usr/bin/bash
set -Eeuo pipefail

REPO="${TMS_AI_ROUTER_REPO:-geogich961-lab/tms-ai-router}"
REF="${TMS_AI_ROUTER_REF:-main}"
HOME="${HOME:-/data/data/com.termux/files/home}"
PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"
TARGET="${TMS_AI_ROUTER_HOME:-$HOME/websites/tms-ai-router}"
SITES="$PREFIX/etc/nginx/sites-enabled"
NGINX_CONF="$SITES/tms-ai-router.conf"
TMS_ROOT="$HOME/tms-os"
STATE="$HOME/.tms-os"
BACKUP_ROOT="$HOME/.tms-ai-router-backups"
WORK="$HOME/.tms-ai-router-installer-$$"
PORT="${TMS_AI_ROUTER_PORT:-8788}"

say(){ printf '%s\n' "$*"; }
fail(){ say "[LỖI] $*" >&2; exit 1; }
cleanup(){ rm -rf "$WORK" 2>/dev/null || true; }
trap cleanup EXIT

say ""
say "============================================"
say " TMS AI Router — Installer for TMS OS"
say "============================================"

[ -d "$TMS_ROOT" ] || fail "Không tìm thấy TMS OS tại $TMS_ROOT. Hãy cài TMS OS trước."
[ -x "$TMS_ROOT/scripts/start-tms.sh" ] || fail "TMS OS thiếu scripts/start-tms.sh."
command -v php >/dev/null 2>&1 || fail "Không tìm thấy PHP."
command -v nginx >/dev/null 2>&1 || fail "Không tìm thấy Nginx."
command -v curl >/dev/null 2>&1 || fail "Không tìm thấy cURL."
command -v unzip >/dev/null 2>&1 || fail "Không tìm thấy unzip."

PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
[ "$PHP_VERSION_ID" -ge 80000 ] || fail "Cần PHP 8.0+."
php -r 'exit(class_exists("SQLite3") ? 0 : 1);' || fail "PHP SQLite3 chưa khả dụng."
php -r 'exit(function_exists("curl_init") ? 0 : 1);' || fail "PHP cURL chưa khả dụng."
php -r 'exit(function_exists("sodium_crypto_secretbox") || function_exists("openssl_encrypt") ? 0 : 1);' || fail "Cần PHP Sodium hoặc OpenSSL để mã hóa API key."

mkdir -p "$WORK" "$BACKUP_ROOT" "$SITES" "$HOME/websites"

if [ ! -f "$NGINX_CONF" ]; then
  while :; do
    if php -r '$p=(int)$argv[1];$e=0;$s=@stream_socket_server("tcp://0.0.0.0:".$p,$e,$m);if($s){fclose($s);exit(0);}exit(1);' "$PORT"; then
      break
    fi
    PORT=$((PORT + 1))
    [ "$PORT" -le 8798 ] || fail "Không tìm thấy cổng trống trong dải 8788-8798."
  done
else
  EXISTING_PORT="$(sed -nE 's/.*listen[[:space:]]+0\.0\.0\.0:([0-9]+).*/\1/p' "$NGINX_CONF" | head -n1 || true)"
  [ -n "$EXISTING_PORT" ] && PORT="$EXISTING_PORT"
fi

say "[1/6] Tải source $REPO@$REF ..."
ZIP="$WORK/source.zip"
URL="https://github.com/${REPO}/archive/refs/heads/${REF}.zip"
if ! curl -fLsS -4 --http1.1 --connect-timeout 20 --max-time 240 --retry 2 -o "$ZIP" "$URL"; then
  curl -fLsS --connect-timeout 20 --max-time 240 --retry 2 -o "$ZIP" "$URL" || fail "Không tải được source từ GitHub."
fi
unzip -q "$ZIP" -d "$WORK/extract"
SRC="$(find "$WORK/extract" -maxdepth 3 -type f -path '*/public/index.php' -print | head -n1 | xargs -r dirname | xargs -r dirname)"
[ -n "$SRC" ] && [ -d "$SRC/app" ] && [ -d "$SRC/public" ] || fail "Source tải về không đúng cấu trúc."

say "[2/6] Kiểm tra source PHP ..."
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || fail "PHP syntax error: $f"
done < <(find "$SRC" -type f -name '*.php' -print0)

STAMP="$(date +%Y%m%d_%H%M%S)"
if [ -d "$TARGET" ]; then
  say "[3/6] Nâng cấp bản hiện có — sao lưu dữ liệu runtime ..."
  mkdir -p "$BACKUP_ROOT/$STAMP"
  [ -d "$TARGET/storage" ] && cp -a "$TARGET/storage" "$BACKUP_ROOT/$STAMP/storage"
else
  say "[3/6] Cài mới ..."
fi

RUNTIME_TMP="$WORK/runtime-preserve"
if [ -d "$TARGET/storage" ]; then
  mv "$TARGET/storage" "$RUNTIME_TMP"
fi

rm -rf "$TARGET.new"
mkdir -p "$TARGET.new"
cp -a "$SRC/." "$TARGET.new/"
rm -rf "$TARGET.new/storage"
if [ -d "$RUNTIME_TMP" ]; then
  mv "$RUNTIME_TMP" "$TARGET.new/storage"
else
  mkdir -p "$TARGET.new/storage/secure" "$TARGET.new/storage/logs" "$TARGET.new/storage/cache"
  touch "$TARGET.new/storage/.gitkeep" "$TARGET.new/storage/secure/.gitkeep" "$TARGET.new/storage/logs/.gitkeep" "$TARGET.new/storage/cache/.gitkeep"
fi
chmod 700 "$TARGET.new/storage" "$TARGET.new/storage/secure" "$TARGET.new/storage/logs" "$TARGET.new/storage/cache" 2>/dev/null || true

OLD_TARGET=""
if [ -d "$TARGET" ]; then
  OLD_TARGET="$TARGET.old-$STAMP"
  mv "$TARGET" "$OLD_TARGET"
fi
mv "$TARGET.new" "$TARGET"

say "[4/6] Khởi tạo SQLite ..."
if ! php -r 'require $argv[1]."/app/Core/App.php"; \TmsAi\Core\App::boot(); echo "ok";' "$TARGET" >/dev/null; then
  rm -rf "$TARGET"
  [ -n "$OLD_TARGET" ] && [ -d "$OLD_TARGET" ] && mv "$OLD_TARGET" "$TARGET"
  fail "Không khởi tạo được database. Bản cũ đã được khôi phục."
fi

ENGINE="fastcgi"
if [ -x "$TMS_ROOT/scripts/tms-php-engine.sh" ]; then
  DETECTED="$(bash "$TMS_ROOT/scripts/tms-php-engine.sh" status 2>/dev/null || true)"
  case "$DETECTED" in php-http|fastcgi) ENGINE="$DETECTED" ;; esac
elif [ -f "$STATE/php-engine-policy" ]; then
  DETECTED="$(cat "$STATE/php-engine-policy" 2>/dev/null || true)"
  case "$DETECTED" in php-http|fastcgi) ENGINE="$DETECTED" ;; esac
fi

say "[5/6] Tạo Nginx site trên cổng $PORT (engine: $ENGINE) ..."
CONF_BACKUP=""
if [ -f "$NGINX_CONF" ]; then
  CONF_BACKUP="$WORK/tms-ai-router.conf.backup"
  cp "$NGINX_CONF" "$CONF_BACKUP"
fi

if [ "$ENGINE" = "php-http" ]; then
cat > "$NGINX_CONF" <<NGINX
server {
    listen 0.0.0.0:${PORT};
    server_name _;
    root ${TARGET}/public;
    index index.php index.html;
    access_log ${HOME}/logs/nginx/tms-ai-router-access.log tms_access;
    error_log ${HOME}/logs/nginx/tms-ai-router-error.log;
    location / {
        proxy_pass http://127.0.0.1:9000;
        proxy_request_buffering off;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
        proxy_set_header Host \$host;
        proxy_set_header X-TMS-Root ${TARGET}/public;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    }
    location ~* \.(css|js|jpg|jpeg|png|gif|webp|ico|svg|woff2?|ttf|eot)$ {
        expires 1h;
        add_header Cache-Control "public";
        access_log off;
    }
    location ~ /\. { deny all; }
}
NGINX
else
cat > "$NGINX_CONF" <<NGINX
server {
    listen 0.0.0.0:${PORT};
    server_name _;
    root ${TARGET}/public;
    index index.php index.html;
    access_log ${HOME}/logs/nginx/tms-ai-router-access.log tms_access;
    error_log ${HOME}/logs/nginx/tms-ai-router-error.log;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_pass 127.0.0.1:9000;
    }
    location ~* \.(css|js|jpg|jpeg|png|gif|webp|ico|svg|woff2?|ttf|eot)$ {
        expires 1h;
        add_header Cache-Control "public";
        access_log off;
    }
    location ~ /\. { deny all; }
}
NGINX
fi

mkdir -p "$HOME/logs/nginx"
if ! nginx -t >/dev/null 2>&1; then
  [ -n "$CONF_BACKUP" ] && cp "$CONF_BACKUP" "$NGINX_CONF" || rm -f "$NGINX_CONF"
  rm -rf "$TARGET"
  [ -n "$OLD_TARGET" ] && [ -d "$OLD_TARGET" ] && mv "$OLD_TARGET" "$TARGET"
  fail "Nginx config không hợp lệ. Đã rollback."
fi

if nginx -s reload >/dev/null 2>&1; then
  :
else
  bash "$TMS_ROOT/scripts/start-tms.sh" >/dev/null 2>&1 || fail "Không thể reload/start TMS OS sau khi cài."
fi

rm -rf "$OLD_TARGET" 2>/dev/null || true

say "[6/6] Kiểm tra dịch vụ ..."
LOCAL_OK=0
for _ in 1 2 3 4 5; do
  if curl -fsS --max-time 4 "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
    LOCAL_OK=1
    break
  fi
  sleep 1
done

LAN_IP="$(php -r '$s=@socket_create(AF_INET,SOCK_DGRAM,SOL_UDP);if($s){@socket_connect($s,"8.8.8.8",53);@socket_getsockname($s,$a);@socket_close($s);}if(!empty($a)&&$a!=="0.0.0.0"){echo $a;exit;}$r=@shell_exec("ip route 2>/dev/null");if(preg_match("/src ([0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+)/",$r,$m)){echo $m[1];exit;}$g=trim((string)@shell_exec("getprop dhcp.wlan0.ipaddress 2>/dev/null"));echo preg_match("/^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$/",$g)?$g:"127.0.0.1";' 2>/dev/null || echo 127.0.0.1)"

say ""
say "============================================"
if [ "$LOCAL_OK" -eq 1 ]; then
  say " [OK] TMS AI Router đã cài thành công!"
else
  say " [CẢNH BÁO] Đã cài xong nhưng health-check chưa phản hồi."
  say " Hãy chạy: bash ~/tms-os/scripts/start-tms.sh"
fi
say " Local : http://127.0.0.1:${PORT}"
say " LAN   : http://${LAN_IP}:${PORT}"
say " Source: ${TARGET}"
say " Nginx : ${NGINX_CONF}"
say " Engine: ${ENGINE}"
say "============================================"
say ""
say "Lần đầu mở URL trên, hệ thống sẽ chuyển tới /setup để tạo tài khoản admin."
