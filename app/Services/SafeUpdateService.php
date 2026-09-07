<?php
declare(strict_types=1);
namespace TmsAi\Services;

use RuntimeException;
use TmsAi\Core\App;

final class SafeUpdateService
{
    private string $root;
    private string $cache;
    private string $statusFile;
    private UpdateService $base;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
        $this->cache = $this->root . '/storage/cache';
        $this->statusFile = $this->cache . '/hot-update-status.json';
        if (!is_dir($this->cache)) @mkdir($this->cache, 0700, true);
        $this->base = new UpdateService();
    }

    public function check(): array
    {
        return $this->base->check();
    }

    public function status(): array
    {
        $data = @json_decode((string)@file_get_contents($this->statusFile), true);
        if (!is_array($data)) {
            return ['state' => 'idle', 'message' => 'Chưa có tiến trình cập nhật.'];
        }
        return $data;
    }

    public function apply(): array
    {
        $info = $this->base->check();
        if (empty($info['available'])) {
            return ['ok' => true, 'scheduled' => false, 'updated' => false, 'version' => $info['current']];
        }
        if (!is_writable($this->root) || !is_writable($this->cache)) {
            throw new RuntimeException('Thư mục TMS AI Router không có quyền ghi để hot update.');
        }
        if (!function_exists('exec')) {
            throw new RuntimeException('PHP exec() không khả dụng; không thể chạy updater an toàn nền.');
        }

        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$info['latest']) ?: 'update';
        $token = bin2hex(random_bytes(5));
        $zip = $this->cache . '/download-' . $version . '-' . $token . '.zip';
        $extract = $this->cache . '/extract-' . $version . '-' . $token;
        $stage = $this->cache . '/stage-' . $version . '-' . $token;
        @mkdir($extract, 0700, true);
        @mkdir($stage, 0700, true);

        try {
            $this->download((string)($info['download_url'] ?? ''), $zip);
            $expectedHash = trim((string)($info['checksum_sha256'] ?? ''));
            if ($expectedHash !== '') {
                $actual = strtolower((string)(hash_file('sha256', $zip) ?: ''));
                if ($actual === '' || !hash_equals(strtolower($expectedHash), $actual)) {
                    throw new RuntimeException('Checksum SHA-256 của gói cập nhật không khớp.');
                }
            }

            $this->extract($zip, $extract);
            $source = $this->findSourceRoot($extract);
            $incoming = trim((string)@file_get_contents($source . '/VERSION'));
            if ($incoming === '' || version_compare($incoming, $this->base->currentVersion(), '<=')) {
                throw new RuntimeException('Gói cập nhật không có VERSION mới hơn bản hiện tại.');
            }
            foreach (['app','config','public','database','views','VERSION'] as $required) {
                if (!file_exists($source . '/' . $required)) {
                    throw new RuntimeException('Gói cập nhật thiếu thành phần bắt buộc: ' . $required);
                }
            }

            foreach ($this->managedPaths() as $path) {
                $src = $source . '/' . $path;
                if (file_exists($src) || is_link($src)) {
                    $this->copyTree($src, $stage . '/' . $path);
                }
            }

            $workerSource = $this->root . '/scripts/hot-update-worker.sh';
            if (!is_file($workerSource)) {
                throw new RuntimeException('Thiếu scripts/hot-update-worker.sh.');
            }
            $worker = $this->cache . '/worker-' . $token . '.sh';
            if (!copy($workerSource, $worker)) {
                throw new RuntimeException('Không chuẩn bị được updater nền.');
            }
            @chmod($worker, 0700);

            $this->writeStatus([
                'state' => 'queued',
                'from' => $info['current'],
                'to' => $incoming,
                'message' => 'Gói cập nhật đã xác minh. Đang chờ áp dụng an toàn.',
                'at' => time(),
            ]);

            $cmd = 'nohup sh ' . escapeshellarg($worker)
                . ' ' . escapeshellarg($this->root)
                . ' ' . escapeshellarg($stage)
                . ' ' . escapeshellarg($incoming)
                . ' ' . escapeshellarg($token)
                . ' >/dev/null 2>&1 &';
            $out = []; $code = 0;
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Không khởi chạy được updater nền.');
            }

            return [
                'ok' => true,
                'scheduled' => true,
                'updated' => false,
                'version' => $incoming,
                'message' => 'Updater nền đã được lên lịch. Không restart PHP/Nginx/Tunnel.',
            ];
        } catch (\Throwable $e) {
            $this->removeTree($stage);
            throw $e;
        } finally {
            @unlink($zip);
            $this->removeTree($extract);
        }
    }

    private function writeStatus(array $data): void
    {
        @file_put_contents($this->statusFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function managedPaths(): array
    {
        return ['app','config','database','public','views','scripts','VERSION','README.md','install-tms-os.sh','nginx.example.conf','.gitignore'];
    }

    private function download(string $url, string $target): void
    {
        if ($url === '') throw new RuntimeException('Release không có URL tải xuống.');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL chưa được cài.');
        $fp = fopen($target, 'wb');
        if (!$fp) throw new RuntimeException('Không tạo được file cập nhật tạm.');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'User-Agent: TMS-AI-Router/' . $this->base->currentVersion()],
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
        if (!is_dir($dst) && !mkdir($dst, 0700, true) && !is_dir($dst)) {
            throw new RuntimeException('Không tạo được ' . $dst);
        }
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
