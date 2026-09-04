#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

REPO="${TMS_AI_ROUTER_REPO:-geogich961-lab/tms-ai-router}"
REF="${TMS_AI_ROUTER_REF:-main}"
PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"
HOME="${HOME:-/data/data/com.termux/files/home}"
NAME="tms-ai-router"
TARGET="$HOME/websites/$NAME"
SITES_DIR="$PREFIX/etc/nginx/sites-enabled"
CONF="$SITES_DIR/$NAME.conf"
TMS_ROOT="$HOME/tms-os"
WORK="$HOME/.tms-ai-router-installer-$$"
BACKUP_ROOT="$HOME/.tms-ai-router-backups"
ARCHIVE="$WORK/source.zip"
STAGE="$WORK/stage"
PORT="${TMS_AI_ROUTER_PORT:-}"

cleanup(){ rm -rf "$WORK" 2>/dev/null || true; }
trap cleanup EXIT

say(){ printf '%s\n' "$*"; }
fail(){ say "[LỖI] $*" >&2; exit 1; }

say '============================================='
say ' TMS AI Router — Installer dành cho TMS OS'
say '============================================='

[ -d "$TMS_ROOT" ] || fail "Không phát hiện TMS OS tại $TMS_ROOT"
[ -d "$SITES_DIR" ] || mkdir -p "$SITES_DIR"
for c in php nginx curl unzip; do command -v "$c" >/dev/null 2>&1 || fail "Thiếu lệnh: $c"; done

php -r 'if(PHP_VERSION_ID<80000){fwrite(STDERR,"PHP 8.0+ required\n");exit(1);} if(!class_exists("SQLite3")){fwrite(STDERR,"SQLite3 extension missing\n");exit(2);} if(!function_exists("curl_init")){fwrite(STDERR,"cURL extension missing\n");exit(3);} if(!function_exists("sodium_crypto_secretbox")&&!function_exists("openssl_encrypt")){fwrite(STDERR,"Sodium/OpenSSL missing\n");exit(4);}' \
  || fail 'PHP của máy chưa đủ SQLite3 + cURL + Sodium/OpenSSL.'

mkdir -p "$WORK" "$BACKUP_ROOT" "$HOME/websites"

say '[1/6] Tải source TMS AI Router...'
URL="https://github.com/${REPO}/archive/refs/heads/${REF}.zip"
curl -fL --connect-timeout 20 --max-time 240 --retry 2 --retry-delay 2 -o "$ARCHIVE" "$URL" \
  || fail 'Không tải được source từ GitHub.'
unzip -q "$ARCHIVE" -d "$WORK"
SRC="$(find "$WORK" -mindepth 1 -maxdepth 1 -type d -name 'tms-ai-router-*' | head -n1)"
[ -n "$SRC" ] && [ -f "$SRC/public/index.php" ] || fail 'Gói source không hợp lệ.'

say '[2/6] Kiểm tra PHP source...'
while IFS= read -r f; do php -l "$f" >/dev/null || fail "PHP syntax error: $f"; done < <(find "$SRC" -type f -name '*.php' | sort)

mkdir -p "$STAGE"
cp -a "$SRC"/. "$STAGE"/
rm -rf "$STAGE/.git" 2>/dev/null || true
mkdir -p "$STAGE/storage/secure" "$STAGE/storage/logs" "$STAGE/storage/cache"
chmod 700 "$STAGE/storage" "$STAGE/storage/secure" "$STAGE/storage/logs" "$STAGE/storage/cache" 2>/dev/null || true

say '[3/6] Chuẩn bị dữ liệu và cập nhật an toàn...'
TS="$(date +%Y%m%d-%H%M%S)"
OLD_BACKUP=""
if [ -d "$TARGET" ]; then
  OLD_BACKUP="$BACKUP_ROOT/$NAME-$TS"
  mv "$TARGET" "$OLD_BACKUP"
  if [ -d "$OLD_BACKUP/storage" ]; then
    rm -rf "$STAGE/storage"
    cp -a "$OLD_BACKUP/storage" "$STAGE/storage"
  fi
fi
mv "$STAGE" "$TARGET"
chmod +x "$TARGET/scripts/"*.sh "$TARGET/install-tms-os.sh" 2>/dev/null || true

if ! php -r 'require $argv[1]."/app/Core/App.php"; \TmsAi\Core\App::boot(); echo "OK\n";' "$TARGET" >/dev/null; then
  rm -rf "$TARGET"
  [ -n "$OLD_BACKUP" ] && mv "$OLD_BACKUP" "$TARGET"
  fail 'Không khởi tạo được SQLite/database. Đã rollback source.'
fi

