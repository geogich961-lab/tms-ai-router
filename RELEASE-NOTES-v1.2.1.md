# TMS AI Router v1.2.1

Hotfix cho Dashboard/Update Center trên TMS OS.

- Thêm endpoint PHP trực tiếp `public/router-api.php` để Dashboard không còn phụ thuộc rewrite, query string hoặc custom action header.
- Toàn bộ action nội bộ gửi `_action` và `_csrf` trong JSON body.
- Sửa lỗi `HTTP 200` nhưng trả HTML khiến Update Center báo `Máy chủ không trả JSON hợp lệ`.
- Giữ nguyên fallback GitHub API → RELEASE.json → raw VERSION của v1.2.0.
- Không thay `storage/`, SQLite hoặc master key khi hot update.
