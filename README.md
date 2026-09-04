# TMS AI Router

TMS AI Router là AI gateway nhẹ chạy độc lập bằng **PHP 8 + SQLite + Vanilla JS**.
Dự án được thiết kế để chạy tốt trên TMS OS/Termux, Ubuntu/Debian, Nginx hoặc Apache.

## Tính năng v1.0.0

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

## Cài trực tiếp vào TMS OS

Trên Termux của máy Android đang chạy TMS OS, chạy đúng **1 lệnh**:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/geogich961-lab/tms-ai-router/main/install-tms-os.sh)
```

Installer sẽ tự:

- xác nhận máy đã có TMS OS;
- kiểm tra PHP 8, SQLite3, cURL và Sodium/OpenSSL;
- tải source mới nhất;
- cài vào `~/websites/tms-ai-router`;
- giữ nguyên thư mục `storage` khi cập nhật;
- tự chọn port trống từ `8788` trở lên;
- nhận diện PHP engine `fastcgi` hoặc `php-http` của TMS OS;
- tạo `$PREFIX/etc/nginx/sites-enabled/tms-ai-router.conf`;
- chạy `nginx -t`, rollback cấu hình nếu lỗi;
- reload Nginx và kiểm tra `/health`;
- in URL LAN để mở Dashboard.

Có thể ép port khác:

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

Mở trang web và hoàn tất tài khoản quản trị đầu tiên.

## Sử dụng API

Tạo Client API Key trong dashboard, sau đó cấu hình ứng dụng AI:

```text
Base URL: http://IP-CUA-TMS-OS:PORT/v1
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
