<?php
/**
 * AI Service for BARO AI Chatbot
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Service {
  private $settings;
  private $api_key;
  private $model;

  public function __construct() {
    $this->settings = get_option('baro_ai_settings', []);
    $this->api_key = $this->settings['api_key'] ?? '';
    $this->model = $this->settings['model'] ?? 'gemini-1.5-flash-latest';
  }

  /**
   * Check if debug logging is enabled
   */
  private function is_debug_enabled() {
    $node_env = getenv('NODE_ENV');
    return defined('WP_DEBUG') && WP_DEBUG && ($node_env === 'development');
  }

  /**
   * Log debug message if debug is enabled
   */
  private function debug_log($message) {
    if ($this->is_debug_enabled()) {
      error_log("BARO AI Service: " . $message);
    }
  }

  /**
   * Check if AI is configured
   */
  public function is_configured() {
    return !empty($this->api_key);
  }

  /**
   * Generate AI response
   */
  public function generate_response($user_message, $history = [], $context = '', $sources = []) {
    if (!$this->is_configured()) {
      return [
        'success' => false,
        'error' => 'Chưa cấu hình API key'
      ];
    }

    $brand = $this->settings['brand'] ?? get_bloginfo('name');
    $kb = $this->settings['kb'] ?? '';

    // Build system instruction
    $system = "Trợ lý AI của {$brand}. Chuyên: Cloud, Hosting, VPS, Email Marketing, Web Design, IT Support.\n\nQUY TẮC:\n1. CONTEXT có sẵn → JSON: {\"grounded\": true, \"answer\": \"...\", \"sources\": []}\n2. Câu hỏi chung → JSON: {\"grounded\": false, \"answer\": \"...\"}\n3. Cần tư vấn chi tiết → Yêu cầu Tên, SĐT, Email. JSON: {\"grounded\": false, \"request_contact\": true, \"answer\": \"...\"}\n4. Dùng Markdown, emoji, **in đậm**.\n\nCONTEXT:\n{$context}\n\nNGUỒN:\n" . implode("\n", $sources);

    // Build conversation history
    $contents = [];
    foreach ($history as $turn) {
      if (!empty($turn['user'])) {
        $contents[] = [
          'role' => 'user',
          'parts' => [['text' => wp_strip_all_tags($turn['user'])]]
        ];
      }
      if (!empty($turn['assistant'])) {
        $contents[] = [
          'role' => 'model',
          'parts' => [['text' => wp_strip_all_tags($turn['assistant'])]]
        ];
      }
    }
    $contents[] = [
      'role' => 'user',
      'parts' => [['text' => $user_message]]
    ];

    // Prepare API request
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";
    $payload = [
      'contents' => $contents,
      'system_instruction' => [
        'parts' => [['text' => $system]]
      ],
      'generationConfig' => [
        'temperature' => 0.2,
        'response_mime_type' => 'application/json'
      ]
    ];

    $this->debug_log("Sending request to Gemini API");

    $response = wp_remote_post($api_url, [
      'headers' => ['Content-Type' => 'application/json'],
      'timeout' => 30,
      'body' => wp_json_encode($payload),
      'blocking' => true
    ]);

    if (is_wp_error($response)) {
      $this->debug_log("API request failed: " . $response->get_error_message());
      return [
        'success' => false,
        'error' => 'Service temporarily unavailable'
      ];
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 400 || !is_array($data)) {
      $this->debug_log("API response error - Code: $code, Data: " . wp_json_encode($data));

      // User-friendly error messages
      if ($code === 429) {
        return [
          'success' => false,
          'error' => 'Hệ thống đang quá tải, vui lòng thử lại sau'
        ];
      } elseif ($code === 401 || $code === 403) {
        return [
          'success' => false,
          'error' => 'Lỗi cấu hình API, vui lòng liên hệ quản trị viên'
        ];
      } else {
        $msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : 'Dịch vụ tạm thời không khả dụng';
        return [
          'success' => false,
          'error' => $msg
        ];
      }
    }

    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $json = json_decode($content, true);

    if (!is_array($json)) {
      return [
        'success' => false,
        'error' => 'Xin lỗi, đã có lỗi xảy ra. Vui lòng thử lại.'
      ];
    }

    return [
      'success' => true,
      'data' => $json
    ];
  }

  /**
   * Extract customer information using AI
   */
  public function extract_customer_info($message) {
    if (!$this->is_configured()) {
      return $this->extract_customer_info_fallback($message);
    }

    $system_prompt = "Bạn là một AI chuyên trích xuất thông tin khách hàng từ tin nhắn. Nhiệm vụ của bạn là phân tích tin nhắn và trích xuất:
1. Tên khách hàng (nếu có)
2. Số điện thoại (nếu có) 
3. Email (nếu có)
4. Người tiếp nhận (nếu có - chỉ khi được đề cập rõ ràng trong tin nhắn)

Tin nhắn thường có format như: \"Tên: Nguyễn Văn A, SĐT: 0123456789, Email: test@example.com\"
Hoặc có thể có thêm: \"Người tiếp nhận: Anh B\" nếu được đề cập.

Trả về kết quả dưới dạng JSON với format:
{
  \"name\": \"Tên khách hàng hoặc null\",
  \"phone\": \"Số điện thoại hoặc null\", 
  \"email\": \"Email hoặc null\",
  \"receiver_name\": \"Tên người tiếp nhận hoặc null\"
}

Chỉ trả về JSON, không giải thích thêm.";

    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";
    $payload = [
      'contents' => [
        [
          'role' => 'user',
          'parts' => [['text' => $message]]
        ]
      ],
      'system_instruction' => [
        'parts' => [['text' => $system_prompt]]
      ],
      'generationConfig' => [
        'temperature' => 0.1,
        'response_mime_type' => 'application/json'
      ]
    ];

    $response = wp_remote_post($api_url, [
      'headers' => ['Content-Type' => 'application/json'],
      'timeout' => 15,
      'body' => wp_json_encode($payload)
    ]);

    if (is_wp_error($response)) {
      return $this->extract_customer_info_fallback($message);
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
      return $this->extract_customer_info_fallback($message);
    }

    $ai_result = json_decode($data['candidates'][0]['content']['parts'][0]['text'], true);
    if (!is_array($ai_result)) {
      return $this->extract_customer_info_fallback($message);
    }

    return [
      'name' => !empty($ai_result['name']) ? trim($ai_result['name']) : '',
      'phone' => !empty($ai_result['phone']) ? trim($ai_result['phone']) : '',
      'email' => !empty($ai_result['email']) ? trim($ai_result['email']) : '',
      'receiver_name' => !empty($ai_result['receiver_name']) ? trim($ai_result['receiver_name']) : ''
    ];
  }

  /**
   * Fallback customer info extraction using regex
   */
  private function extract_customer_info_fallback($message) {
    $phone = '';
    $email = '';
    $name = '';
    $receiver_name = '';

    // Extract phone number - improved pattern
    if (preg_match('/SĐT:\s*([0-9\s\-+]+)/ui', $message, $phone_matches)) {
      $phone = preg_replace('/[^0-9]/', '', $phone_matches[1]);
    } elseif (preg_match('/(0[3|5|7|8|9])([0-9]{8})/', $message, $phone_matches)) {
      $phone = $phone_matches[0];
    }

    // Extract email - improved pattern
    if (preg_match('/Email:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/ui', $message, $email_matches)) {
      $email = $email_matches[1];
    } elseif (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', $message, $email_matches)) {
      $email = $email_matches[0];
    }

    // Extract name - improved pattern
    if (preg_match('/Tên:\s*([^,]+)/ui', $message, $name_matches)) {
      $name = trim($name_matches[1]);
    } elseif (preg_match('/(tên tôi là|tên của tôi là|tên mình là|mình tên là)\s*([^\d\n,]+)/ui', $message, $name_matches)) {
      $name = trim($name_matches[2]);
    }

    // Extract receiver name
    if (preg_match('/Người tiếp nhận:\s*([^,]+)/ui', $message, $receiver_matches)) {
      $receiver_name = trim($receiver_matches[1]);
    }

    return [
      'name' => $name,
      'phone' => $phone,
      'email' => $email,
      'receiver_name' => $receiver_name
    ];
  }

  /**
   * Convert markdown to HTML
   */
  public function convert_markdown_to_html($text) {
    $text = htmlspecialchars($text, ENT_NOQUOTES);
    $text = preg_replace('/\n/', '<br />', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/^\*\s(.*?)$/m', '<li>$1</li>', $text);
    
    if (strpos($text, '<li>') !== false) {
      $text = '<ul>' . str_replace("\n", "", $text) . '</ul>';
    }
    
    return $text;
  }

  /**
   * Find content snippets from WordPress
   */
  public function find_snippets($query, $limit = 4) {
    $query = sanitize_text_field($query);
    $pt = ['post', 'page'];
    
    if (post_type_exists('product')) $pt[] = 'product';
    if (post_type_exists('faq')) $pt[] = 'faq';
    
    $args = [
      's' => $query,
      'post_type' => $pt,
      'post_status' => 'publish',
      'posts_per_page' => $limit,
      'orderby' => 'relevance',
      'no_found_rows' => true,
      'suppress_filters' => true
    ];
    
    $q = new \WP_Query($args);
    $snips = [];
    
    if ($q->have_posts()) {
      while ($q->have_posts()) {
        $q->the_post();
        $content = get_post_field('post_content', get_the_ID());
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\n/', ' ', $content);
        $excerpt = mb_substr($content, 0, 700);
        $snips[] = [
          'title' => get_the_title(),
          'url' => get_permalink(),
          'text' => $excerpt
        ];
      }
      wp_reset_postdata();
    }
    
    return $snips;
  }

  /**
   * Build context from knowledge base and snippets
   */
  public function build_context($kb, $snips, $brand) {
    $kb = wp_strip_all_tags($kb ?? '');
    $identity = "Thông tin về tôi: Tôi là trợ lý ảo thông minh, đại diện tư vấn cho thương hiệu {$brand}.";
    $ctx = "=== KNOWLEDGE BASE ===\n" . $identity . "\n\n" . $kb . "\n\n";
    
    if (!empty($snips)) {
      $ctx .= "=== INTERNAL SNIPPETS (ALLOWED SOURCES ONLY) ===\n";
      $urls = [];
      foreach ($snips as $i => $s) {
        $title = wp_strip_all_tags($s['title']);
        $url = esc_url_raw($s['url']);
        $text = wp_strip_all_tags($s['text']);
        $ctx .= "- [$title] <$url>\n  \"$text\"\n";
        $urls[] = $url;
      }
    }
    
    return [$ctx, $urls ?? []];
  }

  /**
   * Get site hosts whitelist
   */
  public function get_site_hosts_whitelist() {
    $host = parse_url(home_url(), PHP_URL_HOST);
    return array_unique([$host, 'www.' . $host]);
  }
}
