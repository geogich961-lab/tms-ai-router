# TMS AI Router

TMS AI Router là AI gateway nhẹ chạy độc lập bằng **PHP 8 + SQLite + Vanilla JS**.
Dự án được thiết kế để chạy tốt trên TMS OS/Termux, Ubuntu/Debian, Nginx hoặc Apache.

## Tính năng v1.0.1

- Dashboard theo dõi request, input/output/total token.
- Quản lý nhiều provider/account.
- OpenAI-compatible gateway:
  - `GET /v1/models`
  - `POST /v1/chat/completions`
- Routing: priority, round-robin, least-used, quota-first.
- Fallback tự động khi provider lỗi/429/5xx.
- API key nội bộ dạng `tms_...`, chỉ lưu hash SHA-256.
- Quota window: 5 giờ, ngày, tuần, tháng hoặc custom.
- Countdown reset trực tiếp trên dashboard.
- Theo dõi token từng provider/account.
- SQLite WAL + busy timeout.
- Provider secret được mã hóa bằng Sodium hoặc OpenSSL.
- Không lưu prompt/response đầy đủ mặc định.
- Không cần Composer, Node.js, Docker.
- Có `public/install.php` để cài trực tiếp bằng trình duyệt khi upload như website bình thường.
- Có `.htaccess` cho Apache front-controller.

## Cài như một website bình thường trên TMS OS

Đây là cách đơn giản nhất nếu bạn đang quản lý TMS OS từ xa qua giao diện web.

1. Tải source của repo và upload toàn bộ vào một thư mục, ví dụ:

```text
~/websites/tms-ai-router/
```

2. Trong mục **Website** của TMS OS, tạo website mới và đặt **Document Root** vào:

```text
~/websites/tms-ai-router/public
```

3. Website cần dùng PHP 8.x và có các extension:

```text
SQLite3
cURL
Sodium hoặc OpenSSL
```

4. Mở domain/URL của website. Nếu chưa cài, TMS AI Router sẽ tự chuyển tới:

```text
/install.php
```

5. `install.php` sẽ tự:

- Kiểm tra PHP >= 8.0.
- Kiểm tra SQLite3, cURL và Sodium/OpenSSL.
- Kiểm tra các file hệ thống bắt buộc.
- Tạo `storage/`, `storage/secure/`, `storage/logs/`, `storage/cache/`.
- Khởi tạo SQLite database và toàn bộ schema.
- Tạo tài khoản Admin đầu tiên.
- Đăng nhập Admin tự động sau khi cài xong.
- Tạo `storage/install.lock` để ghi nhận thông tin cài đặt.

Sau khi hoàn tất, mở Dashboard tại `/`.

> Bảo mật: Document Root phải là thư mục `public/`. Không trỏ website trực tiếp vào thư mục gốc của project vì `config/`, `database/`, `storage/` và mã nguồn backend không nên public trực tiếp.

## Cài trực tiếp vào TMS OS bằng Termux

Nếu có quyền truy cập Termux của máy Android đang chạy TMS OS, có thể chạy đúng 1 lệnh:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/geogich961-lab/tms-ai-router/main/install-tms-os.sh)
```

Installer tự xác nhận TMS OS, kiểm tra PHP 8 + SQLite3 + cURL + Sodium/OpenSSL, tải source, cài vào `~/websites/tms-ai-router`, giữ nguyên `storage` khi cập nhật, chọn port trống từ `8788`, nhận diện `fastcgi`/`php-http`, tạo Nginx site, chạy `nginx -t`, rollback cấu hình nếu lỗi, reload Nginx, kiểm tra `/health` và cuối cùng in URL LAN cho bạn mở Dashboard.

Muốn ép port riêng, ví dụ `8899`:

```bash
TMS_AI_ROUTER_PORT=8899 bash <(curl -fsSL https://raw.githubusercontent.com/geogich961-lab/tms-ai-router/main/install-tms-os.sh)
```

## Cài trên Linux/Nginx thông thường

```bash
git clone https://github.com/geogich961-lab/tms-ai-router.git
cd tms-ai-router
chmod +x scripts/install.sh
./scripts/install.sh
```

Sau đó trỏ document root của website vào:

```text
/path/to/tms-ai-router/public
```

Nginx cần dùng front controller (`try_files ... /index.php`) để các route như `/v1/models` và `/v1/chat/completions` đi vào `public/index.php`.

## Sử dụng API

Tạo Client API Key trong dashboard, sau đó cấu hình ứng dụng AI:

```text
Base URL: https://domain-cua-ban/v1
API Key:  tms_xxxxxxxxx
```

## Provider

Bản v1 hỗ trợ trực tiếp các endpoint có giao thức OpenAI-compatible, ví dụ OpenAI, OpenRouter và nhiều gateway/self-hosted khác.

Anthropic/Gemini native adapter, Responses API translation, Embeddings/TTS/STT/Image/Video/Web Search được chừa kiến trúc để mở rộng ở các phiên bản sau.

## Bảo mật

- Không commit `storage/`.
- Provider API key được mã hóa.
- Client API key chỉ lưu hash.
- Prompt/response không lưu mặc định.
- Admin POST dùng CSRF.
- Hãy dùng HTTPS khi expose ra Internet.
- Website chỉ nên expose thư mục `public/`.
