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
        $release = $this->githubJson('https://api.github.com/repos/' . $this->repo . '/releases/latest');
        $tag = ltrim(trim((string)($release['tag_name'] ?? '')), 'vV');
        if ($tag === '') throw new RuntimeException('GitHub chưa có release hợp lệ.');
        return [
            'current' => $this->currentVersion(),
            'latest' => $tag,
            'available' => version_compare($tag, $this->currentVersion(), '>'),
            'name' => (string)($release['name'] ?? ('v' . $tag)),
            'notes' => (string)($release['body'] ?? ''),
            'published_at' => (string)($release['published_at'] ?? ''),
            'zipball_url' => (string)($release['zipball_url'] ?? ''),
            'html_url' => (string)($release['html_url'] ?? ''),
        ];
    }

    public function apply(): array
    {
        $info = $this->check();
        if (!$info['available']) return ['ok' => true, 'updated' => false, 'version' => $info['current']];
        if (!is_writable($this->root)) throw new RuntimeException('Thư mục TMS AI Router không có quyền ghi để hot update.');

        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$info['latest']) ?: 'update';
        $zip = $this->cache . '/update-' . $version . '.zip';
        $tmp = $this->cache . '/update-' . $version . '-' . bin2hex(random_bytes(4));
        $backup = $this->root . '/storage/backups/code-' . date('Ymd-His') . '-' . $version;
        @mkdir($tmp, 0700, true);
        @mkdir($backup, 0700, true);

        try {
            $this->download((string)$info['zipball_url'], $zip);
            $this->extract($zip, $tmp);
            $source = $this->findSourceRoot($tmp);
            $incoming = trim((string)@file_get_contents($source . '/VERSION'));
            if ($incoming === '' || version_compare($incoming, $this->currentVersion(), '<=')) {
                throw new RuntimeException('Gói cập nhật không có VERSION mới hơn bản hiện tại.');
            }
            foreach ($this->managedPaths() as $path) {
                $src = $source . '/' . $path;
                if (!file_exists($src)) continue;
                $dst = $this->root . '/' . $path;
                if (file_exists($dst)) $this->copyTree($dst, $backup . '/' . $path);
                $this->removeTree($dst);
                $this->copyTree($src, $dst);
            }
            if (function_exists('opcache_reset')) @opcache_reset();
            @file_put_contents($this->root . '/storage/cache/last-update.json', json_encode([
                'from' => $info['current'], 'to' => $incoming, 'at' => time(), 'backup' => $backup
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'updated' => true, 'version' => $incoming, 'backup' => basename($backup)];
        } catch (\Throwable $e) {
            throw new RuntimeException('Hot update thất bại: ' . $e->getMessage());
        } finally {
            @unlink($zip);
            $this->removeTree($tmp);
        }
    }

    private function managedPaths(): array
    {
        return ['app', 'config', 'database', 'public', 'views', 'scripts', 'VERSION', 'README.md', 'install-tms-os.sh', 'nginx.example.conf', '.gitignore'];
    }

    private function githubJson(string $url): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL chưa được cài.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: TMS-AI-Router/' . $this->currentVersion()]]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) throw new RuntimeException('Không đọc được GitHub Release' . ($err ? ': ' . $err : ' (HTTP ' . $code . ')'));
        $data = json_decode((string)$body, true);
        if (!is_array($data)) throw new RuntimeException('GitHub trả về JSON không hợp lệ.');
        return $data;
    }

    private function download(string $url, string $target): void
    {
        if ($url === '') throw new RuntimeException('Release không có zipball_url.');
        $fp = fopen($target, 'wb'); if (!$fp) throw new RuntimeException('Không tạo được file cập nhật tạm.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'User-Agent: TMS-AI-Router/' . $this->currentVersion()]]);
        $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch); fclose($fp);
        if (!$ok || $code < 200 || $code >= 300 || filesize($target) < 1000) throw new RuntimeException('Tải gói cập nhật thất bại' . ($err ? ': ' . $err : ' (HTTP ' . $code . ')'));
    }

    private function extract(string $zip, string $to): void
    {
        if (class_exists('ZipArchive')) {
            $z = new \ZipArchive(); if ($z->open($zip) !== true) throw new RuntimeException('Không mở được ZIP cập nhật.');
            if (!$z->extractTo($to)) { $z->close(); throw new RuntimeException('Không giải nén được ZIP cập nhật.'); } $z->close(); return;
        }
        $out = []; $code = 0; exec('unzip -q ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($to) . ' 2>&1', $out, $code);
        if ($code !== 0) throw new RuntimeException('Máy chủ cần ZipArchive hoặc lệnh unzip.');
    }

    private function findSourceRoot(string $tmp): string
    {
        foreach (glob($tmp . '/*') ?: [] as $dir) if (is_dir($dir) && is_file($dir . '/VERSION') && is_dir($dir . '/app')) return $dir;
        if (is_file($tmp . '/VERSION') && is_dir($tmp . '/app')) return $tmp;
        throw new RuntimeException('Cấu trúc release không hợp lệ.');
    }

    private function copyTree(string $src, string $dst): void
    {
        if (is_file($src) || is_link($src)) { @mkdir(dirname($dst), 0700, true); if (!copy($src, $dst)) throw new RuntimeException('Không copy được ' . basename($src)); return; }
        if (!is_dir($dst) && !mkdir($dst, 0700, true) && !is_dir($dst)) throw new RuntimeException('Không tạo được ' . $dst);
        foreach (scandir($src) ?: [] as $item) if ($item !== '.' && $item !== '..') $this->copyTree($src . '/' . $item, $dst . '/' . $item);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') $this->removeTree($path . '/' . $item);
        @rmdir($path);
    }
}
