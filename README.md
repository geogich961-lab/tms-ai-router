# TMS AI Router

TMS AI Router là AI gateway nhẹ chạy độc lập bằng **PHP 8 + SQLite + Vanilla JS**, tối ưu cho TMS OS/Termux nhưng cũng chạy được trên Linux/Nginx hoặc Apache.

## Tính năng v1.2.0

- Dashboard sidebar tối ưu desktop và mobile.
- Theo dõi request, input/output/total token theo provider.
- Quota window 5 giờ, ngày, tuần, tháng hoặc custom, có countdown reset trực tiếp.
- OpenAI-compatible gateway: `GET /v1/models`, `POST /v1/chat/completions`, `POST /v1/responses`.
- SSE streaming cho `/v1/chat/completions` với provider OpenAI-compatible.
- Provider native: OpenAI-compatible, Anthropic Messages API và Google Gemini `generateContent`.
- Routing: priority, round-robin, least-used, quota-first.
- Fallback khi provider lỗi/429/5xx.
- Client API key dạng `tms_...`, chỉ lưu hash SHA-256.
- Provider secret được mã hóa bằng Sodium hoặc OpenSSL.
- Dashboard AJAX dùng **rewrite-safe root transport** qua `POST /` + `X-TMS-Action`, tránh lỗi nested `/admin/api/...` trên TMS OS/Nginx/Cloudflare.
- **Hot Update v1.2.0** có ba đường kiểm tra GitHub: Releases API → `RELEASE.json` → raw `VERSION`.
- Release chính thức có `TMS_AI_ROUTER.zip` + SHA-256; updater xác minh checksum trước khi thay code.
- Trước update, code được backup vào `storage/backups/`; nếu copy/deploy lỗi thì tự rollback source.
- `storage/`, SQLite, master key và API key không bị thay thế khi hot update.
- Không cần Composer, Node.js hoặc Docker.

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

## Nâng cấp lên v1.2.0

Nếu đang dùng v1.1.2 hoặc thấp hơn và Update Center hiện không kiểm tra GitHub được, hãy upload source v1.2.0 và ghi đè code **một lần**, nhưng giữ nguyên toàn bộ thư mục `storage/`.

Sau khi lên v1.2.0, vào:

```text
Dashboard → Updates → Kiểm tra cập nhật → Cập nhật ngay
```

Từ v1.2.0, updater ưu tiên gói release `TMS_AI_ROUTER.zip`, kiểm tra SHA-256, backup source trước khi deploy và rollback source nếu có lỗi. Updater không restart Nginx, PHP hoặc Cloudflare Tunnel.

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

OpenAI-compatible Base URL ví dụ:

```text
https://api.openai.com/v1
https://openrouter.ai/api/v1
```

Anthropic native Base URL:

```text
https://api.anthropic.com
```

Gemini native Base URL:

```text
https://generativelanguage.googleapis.com
```

Model mapping hỗ trợ:

```text
gpt-4o-mini
claude-sonnet=claude-3-7-sonnet-latest
gemini-flash=gemini-2.5-flash
```

## Streaming

v1.2.0 hỗ trợ SSE streaming cho provider **OpenAI-compatible** qua `/v1/chat/completions`. Anthropic/Gemini native hiện chạy non-stream.

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
