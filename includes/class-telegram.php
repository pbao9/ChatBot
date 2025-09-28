<?php
/**
 * Telegram notifications for BARO AI Chatbot
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Telegram {
  private $settings;
  private $bot_token;
  private $chat_id;

  public function __construct() {
    $this->settings = get_option('baro_ai_settings', []);
    $this->bot_token = $this->settings['telegram_bot_token'] ?? '';
    $this->chat_id = $this->settings['telegram_chat_id'] ?? '';
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
      error_log("BARO AI Telegram: " . $message);
    }
  }

  /**
   * Check if Telegram is configured
   */
  public function is_configured() {
    return !empty($this->bot_token) && !empty($this->chat_id);
  }

  /**
   * Send new lead notification
   */
  public function send_lead_notification($name, $phone, $email, $message, $receiver_name = '', $current_page_url = '') {
    if (!$this->is_configured()) {
      $this->debug_log("Telegram not configured - Bot token or Chat ID missing");
      return false;
    }

    $this->debug_log("Attempting to send Telegram notification");

    $text = "🆕 *KHÁCH HÀNG TIỀM NĂNG MỚI* 🎉\n\n";
    $text .= "👤 *Tên:* " . ($name ?: '❌ Chưa cung cấp') . "\n";
    $text .= "📞 *SĐT:* " . ($phone ?: '❌ Chưa cung cấp') . "\n";
    $text .= "📧 *Email:* " . ($email ?: '❌ Chưa cung cấp') . "\n";
    
    if ($receiver_name) {
      $text .= "🎯 *Người tiếp nhận:* 👨‍💼 " . $receiver_name . "\n";
    }
    
    $text .= "💬 *Tin nhắn:* " . $message . "\n";
    $text .= "⏰ *Thời gian:* " . current_time('d/m/Y H:i:s') . "\n";
    $text .= "📊 *Trạng thái:* 🔴 Chưa liên hệ\n";
    $text .= "🌐 *Website:* " . get_bloginfo('name') . "\n";

    // Add current page URL if available
    if (!empty($current_page_url)) {
      $text .= "🔗 *Trang đang xem:* " . $current_page_url . "\n";
    }

    // Add registration link if configured
    $registration_link = $this->settings['registration_link'] ?? '';
    if (!empty($registration_link)) {
      $text .= "🔗 *Link đăng ứng:* " . $registration_link . "\n";
    }

    $text .= "\n💡 *Hành động:* Vui lòng liên hệ khách hàng sớm nhất!";

    return $this->send_message($text);
  }

  /**
   * Send admin action notification (status update, receiver update)
   */
  public function send_admin_action_notification($action_type, $lead, $new_value = '') {
    if (!$this->is_configured()) {
      $this->debug_log("Telegram not configured for admin notifications");
      return false;
    }

    // Status mapping with colors and emojis
    $status_options = [
      'chua_lien_he' => '🔴 Chưa liên hệ',
      'da_lien_he' => '🟡 Đã liên hệ',
      'dang_tu_van' => '🔵 Đang tư vấn',
      'da_chot_don' => '🟢 Đã chốt đơn'
    ];

    $text = "🔄 *CẬP NHẬT THÔNG TIN KHÁCH HÀNG*\n\n";
    $text .= "👤 *Tên:* " . ($lead->name ?: 'Chưa cung cấp') . "\n";
    $text .= "📞 *SĐT:* " . ($lead->phone ?: 'Chưa cung cấp') . "\n";
    $text .= "📧 *Email:* " . ($lead->email ?: 'Chưa cung cấp') . "\n";

    if ($action_type === 'status_update') {
      $status_display = $status_options[$new_value] ?? $new_value;
      $text .= "📊 *Trạng thái mới:* " . $status_display . "\n";
      $text .= "⏰ *Cập nhật lúc:* " . current_time('d/m/Y H:i:s') . "\n";

      // Add status-specific message
      switch ($new_value) {
        case 'chua_lien_he':
          $text .= "💡 *Ghi chú:* Khách hàng chưa được liên hệ\n";
          break;
        case 'da_lien_he':
          $text .= "✅ *Ghi chú:* Đã liên hệ thành công\n";
          break;
        case 'dang_tu_van':
          $text .= "🔄 *Ghi chú:* Đang trong quá trình tư vấn\n";
          break;
        case 'da_chot_don':
          $text .= "🎉 *Ghi chú:* Chúc mừng! Đã chốt đơn thành công\n";
          break;
      }
    } elseif ($action_type === 'receiver_update') {
      $receiver_display = $new_value ? "👨‍💼 " . $new_value : "❌ Chưa phân công";
      $text .= "🎯 *Người tiếp nhận:* " . $receiver_display . "\n";
      $text .= "⏰ *Cập nhật lúc:* " . current_time('d/m/Y H:i:s') . "\n";

      if ($new_value) {
        $text .= "✅ *Ghi chú:* Đã phân công người phụ trách\n";
      } else {
        $text .= "⚠️ *Ghi chú:* Chưa có người phụ trách\n";
      }
    }

    $text .= "🌐 *Website:* " . get_bloginfo('name');

    return $this->send_message($text);
  }

  /**
   * Send message to Telegram
   */
  private function send_message($text) {
    $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
    $data = [
      'chat_id' => $this->chat_id,
      'text' => $text,
      'parse_mode' => 'Markdown'
    ];

    $this->debug_log("Sending to Telegram URL: " . $url);
    $this->debug_log("Message: " . $text);

    $response = wp_remote_post($url, [
      'headers' => ['Content-Type' => 'application/json'],
      'body' => wp_json_encode($data),
      'timeout' => 10
    ]);

    if (is_wp_error($response)) {
      $this->debug_log("Telegram API error: " . $response->get_error_message());
      return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    $this->debug_log("Telegram response code: " . $response_code);
    $this->debug_log("Telegram response body: " . $response_body);

    return $response_code === 200;
  }

  /**
   * Test Telegram connection
   */
  public function test_connection() {
    if (!$this->is_configured()) {
      return [
        'success' => false,
        'message' => 'Telegram chưa được cấu hình. Vui lòng nhập Bot Token và Chat ID.'
      ];
    }

    $test_message = "🧪 *TEST CONNECTION*\n\n";
    $test_message .= "✅ Kết nối Telegram thành công!\n";
    $test_message .= "⏰ Thời gian: " . current_time('d/m/Y H:i:s') . "\n";
    $test_message .= "🌐 Website: " . get_bloginfo('name');

    $result = $this->send_message($test_message);

    if ($result) {
      return [
        'success' => true,
        'message' => 'Gửi tin nhắn test thành công!'
      ];
    } else {
      return [
        'success' => false,
        'message' => 'Gửi tin nhắn test thất bại. Vui lòng kiểm tra Bot Token và Chat ID.'
      ];
    }
  }
}