port_used(){
  local p="$1"
  grep -RqsE "listen[[:space:]]+(0\.0\.0\.0:)?${p}[[:space:]]*;" "$SITES_DIR" 2>/dev/null && return 0
  if command -v ss >/dev/null 2>&1; then ss -ltn 2>/dev/null | grep -qE "[:.]${p}[[:space:]]" && return 0; fi
  return 1
}
if [ -z "$PORT" ]; then
  for p in $(seq 8788 8810); do if ! port_used "$p"; then PORT="$p"; break; fi; done
fi
[[ "$PORT" =~ ^[0-9]+$ ]] || fail 'Port không hợp lệ.'
[ "$PORT" -ge 1024 ] && [ "$PORT" -le 65535 ] || fail 'Port phải từ 1024 đến 65535.'
if port_used "$PORT" && [ ! -f "$CONF" ]; then fail "Port $PORT đang được sử dụng."; fi

ENGINE="fastcgi"
POLICY="$HOME/.tms-os/php-engine-policy"
if [ -r "$POLICY" ]; then ENGINE="$(cat "$POLICY" 2>/dev/null || true)"; fi
if [ -x "$TMS_ROOT/scripts/tms-php-engine.sh" ]; then
  detected="$(bash "$TMS_ROOT/scripts/tms-php-engine.sh" status 2>/dev/null || true)"
  [ "$detected" = 'php-http' ] || [ "$detected" = 'fastcgi' ] && ENGINE="$detected"
fi
case "$ENGINE" in fastcgi|php-http) ;; *) ENGINE='fastcgi' ;; esac

say "[4/6] Tạo Nginx site (port $PORT, PHP engine: $ENGINE)..."
CONF_BACKUP=""
if [ -f "$CONF" ]; then CONF_BACKUP="$BACKUP_ROOT/$NAME-nginx-$TS.conf"; cp "$CONF" "$CONF_BACKUP"; fi

if [ "$ENGINE" = 'php-http' ]; then
cat > "$CONF" <<EOF
server {
    listen 0.0.0.0:$PORT;
    server_name _;
    root $TARGET/public;
    index index.php index.html;
    client_max_body_size 8M;

    location / {
        try_files \$uri \$uri/ @tms_ai_router_php;
    }

    location @tms_ai_router_php {
        proxy_set_header X-TMS-Root \$document_root;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 180s;
        proxy_send_timeout 180s;
        proxy_pass http://127.0.0.1:9000;
    }

    location ~ \.php$ {
        proxy_set_header X-TMS-Root \$document_root;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 180s;
        proxy_send_timeout 180s;
        proxy_pass http://127.0.0.1:9000;
    }

    location ~ /\. { deny all; }
}
EOF
else
cat > "$CONF" <<EOF
server {
    listen 0.0.0.0:$PORT;
    server_name _;
    root $TARGET/public;
    index index.php index.html;
    client_max_body_size 8M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
        fastcgi_read_timeout 180s;
        fastcgi_send_timeout 180s;
        fastcgi_pass 127.0.0.1:9000;
    }

    location ~ /\. { deny all; }
}
EOF
fi

say '[5/6] Kiểm tra và reload Nginx...'
if ! nginx -t >/dev/null 2>&1; then
  rm -f "$CONF"
  [ -n "$CONF_BACKUP" ] && cp "$CONF_BACKUP" "$CONF"
  nginx -t >/dev/null 2>&1 || true
  fail 'Nginx config không hợp lệ. Đã rollback cấu hình.'
fi
nginx -s reload >/dev/null 2>&1 || nginx >/dev/null 2>&1 || fail 'Không reload/start được Nginx.'

say '[6/6] Kiểm tra health endpoint...'
OK=0
for _ in 1 2 3 4 5; do
  if curl -fsS --max-time 5 "http://127.0.0.1:$PORT/health" | grep -q '"ok":true'; then OK=1; break; fi
  sleep 1
done

LAN_IP=""
if command -v ip >/dev/null 2>&1; then LAN_IP="$(ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1);exit}}')"; fi
[ -n "$LAN_IP" ] || LAN_IP="127.0.0.1"

say ''
say '============================================='
if [ "$OK" -eq 1 ]; then say ' [OK] TMS AI Router đã cài thành công!'; else say ' [CẢNH BÁO] Đã cài nhưng health check chưa phản hồi.'; fi
say " Thư mục: $TARGET"
say " Nginx:    $CONF"
say " Port:     $PORT"
say " Truy cập: http://$LAN_IP:$PORT/"
say " Health:   http://$LAN_IP:$PORT/health"
[ -n "$OLD_BACKUP" ] && say " Backup bản cũ: $OLD_BACKUP"
say '============================================='
say 'Lần đầu mở trang, hệ thống sẽ yêu cầu tạo tài khoản Admin.'
