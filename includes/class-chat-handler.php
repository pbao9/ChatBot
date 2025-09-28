<?php
/**
 * Chat Handler for BARO AI Chatbot
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Chat_Handler {
  private $database;
  private $ai_service;
  private $telegram;

  public function __construct() {
    $this->database = new Baro_AI_Database();
    $this->ai_service = new Baro_AI_Service();
    $this->telegram = new Baro_AI_Telegram();
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
      error_log("BARO AI Chat Handler: " . $message);
    }
  }

  /**
   * Rate limiting key
   */
  private function rate_limit_key() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return 'baro_ai_rl_' . md5($ip);
  }

  /**
   * Check rate limit
   */
  private function check_rate_limit() {
    $k = $this->rate_limit_key();
    $data = get_transient($k);
    
    if (!$data) {
      set_transient($k, ['c' => 1, 't' => time()], 60);
      return true;
    }
    
    if (($data['c'] ?? 0) >= 20) {
      return false;
    }
    
    $data['c'] = ($data['c'] ?? 0) + 1;
    set_transient($k, $data, 60);
    return true;
  }

  /**
   * Get context from products
   */
  private function get_context_from_products($query) {
    $keywords = explode(' ', str_replace(['-', '_'], ' ', strtolower($query)));
    $products = $this->database->search_products($keywords);
    
    if (empty($products)) {
      return '';
    }

    $context = "=== DỮ LIỆU SẢN PHẨM & DỊCH VỤ ===\n";
    foreach ($products as $p) {
      $context .= "Tên: {$p->name}\nLoại: {$p->category}\nGiá: {$p->price}\n";
      if (!empty($p->sale_price)) {
        $context .= "Giá khuyến mãi: {$p->sale_price}\n";
      }
      $context .= "Cấu hình: {$p->config}\nMô tả: {$p->description}\n---\n";
    }
    
    return $context;
  }

  /**
   * Extract and save lead
   */
  private function extract_and_save_lead($message, $current_page_url = '') {
    // Use AI extraction for better accuracy
    $customer_info = $this->ai_service->extract_customer_info($message);
    
    $name = $customer_info['name'];
    $phone = $customer_info['phone'];
    $email = $customer_info['email'];
    $receiver_name = $customer_info['receiver_name'];

    $this->debug_log("Extracted customer info - Name: '$name', Phone: '$phone', Email: '$email', Receiver: '$receiver_name'");

    // If no phone or email is found, it's not a lead
    if (empty($phone) && empty($email)) {
      $this->debug_log("No phone or email found, not saving as lead");
      return false;
    }

    $result = $this->database->insert_lead([
      'name' => $name,
      'phone' => $phone,
      'email' => $email,
      'receiver_name' => $receiver_name,
      'message' => $message,
      'current_page_url' => $current_page_url
    ]);

    if ($result === false) {
      $this->debug_log("Failed to insert lead into database");
    } else {
      $this->debug_log("Successfully inserted lead");
    }

    // Send Telegram notification
    $telegram_sent = $this->telegram->send_lead_notification($name, $phone, $email, $message, $receiver_name, $current_page_url);
    $this->debug_log("Telegram notification sent: " . ($telegram_sent ? 'Yes' : 'No'));

    return true;
  }

  /**
   * Handle chat request
   */
  public function handle_chat(\WP_REST_Request $req) {
    // Rate limiting
    if (!$this->check_rate_limit()) {
      return new \WP_REST_Response(['error' => 'Too many requests'], 429);
    }

    // Input validation
    $body = $req->get_json_params();
    if (!is_array($body)) {
      return new \WP_REST_Response(['error' => 'Invalid request format'], 400);
    }

    $user_msg = trim(sanitize_text_field($body['message'] ?? ''));
    $is_form_submission = isset($body['is_form_submission']) && $body['is_form_submission'];
    $current_page_url = isset($body['current_page_url']) ? esc_url_raw($body['current_page_url']) : '';
    
    if ($user_msg === '') {
      return new \WP_REST_Response(['error' => 'Empty message'], 400);
    }

    // Handle form submission - save lead and return success without showing message
    if ($is_form_submission) {
      $this->extract_and_save_lead($user_msg, $current_page_url);
      return new \WP_REST_Response(['success' => true], 200);
    }

    // For regular messages, try to extract lead info
    if ($this->extract_and_save_lead($user_msg)) {
      // For regular lead extraction, show the thank you message
      return new \WP_REST_Response(['answer' => 'Cảm ơn bạn đã cung cấp thông tin. Chúng tôi sẽ liên hệ bạn lại trong thời gian sớm nhất!'], 200);
    }

    // Check if AI is configured
    if (!$this->ai_service->is_configured()) {
      return new \WP_REST_Response(['error' => 'Chưa cấu hình API key'], 500);
    }

    $settings = get_option('baro_ai_settings', []);
    $brand = $settings['brand'] ?? get_bloginfo('name');
    $kb = $settings['kb'] ?? '';

    // Get context from products or WordPress content
    $contextText = $this->get_context_from_products($user_msg);
    $urls = [];
    
    if (empty($contextText)) {
      list($contextText, $urls) = $this->ai_service->build_context($kb, $this->ai_service->find_snippets($user_msg, 4), $brand);
    } else {
      $contextText .= "\n" . $this->ai_service->build_context($kb, [], $brand)[0];
    }

    // Generate AI response
    $history = is_array($body['history'] ?? null) ? $body['history'] : [];
    $ai_response = $this->ai_service->generate_response($user_msg, $history, $contextText, $urls);

    if (!$ai_response['success']) {
      return new \WP_REST_Response(['error' => $ai_response['error']], 503);
    }

    $json = $ai_response['data'];
    $answer = $json['answer'] ?? 'Xin lỗi, hiện mình chưa có thông tin trong hệ thống.';
    $answer_formatted = $this->ai_service->convert_markdown_to_html($answer);

    // Validate sources if grounded
    if (($json['grounded'] ?? false) && !empty($json['sources']) && is_array($json['sources'])) {
      $hosts = $this->ai_service->get_site_hosts_whitelist();
      foreach ($json['sources'] as $u) {
        if (isset(parse_url($u)['host']) && !in_array(parse_url($u)['host'], $hosts, true)) {
          return new \WP_REST_Response(['answer' => 'Xin lỗi, câu trả lời chứa nguồn không hợp lệ.'], 200);
        }
      }
    }

    return new \WP_REST_Response([
      'answer' => wp_kses($answer_formatted, [
        'a' => ['href' => [], 'title' => [], 'target' => []],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'br' => [],
        'p' => [],
        'ul' => [],
        'ol' => [],
        'li' => []
      ])
    ], 200);
  }
}
