#!/usr/bin/env sh
set -eu

ROOT="$1"
STAGE="$2"
VERSION="$3"
TOKEN="$4"
CACHE="$ROOT/storage/cache"
STATUS="$CACHE/hot-update-status.json"
PREV="$CACHE/prev-$TOKEN"
BACKUP="$ROOT/storage/backups/code-$(date +%Y%m%d-%H%M%S)-$VERSION"
SWAPPED="$CACHE/swapped-$TOKEN.txt"
PATHS="app config database public views scripts VERSION README.md install-tms-os.sh nginx.example.conf .gitignore"

json_status() {
  state="$1"; message="$2"
  safe_msg=$(printf '%s' "$message" | sed 's/\\/\\\\/g;s/"/\\"/g')
  printf '{"state":"%s","version":"%s","message":"%s","at":%s}\n' "$state" "$VERSION" "$safe_msg" "$(date +%s)" > "$STATUS.tmp"
  mv "$STATUS.tmp" "$STATUS"
}

rollback() {
  json_status failed "Cập nhật gặp lỗi; đang rollback source cũ."
  if [ -f "$SWAPPED" ]; then
    while IFS= read -r p; do
      [ -n "$p" ] || continue
      rm -rf "$ROOT/$p" 2>/dev/null || true
      if [ -e "$PREV/$p" ] || [ -L "$PREV/$p" ]; then
        mkdir -p "$(dirname "$ROOT/$p")"
        mv "$PREV/$p" "$ROOT/$p" 2>/dev/null || true
      fi
    done < "$SWAPPED"
  fi
  json_status failed "Cập nhật thất bại và đã rollback source. Không restart PHP, Nginx hoặc Tunnel."
  exit 1
}
trap rollback HUP INT TERM

sleep 2
json_status applying "Đang áp dụng source mới bằng worker nền; không restart dịch vụ dùng chung."
mkdir -p "$PREV" "$BACKUP"
: > "$SWAPPED"

if command -v php >/dev/null 2>&1; then
  find "$STAGE" -type f -name '*.php' > "$CACHE/php-files-$TOKEN.txt"
  while IFS= read -r f; do
    php -l "$f" >/dev/null 2>&1 || { rm -f "$CACHE/php-files-$TOKEN.txt"; rollback; }
  done < "$CACHE/php-files-$TOKEN.txt"
  rm -f "$CACHE/php-files-$TOKEN.txt"
fi

for p in $PATHS; do
  if [ -e "$ROOT/$p" ] || [ -L "$ROOT/$p" ]; then
    mkdir -p "$BACKUP/$(dirname "$p")"
    cp -R "$ROOT/$p" "$BACKUP/$p"
  fi
done

for p in $PATHS; do
  [ -e "$STAGE/$p" ] || [ -L "$STAGE/$p" ] || continue
  mkdir -p "$PREV/$(dirname "$p")"
  if [ -e "$ROOT/$p" ] || [ -L "$ROOT/$p" ]; then
    mv "$ROOT/$p" "$PREV/$p" || rollback
  fi
  mkdir -p "$(dirname "$ROOT/$p")"
  mv "$STAGE/$p" "$ROOT/$p" || rollback
  printf '%s\n' "$p" >> "$SWAPPED"
done

[ "$(cat "$ROOT/VERSION" 2>/dev/null || true)" = "$VERSION" ] || rollback
if command -v php >/dev/null 2>&1; then
  php -l "$ROOT/public/index.php" >/dev/null 2>&1 || rollback
  [ ! -f "$ROOT/public/router-api.php" ] || php -l "$ROOT/public/router-api.php" >/dev/null 2>&1 || rollback
fi

rm -rf "$PREV" "$STAGE" 2>/dev/null || true
rm -f "$SWAPPED" "$0" 2>/dev/null || true
json_status done "Cập nhật hoàn tất. Source cũ đã được backup; storage, SQLite và master key được giữ nguyên."
exit 0
