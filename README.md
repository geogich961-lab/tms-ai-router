# TMS AI Router

TMS AI Router là AI gateway nhẹ chạy độc lập bằng **PHP 8 + SQLite + Vanilla JS**, tối ưu cho TMS OS/Termux nhưng cũng chạy được trên Linux/Nginx hoặc Apache.

## Tính năng v1.1.0

- Dashboard mới dạng sidebar, tối ưu desktop và mobile.
- Theo dõi request, input/output/total token theo provider.
- Quota window 5 giờ, ngày, tuần, tháng hoặc custom, có countdown reset trực tiếp.
- OpenAI-compatible gateway:
  - `GET /v1/models`
  - `POST /v1/chat/completions`
  - `POST /v1/responses`
- SSE streaming cho `POST /v1/chat/completions` với provider OpenAI-compatible.
- Provider native:
  - OpenAI-compatible / OpenRouter / self-hosted.
  - Anthropic Messages API.
  - Google Gemini `generateContent`.
- Routing: priority, round-robin, least-used, quota-first.
- Fallback khi provider lỗi/429/5xx.
- Client API key dạng `tms_...`, chỉ lưu hash SHA-256.
- Provider secret được mã hóa bằng Sodium hoặc OpenSSL.
- **Hot Update Center**: kiểm tra GitHub Releases và cập nhật trực tiếp trong Dashboard, giữ nguyên `storage/`, SQLite và master key.
- Không cần Composer, Node.js, Docker.

## Cài như website trên TMS OS

Upload toàn bộ source vào ví dụ:

```text
~/websites/tms-ai-router/
```

Trong **Website** của TMS OS, đặt Document Root vào:

```text
~/websites/tms-ai-router/public
```

Yêu cầu: PHP 8+, SQLite3, cURL và Sodium hoặc OpenSSL. Mở website lần đầu, Router sẽ chuyển đến `/install.php` để kiểm tra môi trường và tạo Admin.

> Document Root bắt buộc nên là `public/`; không expose trực tiếp `config/`, `database/` hoặc `storage/`.

## Nâng cấp từ v1.0.x lên v1.1.0

Bản v1.0.x chưa có Hot Update, vì vậy lần nâng cấp này cần thực hiện **một lần** bằng cách upload source v1.1.0 và ghi đè các thư mục code, nhưng **không xóa hoặc ghi đè thư mục `storage/`**.

Từ v1.1.0 trở đi, vào:

```text
Dashboard → Updates → Kiểm tra cập nhật → Cập nhật ngay
```

Hot updater sẽ:

- đọc release mới nhất từ `geogich961-lab/tms-ai-router`;
- tải source ZIP chính thức của GitHub Release;
- backup code cũ vào `storage/backups/`;
- giữ nguyên toàn bộ `storage/`, database và master key;
- thay source mới và reset OPcache khi có thể.

## API

Tạo Client API Key trong Dashboard rồi cấu hình client:

```text
Base URL: https://domain-cua-ban/v1
API Key:  tms_xxxxxxxxx
```

Các endpoint chính:

```text
GET  /v1/models
POST /v1/chat/completions
POST /v1/responses
```

## Provider

### OpenAI-compatible

Base URL ví dụ:

```text
https://api.openai.com/v1
https://openrouter.ai/api/v1
```

### Anthropic native

Base URL:

```text
https://api.anthropic.com
```

### Gemini native

Base URL:

```text
https://generativelanguage.googleapis.com
```

Model mapping hỗ trợ cú pháp:

```text
gpt-4o-mini
claude-sonnet=claude-3-7-sonnet-latest
gemini-flash=gemini-2.5-flash
```

## Streaming

v1.1.0 hỗ trợ SSE streaming cho provider **OpenAI-compatible** qua `/v1/chat/completions`. Anthropic/Gemini native hiện chạy non-stream và sẽ được mở rộng streaming native ở bản sau.

## Cài trực tiếp bằng Termux

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/geogich961-lab/tms-ai-router/main/install-tms-os.sh)
```

## Bảo mật

- Không commit `storage/`.
- Provider API key được mã hóa at-rest.
- Client API key chỉ lưu hash.
- Prompt/response không lưu mặc định.
- Admin POST dùng CSRF.
- Hot Update chỉ chạy sau khi đăng nhập Admin và xác thực CSRF.
- Hãy expose qua HTTPS khi truy cập từ Internet.
