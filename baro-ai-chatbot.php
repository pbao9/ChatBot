<?php
/**
 * Plugin Name: BARO AI Chatbot (Grounded)
 * Description: Chatbot AI tư vấn dựa trên Knowledge Base & nội dung nội bộ. Tự động thêm vào footer. Production-ready với error handling và security improvements.
 * Version: 1.9.0
 * Author: TGS Developers
 * Author URI: https://tgs.com.vn
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Load required classes
require_once plugin_dir_path(__FILE__) . 'includes/class-database.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-telegram.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ai-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-chat-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';

class Baro_AI_Chatbot_Grounded {
  const OPT_KEY = 'baro_ai_settings';

  private $database;
  private $chat_handler;
  private $admin;

  public function __construct($plugin_file) {
    // Initialize classes
    $this->database = new Baro_AI_Database();
    $this->chat_handler = new Baro_AI_Chat_Handler();
    $this->admin = new Baro_AI_Admin();

    // WordPress hooks
    add_action('wp_footer', [$this, 'render_chat_widget']);
    add_action('wp_enqueue_scripts', [$this, 'assets']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('admin_menu', [$this->admin, 'add_menu']);
    add_action('admin_init', [$this->admin, 'register_settings']);
    add_action('admin_post_baro_ai_save_product', [$this->admin, 'handle_product_form']);
    add_action('admin_init', [$this->admin, 'handle_product_actions']);
    add_action('wp_ajax_update_receiver_name', [$this->admin, 'ajax_update_receiver_name']);

    // Plugin lifecycle hooks
    register_activation_hook($plugin_file, [$this, 'activate']);
    register_deactivation_hook($plugin_file, [$this, 'deactivate']);

    // Add global error handlers for production
    $this->add_global_error_handlers();
  }

  /**
   * Add global error handlers for production stability
   */
  private function add_global_error_handlers()
  {
    $node_env = getenv('NODE_ENV');
    $is_debug = defined('WP_DEBUG') && WP_DEBUG && ($node_env === 'development');

    // Only add in production to prevent crashes
    if (!$is_debug) {
      set_error_handler([$this, 'handle_php_errors']);
      register_shutdown_function([$this, 'handle_fatal_errors']);
    }
  }

  /**
   * Handle PHP errors gracefully
   */
  public function handle_php_errors($severity, $message, $file, $line)
  {
    if (!(error_reporting() & $severity)) {
      return false;
    }
    error_log("BARO AI PHP Error: $message in $file on line $line");
    return true;
  }

  /**
   * Handle fatal errors
   */
  public function handle_fatal_errors()
  {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
      error_log("BARO AI Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}");
    }
  }

  /**
   * Plugin activation
   */
  public function activate() {
    $this->database->create_tables();
    $this->database->seed_sample_products();
  }

  /**
   * Plugin deactivation
   */
  public function deactivate() {
    // Reserved for future deactivation tasks
  }

  /**
   * Render chat widget
   */
  public function render_chat_widget() {
    wp_enqueue_script('baro-ai-chat');
    wp_enqueue_style('baro-ai-chat');
    $nonce = wp_create_nonce('wp_rest');
    $settings = get_option(self::OPT_KEY, []);
    $brand = isset($settings['brand']) ? esc_html($settings['brand']) : get_bloginfo('name');

    // Use custom title from settings or default
    if (!empty($settings['chatbot_title'])) {
      $title = $settings['chatbot_title'];
    } else {
      $title = 'Tư vấn ' . $brand . ' 💬';
    }
    $placeholder = 'Nhập câu hỏi về dịch vụ/sản phẩm...';
    ?>
    <div id="baro-ai-root" class="baro-ai-root" data-title="<?php echo esc_attr($title); ?>"
         data-placeholder="<?php echo esc_attr($placeholder); ?>" data-brand="<?php echo esc_attr($brand); ?>" v-cloak></div>
    <script>
      window.BARO_AI_CFG = {
        restBase: "<?php echo esc_js(esc_url_raw(trailingslashit(get_rest_url(null, 'baro-ai/v1')))); ?>",
        nonce: "<?php echo esc_js($nonce); ?>",
        pluginUrl: "<?php echo esc_js(plugin_dir_url(__FILE__)); ?>",
        popupGreeting: "<?php echo esc_js($settings['popup_greeting'] ?? 'Xin chào anh chị đã quan tâm tới Thế Giới Số!'); ?>",
        popupMessage: "<?php echo esc_js($settings['popup_message'] ?? 'Em có thể giúp gì cho Anh/Chị ạ?'); ?>",
        popupQuestions: "<?php echo esc_js($settings['popup_questions'] ?? ''); ?>",
        registrationLink: "<?php echo esc_js($settings['registration_link'] ?? ''); ?>"
      };
    </script>
    <?php
  }

  /**
   * Enqueue assets
   */
  public function assets() {
    $base = plugin_dir_url(__FILE__);
    wp_register_script('vue', 'https://unpkg.com/vue@3/dist/vue.global.js', [], '3.4.27', true);
    wp_register_script('baro-ai-chat', $base . 'assets/js/chat.js', ['vue'], '1.9.0', true);
    wp_register_style('baro-ai-chat', $base . 'assets/css/chat.css', [], '1.9.0');
  }

  /**
   * Register REST API routes
   */
  public function register_routes() {
    register_rest_route('baro-ai/v1', '/chat', [
      'methods' => 'POST',
      'callback' => [$this->chat_handler, 'handle_chat'],
      'permission_callback' => function () {
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

        if (!wp_verify_nonce($nonce, 'wp_rest')) {
          return false;
        }

        if (!empty($referer) && !wp_http_validate_url($referer)) {
          return false;
        }

        return true;
      },
      'args' => [
        'message' => [
          'required' => true,
          'type' => 'string',
          'sanitize_callback' => 'sanitize_text_field',
          'validate_callback' => function ($param, $request, $key) {
            return !empty(trim($param)) && strlen($param) <= 2000;
          }
        ]
      ]
    ]);
  }
}

// Initialize the plugin
new Baro_AI_Chatbot_Grounded(__FILE__);