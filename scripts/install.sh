#!/data/data/com.termux/files/usr/bin/bash
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
echo "== TMS AI Router installer =="
command -v php >/dev/null 2>&1 || { echo "ERROR: Chưa có PHP."; exit 1; }
php -r 'if(PHP_VERSION_ID<80000){fwrite(STDERR,"Cần PHP 8.0+\n");exit(1);} if(!class_exists("SQLite3")){fwrite(STDERR,"Thiếu PHP SQLite3\n");exit(1);} if(!function_exists("curl_init")){fwrite(STDERR,"Thiếu PHP cURL\n");exit(1);}'
mkdir -p storage/secure storage/logs storage/cache
chmod 700 storage storage/secure storage/logs storage/cache 2>/dev/null || true
php -r 'require "app/Core/App.php"; \TmsAi\Core\App::boot(); echo "Database initialized\n";'
echo "OK. Document root: $ROOT/public"
