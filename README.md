# BARO AI Chatbot (Grounded)

**Author:** Baro  
**Website:** https://baro-dev.io.vn

---

## Mô tả

**BARO AI Chatbot (Grounded)** là plugin WordPress tạo chatbot AI tư vấn sản phẩm/dịch vụ cho website của bạn theo mô hình **Closed-Book** (chỉ trả lời dựa trên dữ liệu nội bộ). Plugin sử dụng OpenAI Chat Completions (ví dụ: `gpt-4o-mini`) hoặc có thể tùy chỉnh để gọi Gemini, với cơ chế **Grounding + RAG nhẹ** để đảm bảo độ chính xác và an toàn.

### Tính năng chính

- **Shortcode** `[ai_chatbot]` để hiển thị widget chat nổi trên trang.
- **Knowledge Base**: bạn tự nhập mô tả dịch vụ, chính sách, link sản phẩm; chatbot chỉ trả lời dựa trên nội dung này.
- **RAG nhẹ**: tự động truy vấn nội dung (post/page/product/faq) liên quan đến câu hỏi để đưa vào prompt, tăng khả năng trả lời đúng.
- **Grounded Validation**:
  - Bot trả về JSON có cấu trúc: `{"grounded":true/false, "answer":"...", "sources":[...]}`
  - Nếu không grounded hoặc nguồn không hợp lệ (không thuộc whitelist nội bộ), server sẽ trả fallback: “Xin lỗi… cho mình số/Zalo…”
- **Bảo mật & vận hành**:
  - Kiểm soát rate-limit (12 req/phút/IP).
  - Bắt buộc `X-WP-Nonce` để chống CSRF.
  - Không lộ API key (lưu server-side).
  - Sanitize đầu ra và chỉ cho phép HTML an toàn (links, in đậm, danh sách).
- **UI đơn giản, gọn**:
  - Nút chat nổi góc phải, hiệu ứng Pulse nhẹ nhàng.
  - Panel chat hiện đoạn hội thoại ngắn gọn.
  - Tự xử lý trạng thái “typing…”, hiển thị trả lời mượt.

---

## Cài đặt

1. Tải plugin ZIP và vào **WordPress Dashboard → Plugins → Add New → Upload Plugin** để cài đặt & kích hoạt.
2. Vào **Settings → BARO AI Chatbot**, cấu hình:
   - **API Key** (OpenAI).
   - **Model** (vd: `gpt-4o-mini`).
   - **Brand** (tên công ty/hệ thống).
   - **Knowledge Base** (nhập nội dung, link nội bộ, giá cả, chính sách).
3. Thêm shortcode `[ai_chatbot]` vào footer hoặc trang bạn muốn hiển thị chat.

---

## Cách hoạt động (flow)

1. Người dùng gửi câu hỏi → front-end (JS) gửi `POST /wp-json/baro-ai/v1/chat` cùng `message` + `history`.
2. Server:
   - Lấy KB và tìm snippets nội bộ theo `message` (RAG nhẹ).
   - Tạo prompt `system` chứa KB, snippet, và quy định lý strict.
   - Gọi OpenAI Chat Completions với `response_format: {"type":"json_object"}` ép model trả JSON.
   - Kiểm tra:
     - `grounded===true`
     - `sources` không rỗng và nằm trong whitelist (snippet URLs, homepage, hoặc `kb://internal`).
   - Nếu valid → trả `answer`; nếu không → trả fallback xin lưu liên hệ.
3. Front-end hiển thị bubble chat tương ứng.

---

## Tùy chọn nâng cấp

- **RAG vector (Embeddings)**: thay cho WP_Query, index nội dung bài viết với `text-embedding-3-small`, lưu vector, truy vấn cosine similarity để xây context chính xác hơn.
- **Switch sang Gemini API**: thay endpoint và payload, cú pháp tương ứng.
- **Intent Categories**: thêm trường `intent` như `meta`, `faq`, `product`; relax rules nếu intent là “meta” (ví dụ: chào hỏi, giới thiệu).
- **UI nâng cao**: thêm nút chuyển qua Zalo/Messenger, thu lead qua popup form, kết nối Contact Form 7 hoặc WooCommerce.

---

## Lưu ý & cảnh báo

- **ChatGPT Plus** không áp dụng cho API. Bạn cần nạp credit riêng ở [platform.openai.com](https://platform.openai.com) để dùng plugin.
- **Không cache HTML chứa shortcode** (nonce sẽ hết hạn).
- **Đảm bảo các permalinks và REST rewrite rules** hoạt động bình thường.

---

## Liên hệ

Nếu bạn cần hỗ trợ, hoặc muốn thêm tính năng (embedded RAG, Gemini, intent phân lớp…), cứ truy cập:

**baro-dev.io.vn**

Cám ơn bạn đã dùng AR (AI – đúng, grounded) chatbot của mình! Hy vọng giúp bạn tư vấn khách hàng hiệu quả hơn — **an toàn, tin cậy, có kiểm soát**.

---

