<?php
declare(strict_types=1);
namespace TmsAi\Services;

use RuntimeException;
use TmsAi\Core\App;

final class UpdateService
{
    private string $root;
    private string $cache;
    private string $repo = 'geogich961-lab/tms-ai-router';

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
        $this->cache = $this->root . '/storage/cache';
        if (!is_dir($this->cache)) @mkdir($this->cache, 0700, true);
    }

    public function currentVersion(): string
    {
        $v = trim((string)@file_get_contents($this->root . '/VERSION'));
        return $v !== '' ? $v : (string)App::config('version', '0.0.0');
    }

    public function check(): array
    {
        $current = $this->currentVersion();
        $errors = [];

        try {
            $release = $this->githubJson('https://api.github.com/repos/' . $this->repo . '/releases/latest');
            $tag = ltrim(trim((string)($release['tag_name'] ?? '')), 'vV');
            if ($tag !== '') {
                return [
                    'current' => $current,
                    'latest' => $tag,
                    'available' => version_compare($tag, $current, '>'),
                    'name' => (string)($release['name'] ?? ('v' . $tag)),
                    'notes' => (string)($release['body'] ?? ''),
                    'published_at' => (string)($release['published_at'] ?? ''),
                    'download_url' => $this->assetUrl($release, 'TMS_AI_ROUTER.zip') ?: (string)($release['zipball_url'] ?? ''),
                    'html_url' => (string)($release['html_url'] ?? ''),
                    'source' => 'github_api',
                ];
            }
            $errors[] = 'GitHub API không có tag hợp lệ';
        } catch (\Throwable $e) {
            $errors[] = 'GitHub API: ' . $e->getMessage();
        }

        try {
            $meta = $this->githubJson('https://github.com/' . $this->repo . '/releases/latest/download/RELEASE.json');
            $latest = ltrim(trim((string)($meta['version'] ?? '')), 'vV');
            if ($latest !== '') {
                return [
                    'current' => $current,
                    'latest' => $latest,
                    'available' => version_compare($latest, $current, '>'),
                    'name' => (string)($meta['name'] ?? ('TMS AI Router v' . $latest)),
                    'notes' => (string)($meta['notes'] ?? ''),
                    'published_at' => (string)($meta['released_at'] ?? ''),
                    'download_url' => (string)($meta['download_url'] ?? ('https://github.com/' . $this->repo . '/releases/download/v' . rawurlencode($latest) . '/TMS_AI_ROUTER.zip')),
                    'html_url' => 'https://github.com/' . $this->repo . '/releases/tag/v' . rawurlencode($latest),
                    'checksum_sha256' => (string)($meta['checksum_sha256'] ?? ''),
                    'source' => 'release_metadata',
                ];
            }
            $errors[] = 'RELEASE.json không có version';
        } catch (\Throwable $e) {
            $errors[] = 'RELEASE.json: ' . $e->getMessage();
        }

        try {
            $raw = $this->httpGet('https://raw.githubusercontent.com/' . $this->repo . '/main/VERSION', 15, 'text/plain');
            $latest = ltrim(trim($raw), 'vV');
            if ($latest !== '' && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $latest)) {
                return [
                    'current' => $current,
                    'latest' => $latest,
                    'available' => version_compare($latest, $current, '>'),
                    'name' => 'TMS AI Router v' . $latest,
                    'notes' => 'Phát hiện phiên bản mới từ nhánh main. Metadata release không khả dụng trên mạng hiện tại.',
                    'published_at' => '',
                    'download_url' => 'https://github.com/' . $this->repo . '/releases/download/v' . rawurlencode($latest) . '/TMS_AI_ROUTER.zip',
                    'html_url' => 'https://github.com/' . $this->repo . '/releases/tag/v' . rawurlencode($latest),
                    'source' => 'raw_version',
                ];
            }
            $errors[] = 'raw VERSION không hợp lệ';
        } catch (\Throwable $e) {
            $errors[] = 'raw VERSION: ' . $e->getMessage();
        }

        throw new RuntimeException('Không thể kiểm tra GitHub. ' . implode(' | ', $errors));
    }

    public function apply(): array
    {
        $info = $this->check();
        if (empty($info['available'])) return ['ok' => true, 'updated' => false, 'version' => $info['current']];
        if (!is_writable($this->root)) throw new RuntimeException('Thư mục TMS AI Router không có quyền ghi để hot update.');

        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$info['latest']) ?: 'update';
        $zip = $this->cache . '/update-' . $version . '.zip';
        $tmp = $this->cache . '/update-' . $version . '-' . bin2hex(random_bytes(4));
        $backup = $this->root . '/storage/backups/code-' . date('Ymd-His') . '-' . $version;
        @mkdir($tmp, 0700, true);
        @mkdir($backup, 0700, true);
        $existed = [];
        $deploymentStarted = false;

        try {
            $this->download((string)($info['download_url'] ?? ''), $zip);
            $expectedHash = trim((string)($info['checksum_sha256'] ?? ''));
            if ($expectedHash !== '' && !hash_equals(strtolower($expectedHash), strtolower(hash_file('sha256', $zip) ?: ''))) {
                throw new RuntimeException('Checksum SHA-256 của gói cập nhật không khớp.');
            }
            $this->extract($zip, $tmp);
            $source = $this->findSourceRoot($tmp);
            $incoming = trim((string)@file_get_contents($source . '/VERSION'));
            if ($incoming === '' || version_compare($incoming, $this->currentVersion(), '<=')) {
                throw new RuntimeException('Gói cập nhật không có VERSION mới hơn bản hiện tại.');
            }
            foreach (['app','config','public','database','views','VERSION'] as $required) {
                if (!file_exists($source . '/' . $required)) throw new RuntimeException('Gói cập nhật thiếu thành phần bắt buộc: ' . $required);
            }

            foreach ($this->managedPaths() as $path) {
                $dst = $this->root . '/' . $path;
                $existed[$path] = file_exists($dst) || is_link($dst);
                if ($existed[$path]) $this->copyTree($dst, $backup . '/' . $path);
            }

            $deploymentStarted = true;
            foreach ($this->managedPaths() as $path) {
                $src = $source . '/' . $path;
                if (!file_exists($src) && !is_link($src)) continue;
                $dst = $this->root . '/' . $path;
                $this->removeTree($dst);
                $this->copyTree($src, $dst);
            }

            if (function_exists('opcache_reset')) @opcache_reset();
            @file_put_contents($this->root . '/storage/cache/last-update.json', json_encode([
                'from' => $info['current'], 'to' => $incoming, 'at' => time(), 'backup' => $backup, 'source' => $info['source'] ?? ''
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'updated' => true, 'version' => $incoming, 'backup' => basename($backup)];
        } catch (\Throwable $e) {
            if ($deploymentStarted) {
                try { $this->restoreBackup($backup, $existed); } catch (\Throwable) {}
                if (function_exists('opcache_reset')) @opcache_reset();
            }
            throw new RuntimeException('Hot update thất bại' . ($deploymentStarted ? ' và đã rollback source' : '') . ': ' . $e->getMessage());
        } finally {
            @unlink($zip);
            $this->removeTree($tmp);
        }
    }

    private function restoreBackup(string $backup, array $existed): void
    {
        foreach ($this->managedPaths() as $path) {
            $dst = $this->root . '/' . $path;
            $src = $backup . '/' . $path;
            $this->removeTree($dst);
            if (!empty($existed[$path]) && (file_exists($src) || is_link($src))) $this->copyTree($src, $dst);
        }
    }

    private function managedPaths(): array
    {
        return ['app', 'config', 'database', 'public', 'views', 'scripts', 'VERSION', 'README.md', 'install-tms-os.sh', 'nginx.example.conf', '.gitignore'];
    }

    private function assetUrl(array $release, string $name): string
    {
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && (string)($asset['name'] ?? '') === $name) return (string)($asset['browser_download_url'] ?? '');
        }
        return '';
    }

    private function githubJson(string $url): array
    {
        $body = $this->httpGet($url, 25, 'application/vnd.github+json');
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('GitHub trả về JSON không hợp lệ.');
        return $data;
    }

    private function httpGet(string $url, int $timeout, string $accept): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL chưa được cài.');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'User-Agent: TMS-AI-Router/' . $this->currentVersion()],
        ];
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException(($err !== '' ? $err : 'HTTP ' . $code));
        return (string)$body;
    }

    private function download(string $url, string $target): void
    {
        if ($url === '') throw new RuntimeException('Release không có URL tải xuống.');
        $fp = fopen($target, 'wb');
        if (!$fp) throw new RuntimeException('Không tạo được file cập nhật tạm.');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'User-Agent: TMS-AI-Router/' . $this->currentVersion()],
        ];
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code < 200 || $code >= 300 || !is_file($target) || filesize($target) < 1000) {
            @unlink($target);
            throw new RuntimeException('Tải gói cập nhật thất bại' . ($err ? ': ' . $err : ' (HTTP ' . $code . ')'));
        }
    }

    private function extract(string $zip, string $to): void
    {
        if (class_exists('ZipArchive')) {
            $z = new \ZipArchive();
            if ($z->open($zip) !== true) throw new RuntimeException('Không mở được ZIP cập nhật.');
            if (!$z->extractTo($to)) { $z->close(); throw new RuntimeException('Không giải nén được ZIP cập nhật.'); }
            $z->close();
            return;
        }
        $out = []; $code = 0;
        exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($to) . ' 2>&1', $out, $code);
        if ($code !== 0) throw new RuntimeException('Máy chủ cần ZipArchive hoặc lệnh unzip.');
    }

    private function findSourceRoot(string $tmp): string
    {
        if (is_file($tmp . '/VERSION') && is_dir($tmp . '/app')) return $tmp;
        foreach (glob($tmp . '/*') ?: [] as $dir) {
            if (is_dir($dir) && is_file($dir . '/VERSION') && is_dir($dir . '/app')) return $dir;
        }
        throw new RuntimeException('Cấu trúc release không hợp lệ.');
    }

    private function copyTree(string $src, string $dst): void
    {
        if (is_file($src) || is_link($src)) {
            @mkdir(dirname($dst), 0700, true);
            if (!copy($src, $dst)) throw new RuntimeException('Không copy được ' . basename($src));
            return;
        }
        if (!is_dir($dst) && !mkdir($dst, 0700, true) && !is_dir($dst)) throw new RuntimeException('Không tạo được ' . $dst);
        foreach (scandir($src) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') $this->copyTree($src . '/' . $item, $dst . '/' . $item);
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') $this->removeTree($path . '/' . $item);
        }
        @rmdir($path);
    }
}
