<?php
/**
 * Plugin Name: BARO AI Chatbot (Grounded)
 * Description: Chatbot AI tư vấn dựa trên Knowledge Base & nội dung nội bộ. Tự động thêm vào footer.
 * Version: 1.7.0
 * Author: TGS Developers
 * Author URI: https://tgs.com.vn
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Chatbot_Grounded {
  const OPT_KEY = 'baro_ai_settings';

  public function __construct($plugin_file) {
    add_action('wp_footer', [$this, 'render_chat_widget']);
    add_action('wp_enqueue_scripts', [$this, 'assets']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('admin_menu', [$this, 'settings_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_post_baro_ai_save_product', [$this, 'handle_product_form']);
    add_action('admin_init', [$this, 'handle_product_actions']);
    add_action('wp_ajax_update_receiver_name', [$this, 'ajax_update_receiver_name']);
    register_activation_hook($plugin_file, [$this, 'activate']);
    register_deactivation_hook($plugin_file, [$this, 'deactivate']);
  }

  public function activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table_name_leads = $wpdb->prefix . 'baro_ai_leads';
    $sql_leads = "CREATE TABLE $table_name_leads (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        name varchar(100) DEFAULT '' NOT NULL,
        phone varchar(20) DEFAULT '' NOT NULL,
        email varchar(100) DEFAULT '' NOT NULL,
        message text NOT NULL,
        status varchar(20) DEFAULT 'chua_lien_he' NOT NULL,
        receiver_name varchar(100) DEFAULT '' NOT NULL,
        current_page_url varchar(500) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_leads);

    // Add status and receiver_name columns if they don't exist (for existing installations)
    $columns = $wpdb->get_col("DESCRIBE $table_name_leads");
    if (!in_array('status', $columns)) {
      $wpdb->query("ALTER TABLE $table_name_leads ADD COLUMN status varchar(20) DEFAULT 'chua_lien_he' NOT NULL");

      // Update existing records to have default status
      $wpdb->query("UPDATE $table_name_leads SET status = 'chua_lien_he' WHERE status IS NULL OR status = ''");

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Added status column to existing leads table");
      }
    }

    if (!in_array('receiver_name', $columns)) {
      $wpdb->query("ALTER TABLE $table_name_leads ADD COLUMN receiver_name varchar(100) DEFAULT '' NOT NULL");

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Added receiver_name column to existing leads table");
      }
    }

    if (!in_array('current_page_url', $columns)) {
      $wpdb->query("ALTER TABLE $table_name_leads ADD COLUMN current_page_url varchar(500) DEFAULT '' NOT NULL");

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Added current_page_url column to existing leads table");
      }
    }

    $table_name_products = $wpdb->prefix . 'baro_ai_products';
    $sql_products = "CREATE TABLE $table_name_products (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        category varchar(100) NOT NULL,
        price varchar(100) DEFAULT '' NOT NULL,
        sale_price varchar(100) DEFAULT '' NOT NULL,
        config text NOT NULL,
        description text NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_products);

    // Add sample data for server rental services
    $this->seed_sample_products();
  }

  public function deactivate() {
    // Reserved for future deactivation tasks.
  }

  public function render_chat_widget() {
    wp_enqueue_script('baro-ai-chat');
    wp_enqueue_style('baro-ai-chat');
    $nonce = wp_create_nonce('wp_rest');
    $settings = get_option(self::OPT_KEY, []);
    $brand = isset($settings['brand']) ? esc_html($settings['brand']) : get_bloginfo('name');
    $title = 'Hỗ trợ AI '.$brand. ' 🤖';
    $placeholder = 'Nhập câu hỏi về dịch vụ/sản phẩm...';
    ?>
    <div id="baro-ai-root" class="baro-ai-root" data-title="<?php echo esc_attr($title); ?>"
         data-placeholder="<?php echo esc_attr($placeholder); ?>" data-brand="<?php echo $brand; ?>" v-cloak></div>
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

  public function assets() {
    $base = plugin_dir_url(__FILE__);
    // Register Vue.js from a CDN
    wp_register_script('vue', 'https://unpkg.com/vue@3/dist/vue.global.js', [], '3.4.27', true);
    // Register our chat script with a dependency on Vue
    wp_register_script('baro-ai-chat', $base . 'assets/js/chat.js', ['vue'], '2.5.0', true);
    wp_register_style('baro-ai-chat', $base . 'assets/css/chat.css', [], '2.5.0');
  }

  public function register_routes() {
    register_rest_route('baro-ai/v1', '/chat', [
      'methods'  => 'POST',
      'callback' => [$this, 'handle_chat'],
      'permission_callback' => function() {
        $nonce = isset($_SERVER['HTTP_X_WP_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])) : '';
        return wp_verify_nonce($nonce, 'wp_rest');
      }
    ]);
  }

  private function rate_limit_key() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return 'baro_ai_rl_' . md5($ip);
  }

  private function check_rate_limit() {
    $k = $this->rate_limit_key();
    $data = get_transient($k);
    if (!$data) { set_transient($k, ['c'=>1,'t'=>time()], 60); return true; }
    if (($data['c'] ?? 0) >= 20) return false;
    $data['c'] = ($data['c'] ?? 0) + 1;
    set_transient($k, $data, 60);
    return true;
  }

  private function get_context_from_products($query) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_products';
    $keywords = explode(' ', str_replace(['-', '_'], ' ', strtolower($query)));
    $product_keywords = ['cloud', 'vps', 'hosting', 'email', 'marketing', 'workspace', 'it', 'support', 'web', 'development', 'giá', 'khuyến mãi', 'cấu hình'];
    $found_keywords = array_intersect($keywords, $product_keywords);
    if (empty($found_keywords)) return '';

    $sql = "SELECT * FROM $table_name WHERE ";
    $conditions = [];
    foreach ($found_keywords as $keyword) {
        $conditions[] = $wpdb->prepare("(name LIKE %s OR category LIKE %s OR description LIKE %s)", "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
    }
    $sql .= implode(' OR ', $conditions);
    $products = $wpdb->get_results($sql);
    if (empty($products)) return '';

    $context = "=== DỮ LIỆU SẢN PHẨM & DỊCH VỤ ===\n";
    foreach ($products as $p) {
        $context .= "Tên: {$p->name}\nLoại: {$p->category}\nGiá: {$p->price}\n";
        if (!empty($p->sale_price)) $context .= "Giá khuyến mãi: {$p->sale_price}\n";
        $context .= "Cấu hình: {$p->config}\nMô tả: {$p->description}\n---\n";
    }
    return $context;
  }

  private function convert_markdown_to_html($text) {
    $text = htmlspecialchars($text, ENT_NOQUOTES);
    $text = preg_replace('/
/', '<br />', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/^\*\s(.*?)$/m', '<li>$1</li>', $text);
    if (strpos($text, '<li>') !== false) {
        $text = '<ul>' . str_replace("\n", "", $text) . '</ul>';
    }
    return $text;
  }

  public function handle_chat(\WP_REST_Request $req) {
    if (!$this->check_rate_limit()) {
      return new \WP_REST_Response(['error'=>'Too many requests'], 429);
    }
    $body = $req->get_json_params();
    $user_msg = trim(sanitize_text_field($body['message'] ?? ''));
    $is_form_submission = isset($body['is_form_submission']) && $body['is_form_submission'];
    $current_page_url = isset($body['current_page_url']) ? esc_url_raw($body['current_page_url']) : '';
    
    if ($user_msg === '') {
      return new \WP_REST_Response(['error'=>'Empty message'], 400);
    }

    // Handle form submission - save lead and return success without showing message
    if ($is_form_submission) {
      // For form submissions, always save the lead and send Telegram notification
      $this->extract_and_save_lead($user_msg, $current_page_url);
      return new \WP_REST_Response(['success' => true], 200);
    }

    // For regular messages, try to extract lead info
    if ($this->extract_and_save_lead($user_msg)) {
      // For regular lead extraction, show the thank you message
      return new \WP_REST_Response(['answer' => 'Cảm ơn bạn đã cung cấp thông tin. Chúng tôi sẽ liên hệ bạn lại trong thời gian sớm nhất!'], 200);
    }
    $settings = get_option(self::OPT_KEY, []);
    $api_key  = $settings['api_key'] ?? '';
    if (!$api_key) {
      return new \WP_REST_Response(['error'=>'Chưa cấu hình API key'], 500);
    }
    $brand = $settings['brand'] ?? get_bloginfo('name');
    $kb = $settings['kb'] ?? '';
    $contextText = $this->get_context_from_products($user_msg);
    $urls = [];
    if (empty($contextText)) {
        list($contextText, $urls) = $this->build_context($kb, $this->find_snippets($user_msg, 4), $brand);
    } else {
        $contextText .= "\n" . $this->build_context($kb, [], $brand)[0];
    }
    $system = "Trợ lý AI của {$brand}. Chuyên: Cloud, Hosting, VPS, Email Marketing, Web Design, IT Support.\n\nQUY TẮC:\n1. CONTEXT có sẵn → JSON: {\"grounded\": true, \"answer\": \"...\", \"sources\": []}\n2. Câu hỏi chung → JSON: {\"grounded\": false, \"answer\": \"...\"}\n3. Cần tư vấn chi tiết → Yêu cầu Tên, SĐT, Email. JSON: {\"grounded\": false, \"request_contact\": true, \"answer\": \"...\"}\n4. Dùng Markdown, emoji, **in đậm**.\n\nCONTEXT:\n{$contextText}\n\nNGUỒN:\n" . implode("\n", $urls);
    $history  = is_array($body['history'] ?? null) ? $body['history'] : [];
    $contents = [];
    foreach ($history as $turn) {
        if (!empty($turn['user'])) $contents[] = ['role' => 'user', 'parts' => [['text' => wp_strip_all_tags($turn['user'])]]];
        if (!empty($turn['assistant'])) $contents[] = ['role' => 'model', 'parts' => [['text' => wp_strip_all_tags($turn['assistant'])]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $user_msg]]];
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$settings['model']}:generateContent?key={$api_key}";
    $payload = ['contents' => $contents, 'system_instruction' => ['parts' => [['text' => $system]]], 'generationConfig' => ['temperature' => 0.2, 'response_mime_type' => 'application/json']];
    $resp = wp_remote_post($api_url, ['headers' => ['Content-Type'  => 'application/json'], 'timeout' => 40, 'body' => wp_json_encode($payload)]);
    if (is_wp_error($resp)) return new \WP_REST_Response(['error'=>$resp->get_error_message()], 500);
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code >= 400 || !is_array($data)) {
      $msg = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : 'Gemini API error';
      return new \WP_REST_Response(['error'=> $msg], 500);
    }
    $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $json = json_decode($content, true);
    if (!is_array($json)) return new \WP_REST_Response(['answer' => 'Xin lỗi, đã có lỗi xảy ra. Vui lòng thử lại.'], 200);
    $answer = $json['answer'] ?? 'Xin lỗi, hiện mình chưa có thông tin trong hệ thống.';
    $answer_formatted = $this->convert_markdown_to_html($answer);
    if (($json['grounded'] ?? false) && !empty($json['sources']) && is_array($json['sources'])) {
        $hosts = $this->get_site_hosts_whitelist();
        foreach ($json['sources'] as $u) {
            if (isset(parse_url($u)['host']) && !in_array(parse_url($u)['host'], $hosts, true)) {
                 return new \WP_REST_Response(['answer' => 'Xin lỗi, câu trả lời chứa nguồn không hợp lệ.'], 200);
            }
        }
    }
    return new \WP_REST_Response(['answer' => wp_kses($answer_formatted, ['a'=>['href'=>[],'title'=>[],'target'=>[]],'strong'=>[],'b'=>[],'em'=>[],'i'=>[],'br'=>[],'p'=>[],'ul'=>[],'ol'=>[],'li'=>[]])], 200);
  }

  public function settings_menu() {
    add_menu_page('BARO AI Chatbot', 'BARO AI Chatbot', 'manage_options', 'baro-ai-chatbot', [$this, 'settings_page'], 'dashicons-format-chat', 30);
    add_submenu_page('baro-ai-chatbot', 'Cài đặt Chatbot', 'Cài đặt', 'manage_options', 'baro-ai-chatbot', [$this, 'settings_page']);
    add_submenu_page('baro-ai-chatbot', 'Khách hàng tiềm năng', 'Khách hàng tiềm năng', 'manage_options', 'baro-ai-leads', [$this, 'leads_page']);
    add_menu_page('Dịch vụ & Sản phẩm', 'Dịch vụ & Sản phẩm', 'manage_options', 'baro-ai-products', [$this, 'product_admin_page'], 'dashicons-archive', 31);
  }

  public function handle_product_actions() {
    if (!isset($_GET['page']) || !current_user_can('manage_options'))
      return;
    $action = $_GET['action'] ?? '';

    // Handle product actions
    if ($_GET['page'] === 'baro-ai-products') {
      if ($action === 'delete' && !empty($_GET['id'])) {
        $product_id = absint($_GET['id']);
        if (check_admin_referer('baro_ai_delete_product_' . $product_id)) {
          global $wpdb;
          $wpdb->delete($wpdb->prefix . 'baro_ai_products', ['id' => $product_id], ['%d']);
          wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=deleted'));
          exit;
        }
      }
      if ($action === 'seed_definitions') {
        if (check_admin_referer('baro_ai_seed_definitions_nonce')) {
          $this->seed_definitions_into_db();
          wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=seeded'));
          exit;
        }
      }
      if ($action === 'update_database') {
        if (check_admin_referer('baro_ai_update_database_nonce')) {
          $this->update_database_schema();
          wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=database_updated'));
          exit;
        }
      }
    }

    // Handle leads actions
    if ($_GET['page'] === 'baro-ai-leads') {
      if ($action === 'delete' && !empty($_GET['id'])) {
        $lead_id = absint($_GET['id']);
        if (check_admin_referer('baro_ai_delete_lead_' . $lead_id)) {
          global $wpdb;
          $wpdb->delete($wpdb->prefix . 'baro_ai_leads', ['id' => $lead_id], ['%d']);
          wp_redirect(admin_url('admin.php?page=baro-ai-leads&feedback=deleted'));
          exit;
        }
      }
      if ($action === 'update_status' && !empty($_GET['id']) && !empty($_GET['status'])) {
        $lead_id = absint($_GET['id']);
        $status = sanitize_text_field($_GET['status']);

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log("BARO AI: Updating lead status - ID: $lead_id, Status: $status");
        }

        if (check_admin_referer('baro_ai_update_lead_status_' . $lead_id) && in_array($status, ['chua_lien_he', 'da_lien_he', 'dang_tu_van', 'da_chot_don'])) {
          global $wpdb;
          $table_name = $wpdb->prefix . 'baro_ai_leads';

          // Get current lead info for Telegram notification
          $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $lead_id));

          // Check if status column exists, if not add it
          $columns = $wpdb->get_col("DESCRIBE $table_name");
          if (!in_array('status', $columns)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
              error_log("BARO AI: Status column missing, adding it");
            }
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN status varchar(20) DEFAULT 'chua_lien_he' NOT NULL");
          }

          $result = $wpdb->update($table_name, ['status' => $status], ['id' => $lead_id], ['%s'], ['%d']);

          if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BARO AI: Update result: " . ($result !== false ? $result : 'false'));
            if ($result === false) {
              error_log("BARO AI: Database error: " . $wpdb->last_error);
            }
          }

          if ($result !== false) {
            // Send Telegram notification
            $this->send_admin_action_telegram_notification('status_update', $lead, $status);
          }

          wp_redirect(admin_url('admin.php?page=baro-ai-leads&feedback=updated'));
          exit;
        } else {
          if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BARO AI: Nonce verification failed or invalid status");
          }
        }
      }
    }
  }

  private function update_database_schema()
  {
    global $wpdb;
    $table_name_leads = $wpdb->prefix . 'baro_ai_leads';

    // Check if status and receiver_name columns exist
    $columns = $wpdb->get_col("DESCRIBE $table_name_leads");
    if (!in_array('status', $columns)) {
      $wpdb->query("ALTER TABLE $table_name_leads ADD COLUMN status varchar(20) DEFAULT 'chua_lien_he' NOT NULL");

      // Update existing records
      $wpdb->query("UPDATE $table_name_leads SET status = 'chua_lien_he' WHERE status IS NULL OR status = ''");

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Database schema updated - added status column");
      }
    }

    if (!in_array('receiver_name', $columns)) {
      $wpdb->query("ALTER TABLE $table_name_leads ADD COLUMN receiver_name varchar(100) DEFAULT '' NOT NULL");

      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Database schema updated - added receiver_name column");
      }
    }
  }

  public function ajax_update_receiver_name()
  {
    // Check nonce and permissions
    if (!wp_verify_nonce($_POST['nonce'], 'baro_ai_update_receiver_name') || !current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }

    $lead_id = absint($_POST['lead_id']);
    $receiver_name = sanitize_text_field($_POST['receiver_name']);

    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_leads';

    // Get current lead info for Telegram notification
    $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $lead_id));

    $result = $wpdb->update(
      $table_name,
      ['receiver_name' => $receiver_name],
      ['id' => $lead_id],
      ['%s'],
      ['%d']
    );

    if ($result !== false) {
      // Send Telegram notification
      $this->send_admin_action_telegram_notification('receiver_update', $lead, $receiver_name);
      wp_send_json_success(['message' => 'Cập nhật thành công']);
    } else {
      wp_send_json_error(['message' => 'Cập nhật thất bại: ' . $wpdb->last_error]);
    }
  }

  public function handle_product_form() {
    if (!isset($_POST['baro_ai_product_nonce']) || !wp_verify_nonce($_POST['baro_ai_product_nonce'], 'baro_ai_save_product') || !current_user_can('manage_options')) return;
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_products';
    $data = ['name' => sanitize_text_field($_POST['name']), 'category' => sanitize_text_field($_POST['category']), 'price' => sanitize_text_field($_POST['price']), 'sale_price' => sanitize_text_field($_POST['sale_price']), 'config' => sanitize_textarea_field($_POST['config']), 'description' => sanitize_textarea_field($_POST['description'])];
    $id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if ($id > 0) $wpdb->update($table_name, $data, ['id' => $id]);
    else $wpdb->insert($table_name, $data);
    wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=saved'));
    exit;
  }

  public function product_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_products';
    $action = $_GET['action'] ?? 'list';
    $product_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if ($action === 'add' || $action === 'edit') {
        $product = null;
        if ($product_id > 0) $product = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $product_id));
        ?>
        <div class="wrap">
            <h1><?php echo $product ? 'Sửa sản phẩm' : 'Thêm sản phẩm mới'; ?></h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="baro_ai_save_product">
                <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
                <?php wp_nonce_field('baro_ai_save_product', 'baro_ai_product_nonce'); ?>
                <table class="form-table">
                    <tr><th><label for="name">Tên sản phẩm</label></th><td><input type="text" name="name" id="name" value="<?php echo esc_attr($product->name ?? ''); ?>" class="regular-text"></td></tr>
                    <tr><th><label for="category">Loại (Category)</label></th><td><input type="text" name="category" id="category" value="<?php echo esc_attr($product->category ?? ''); ?>" class="regular-text" placeholder="VD: VPS, Hosting,..."></td></tr>
                    <tr><th><label for="price">Giá gốc</label></th><td><input type="text" name="price" id="price" value="<?php echo esc_attr($product->price ?? ''); ?>" class="regular-text"></td></tr>
                    <tr><th><label for="sale_price">Giá khuyến mãi</label></th><td><input type="text" name="sale_price" id="sale_price" value="<?php echo esc_attr($product->sale_price ?? ''); ?>" class="regular-text"></td></tr>
                    <tr><th><label for="config">Cấu hình</label></th><td><textarea name="config" id="config" rows="5" class="large-text"><?php echo esc_textarea($product->config ?? ''); ?></textarea></td></tr>
                    <tr><th><label for="description">Mô tả</label></th><td><textarea name="description" id="description" rows="8" class="large-text"><?php echo esc_textarea($product->description ?? ''); ?></textarea></td></tr>
                </table>
                <?php submit_button($product ? 'Lưu thay đổi' : 'Thêm sản phẩm'); ?>
            </form>
        </div>
        <?php
        return;
    }
    $products = $wpdb->get_results("SELECT * FROM $table_name ORDER BY category, name ASC");
    ?>
                                <div class="wrap">
                                  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                                  <h1>Dịch vụ & Sản phẩm <a href="?page=baro-ai-products&action=add" class="page-title-action">Thêm mới</a> <a
                                      href="<?php echo wp_nonce_url('?page=baro-ai-products&action=seed_definitions', 'baro_ai_seed_definitions_nonce'); ?>"
                                  class="page-title-action">Thêm định nghĩa dịch vụ</a> <a
                                  href="<?php echo wp_nonce_url('?page=baro-ai-products&action=update_database', 'baro_ai_update_database_nonce'); ?>"
                                  class="page-title-action">Cập nhật Database</a></h1>
        <?php 
        if (!empty($_GET['feedback'])) {
            $feedback_msg = '';
            if ($_GET['feedback'] === 'saved') $feedback_msg = 'Đã lưu thành công.';
            if ($_GET['feedback'] === 'seeded') $feedback_msg = 'Đã thêm các định nghĩa dịch vụ mẫu thành công.';
            if ($_GET['feedback'] === 'deleted') $feedback_msg = 'Đã xóa thành công.';
          if ($_GET['feedback'] === 'database_updated')
            $feedback_msg = 'Đã cập nhật database thành công.';
            if ($feedback_msg) echo '<div class="notice notice-success is-dismissible"><p>' . $feedback_msg . '</p></div>';
        } 
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Tên sản phẩm</th><th>Loại</th><th>Giá</th><th>Hành động</th></tr></thead>
            <tbody>
            <?php if ($products): foreach ($products as $p):
                ?>
                <tr>
                    <td><strong><?php echo esc_html($p->name); ?></strong></td>
                    <td><?php echo esc_html($p->category); ?></td>
                    <td><?php echo esc_html($p->price); ?></td>
                    <td><a href="?page=baro-ai-products&action=edit&id=<?php echo $p->id; ?>">Sửa</a> | <a href="#"
                        onclick="deleteProduct(<?php echo $p->id; ?>, '<?php echo wp_create_nonce('baro_ai_delete_product_' . $p->id); ?>')"
                        style="color:red;">Xóa</a></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4">Chưa có sản phẩm nào.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <script>
    function deleteProduct(productId, nonce) {
        Swal.fire({
            title: "Xác nhận xóa",
            text: "Bạn có chắc muốn xóa sản phẩm này? Hành động này không thể hoàn tác!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Có, xóa!",
            cancelButtonText: "Hủy"
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: "Đang xóa...",
                    text: "Vui lòng chờ trong giây lát",
                    icon: "info",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                var url = "?page=baro-ai-products&action=delete&id=" + productId + "&_wpnonce=" + nonce;
                window.location.href = url;
            }
        });
    }
    
    // Show success/error messages from URL parameters
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const feedback = urlParams.get("feedback");
        
        if (feedback === "saved") {
            Swal.fire({
                title: "Thành công!",
                text: "Sản phẩm đã được lưu thành công",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        } else if (feedback === "seeded") {
            Swal.fire({
                title: "Thành công!",
                text: "Đã thêm các định nghĩa dịch vụ mẫu thành công",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        } else if (feedback === "deleted") {
            Swal.fire({
                title: "Đã xóa!",
                text: "Sản phẩm đã được xóa thành công",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        } else if (feedback === "database_updated") {
            Swal.fire({
                title: "Thành công!",
                text: "Database đã được cập nhật thành công",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        }
    });
    </script>
    <?php
  }

  private function seed_sample_products()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_products';

    // Sample server rental products
    $sample_products = [
      [
        'name' => 'Server Dell Xeon Gold 36 Cores',
        'category' => 'Server Dell',
        'price' => '3.700.000đ/tháng',
        'sale_price' => '1.999.000đ/tháng (24 tháng)',
        'config' => 'CPU: 2 x Intel Xeon Gold/Platinum - 36 Cores | 72 Threads, RAM: 64GB DDR4 ECC, Ổ cứng: 2 x 480GB Enterprise SSD, Băng thông: 100Mbps | Port 10/40Gbps, IP: 01 IPv4',
        'description' => '🔥 THUÊ SERVER DELL CẤU HÌNH KHỦNG – GIÁ SIÊU RẺ TẠI THẾ GIỚI SỐ. Hiệu năng vượt trội – Hoạt động ổn định – Giá tiết kiệm đến 40%. Data Center: Viettel & VNPT – Uptime 99.99%. Hỗ trợ kỹ thuật 24/7 – Phản hồi chỉ trong 15 phút! Phù hợp cho: Phần mềm ERP/CRM, AI/ML, Big Data, Web traffic cao, Render đồ họa, Tính toán chuyên sâu.'
      ],
      [
        'name' => 'VPS Cloud',
        'category' => 'VPS',
        'price' => 'Liên hệ',
        'sale_price' => '',
        'config' => 'CPU: 1-8 cores, RAM: 1-32GB, SSD: 20-500GB, Băng thông: 100Mbps-1Gbps',
        'description' => 'VPS Cloud linh hoạt, hiệu năng cao với khả năng mở rộng tài nguyên theo nhu cầu. Phù hợp cho website, ứng dụng web, phát triển phần mềm.'
      ],
      [
        'name' => 'Cloud Hosting',
        'category' => 'Hosting',
        'price' => 'Từ 99.000đ/tháng',
        'sale_price' => '',
        'config' => 'SSD: 1-100GB, Băng thông: Không giới hạn, Email: 1-1000 accounts',
        'description' => 'Cloud Hosting ổn định, tốc độ cao với uptime 99.9%. Phù hợp cho website doanh nghiệp, blog, thương mại điện tử.'
      ],
      [
        'name' => 'Email Marketing',
        'category' => 'Email Marketing',
        'price' => 'Từ 500.000đ/tháng',
        'sale_price' => '',
        'config' => 'Gửi: 10.000-1.000.000 email/tháng, Template: 100+ mẫu, Analytics: Chi tiết',
        'description' => 'Dịch vụ Email Marketing chuyên nghiệp với template đẹp, phân tích chi tiết, tỷ lệ gửi thành công cao.'
      ],
      [
        'name' => 'Thiết kế Website',
        'category' => 'Web Design',
        'price' => 'Từ 5.000.000đ',
        'sale_price' => '',
        'config' => 'Responsive Design, SEO Friendly, CMS, Bảo hành 12 tháng',
        'description' => 'Thiết kế website chuyên nghiệp, responsive, tối ưu SEO. Bao gồm: Giao diện đẹp, CMS dễ quản lý, bảo hành dài hạn.'
      ],
      [
        'name' => 'IT Support',
        'category' => 'IT Services',
        'price' => 'Từ 2.000.000đ/tháng',
        'sale_price' => '',
        'config' => 'Hỗ trợ 24/7, Bảo trì hệ thống, Cài đặt phần mềm, Backup dữ liệu',
        'description' => 'Dịch vụ IT Support chuyên nghiệp với đội ngũ kỹ thuật giàu kinh nghiệm. Hỗ trợ 24/7, bảo trì hệ thống, cài đặt phần mềm.'
      ]
    ];

    foreach ($sample_products as $product) {
      $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE name = %s", $product['name']));
      if (!$exists) {
        $wpdb->insert($table_name, $product);
      }
    }
  }

  private function seed_definitions_into_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_products';
    $definitions = [
        ['name' => 'VPS', 'category' => 'Định nghĩa chung', 'description' => 'Là một máy chủ ảo được tạo ra bằng cách chia một máy chủ vật lý thành nhiều máy chủ ảo độc lập. Mỗi VPS có hệ điều hành và tài nguyên (CPU, RAM, ổ cứng) riêng, hoạt động như một máy chủ riêng biệt với chi phí thấp hơn, phù hợp cho website có lượng truy cập lớn, máy chủ game, hoặc các dự án cần quyền quản trị cao.'],
        ['name' => 'Cloud Hosting', 'category' => 'Định nghĩa chung', 'description' => 'Là dịch vụ lưu trữ website trên một mạng lưới gồm nhiều máy chủ ảo hóa (đám mây). Dữ liệu được phân tán, giúp website có độ ổn định và thời gian hoạt động (uptime) rất cao, khả năng mở rộng tài nguyên linh hoạt, và bạn chỉ trả tiền cho những gì bạn sử dụng. Rất lý tưởng cho các trang thương mại điện tử và doanh nghiệp cần sự tin cậy.'],
        ['name' => 'Email Marketing', 'category' => 'Định nghĩa chung', 'description' => 'Là một chiến lược tiếp thị kỹ thuật số sử dụng email để quảng bá sản phẩm, dịch vụ và xây dựng mối quan hệ với khách hàng. Nó cho phép doanh nghiệp gửi thông điệp được cá nhân hóa đến đúng đối tượng, với chi phí thấp và khả năng đo lường hiệu quả chi tiết.'],
        ['name' => 'Google Workspace', 'category' => 'Định nghĩa chung', 'description' => 'Là bộ công cụ làm việc văn phòng và cộng tác trực tuyến của Google, bao gồm các ứng dụng như Gmail với tên miền riêng, Google Drive, Docs, Sheets, và Google Meet. Nó giúp các đội nhóm làm việc hiệu quả, linh hoạt từ mọi nơi và trên mọi thiết bị với độ bảo mật cao.'],
        ['name' => 'IT Support', 'category' => 'Định nghĩa chung', 'description' => 'Là dịch vụ hỗ trợ kỹ thuật công nghệ thông tin, chịu trách nhiệm giải quyết các sự cố, bảo trì và đảm bảo hệ thống máy tính, phần mềm, và mạng của một tổ chức hoạt động ổn định. Vai trò của IT Support là giúp người dùng khắc phục vấn đề kỹ thuật và duy trì hiệu suất công việc.'],
        ['name' => 'Web Development', 'category' => 'Định nghĩa chung', 'description' => 'Là công việc tạo ra các trang web và ứng dụng web. Quá trình này bao gồm hai phần chính: Front-end (giao diện người dùng nhìn thấy và tương tác) và Back-end (phần máy chủ xử lý logic và dữ liệu). Đây là một lĩnh vực kết hợp giữa thiết kế, lập trình và quản lý cơ sở dữ liệu.'],
    ];
    foreach ($definitions as $def) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE name = %s AND category = %s", $def['name'], $def['category']));
        if (!$exists) {
            $wpdb->insert($table_name, ['name' => $def['name'], 'category' => $def['category'], 'description' => $def['description'], 'price' => '', 'sale_price' => '', 'config' => '']);
        }
    }
  }

  public function leads_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_leads';

    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_items / $per_page);

    // Get leads for current page
    $leads = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
      $per_page,
      $offset
    ));

    // Status options
    $status_options = [
      'chua_lien_he' => 'Chưa liên hệ',
      'da_lien_he' => 'Đã liên hệ',
      'dang_tu_van' => 'Đang tư vấn',
      'da_chot_don' => 'Đã chốt đơn'
    ];

    // Status colors
    $status_colors = [
      'chua_lien_he' => '#dc3545',
      'da_lien_he' => '#ffc107',
      'dang_tu_van' => '#17a2b8',
      'da_chot_don' => '#28a745'
    ];

    echo '<div class="wrap">';
    echo '<h1>Khách hàng tiềm năng</h1>';
    echo '<p>Danh sách thông tin khách hàng thu thập được từ chatbot.</p>';

    // Add SweetAlert2 and ajaxurl
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>var ajaxurl = "' . admin_url('admin-ajax.php') . '";</script>';

    // Add custom CSS
    echo '<style>
    .receiver-name-cell:hover {
        background-color: #f0f0f0 !important;
    }
    .receiver-name-cell.editing {
        background-color: #fff3cd !important;
    }
    .tablenav {
        margin: 6px 0 4px;
        padding: 0;
        font-size: 13px;
        line-height: 2.15384615;
        color: #646970;
    }
    .tablenav-pages {
        float: right;
        margin: 0;
        text-align: right;
    }
    .tablenav-pages .button {
        margin-left: 2px;
        padding: 3px 8px;
        text-decoration: none;
        border: 1px solid #c3c4c7;
        background: #f6f7f7;
        color: #2c3338;
        border-radius: 2px;
    }
    .tablenav-pages .button:hover {
        background: #f0f0f1;
        border-color: #8c8f94;
    }
    .tablenav-pages .button:focus {
        box-shadow: 0 0 0 1px #2271b1;
        outline: 2px solid transparent;
    }
    .tablenav-pages .paging-input {
        margin: 0 6px;
        font-weight: 400;
    }
    .displaying-num {
        margin-right: 10px;
        font-style: italic;
    }
    </style>';

    // Show feedback messages
    if (!empty($_GET['feedback'])) {
      $feedback_msg = '';
      if ($_GET['feedback'] === 'deleted')
        $feedback_msg = 'Đã xóa khách hàng thành công.';
      if ($_GET['feedback'] === 'updated')
        $feedback_msg = 'Đã cập nhật trạng thái thành công.';
      if ($feedback_msg)
        echo '<div class="notice notice-success is-dismissible"><p>' . $feedback_msg . '</p></div>';
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th style="width:150px;">Thời gian</th><th>Tên</th><th>SĐT</th><th>Email</th><th>Người tiếp nhận</th><th style="width:120px;">Trạng thái</th><th>Trang đang xem</th><th>Tin nhắn gốc</th><th style="width:150px;">Hành động</th></tr></thead>';
    echo '<tbody>';

    if ($leads) {
        foreach ($leads as $lead) {
        $status = $lead->status ?? 'chua_lien_he';
        $status_text = $status_options[$status] ?? 'Chưa liên hệ';
        $status_color = $status_colors[$status] ?? '#dc3545';

        echo '<tr>';
        echo '<td>' . esc_html(date('d/m/Y H:i:s', strtotime($lead->created_at))) . '</td>';
        echo '<td>' . (isset($lead->name) ? esc_html($lead->name) : '') . '</td>';
        echo '<td><strong>' . esc_html($lead->phone) . '</strong></td>';
        echo '<td>' . (isset($lead->email) ? esc_html($lead->email) : '') . '</td>';
        echo '<td class="receiver-name-cell" data-lead-id="' . $lead->id . '" data-receiver-name="' . esc_attr($lead->receiver_name ?? '') . '" style="cursor: pointer; position: relative;">';
        echo '<span class="receiver-name-display">' . (isset($lead->receiver_name) && !empty($lead->receiver_name) ? esc_html($lead->receiver_name) : '-') . '</span>';
        echo '<input type="text" class="receiver-name-input" value="' . esc_attr($lead->receiver_name ?? '') . '" style="display: none; width: 100%; padding: 2px 5px; border: 1px solid #0073aa;">';
        echo '</td>';
        echo '<td><span style="color: ' . $status_color . '; font-weight: bold;">' . $status_text . '</span></td>';
        echo '<td>';
        if (!empty($lead->current_page_url)) {
          echo '<a href="' . esc_url($lead->current_page_url) . '" target="_blank" style="color: #0073aa; text-decoration: none; font-size: 12px;">' . esc_html($lead->current_page_url) . '</a>';
        } else {
          echo '-';
        }
        echo '</td>';
        echo '<td>' . esc_html($lead->message) . '</td>';
        echo '<td>';

        // Status dropdown
        echo '<select onchange="updateLeadStatus(' . $lead->id . ', this.value, \'' . wp_create_nonce('baro_ai_update_lead_status_' . $lead->id) . '\')" style="margin-bottom: 5px; width: 100%;">';
        foreach ($status_options as $key => $label) {
          $selected = ($key === $status) ? 'selected' : '';
          echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';

        // Delete button
        echo '<a href="#" onclick="deleteLead(' . $lead->id . ', \'' . wp_create_nonce('baro_ai_delete_lead_' . $lead->id) . '\')" style="color: red; text-decoration: none;">🗑️ Xóa</a>';

        echo '</td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="8">Chưa có dữ liệu.</td></tr>';
    }

    echo '</tbody></table>';

    // Pagination
    if ($total_pages > 1) {
      echo '<div class="tablenav">';
      echo '<div class="tablenav-pages">';
      echo '<span class="displaying-num">' . $total_items . ' mục</span>';

      $base_url = admin_url('admin.php?page=baro-ai-leads');

      // Previous page
      if ($current_page > 1) {
        echo '<a class="prev-page button" href="' . $base_url . '&paged=' . ($current_page - 1) . '">‹ Trước</a>';
      }

      // Page numbers
      $start_page = max(1, $current_page - 2);
      $end_page = min($total_pages, $current_page + 2);

      if ($start_page > 1) {
        echo '<a class="first-page button" href="' . $base_url . '&paged=1">1</a>';
        if ($start_page > 2) {
          echo '<span class="paging-input">…</span>';
        }
      }

      for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
          echo '<span class="paging-input"><span class="tablenav-paging-text">' . $i . ' / ' . $total_pages . '</span></span>';
        } else {
          echo '<a class="button" href="' . $base_url . '&paged=' . $i . '">' . $i . '</a>';
        }
      }

      if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
          echo '<span class="paging-input">…</span>';
        }
        echo '<a class="last-page button" href="' . $base_url . '&paged=' . $total_pages . '">' . $total_pages . '</a>';
      }

      // Next page
      if ($current_page < $total_pages) {
        echo '<a class="next-page button" href="' . $base_url . '&paged=' . ($current_page + 1) . '">Tiếp ›</a>';
      }

      echo '</div>';
      echo '</div>';
    }

    echo '</div>';

    // JavaScript for status update, delete, and inline editing
    echo '<script>
    // Inline editing for receiver name
    document.addEventListener("DOMContentLoaded", function() {
        const receiverCells = document.querySelectorAll(".receiver-name-cell");
        
        receiverCells.forEach(cell => {
            cell.addEventListener("dblclick", function() {
                const leadId = this.dataset.leadId;
                const displaySpan = this.querySelector(".receiver-name-display");
                const inputField = this.querySelector(".receiver-name-input");
                const originalValue = this.dataset.receiverName;
                
                // Hide display, show input
                displaySpan.style.display = "none";
                inputField.style.display = "block";
                this.classList.add("editing");
                inputField.focus();
                inputField.select();
                
                // Handle save on Enter or blur
                const saveEdit = () => {
                    const newValue = inputField.value.trim();
                    
                    if (newValue !== originalValue) {
                        // Show loading
                        inputField.style.background = "#f0f0f0";
                        inputField.disabled = true;
                        
                        // AJAX update
                        const formData = new FormData();
                        formData.append("action", "update_receiver_name");
                        formData.append("lead_id", leadId);
                        formData.append("receiver_name", newValue);
                        formData.append("nonce", "' . wp_create_nonce('baro_ai_update_receiver_name') . '");
                        
                        fetch(ajaxurl, {
                            method: "POST",
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update display and data attribute
                                displaySpan.textContent = newValue || "-";
                                this.dataset.receiverName = newValue;
                                
                                // Show success message
                                Swal.fire({
                                    title: "Thành công!",
                                    text: "Đã cập nhật người tiếp nhận",
                                    icon: "success",
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            } else {
                                // Show error and revert
                                Swal.fire({
                                    title: "Lỗi!",
                                    text: data.data.message || "Có lỗi xảy ra",
                                    icon: "error"
                                });
                                inputField.value = originalValue;
                            }
                        })
                        .catch(error => {
                            console.error("Error:", error);
                            Swal.fire({
                                title: "Lỗi!",
                                text: "Có lỗi xảy ra khi cập nhật",
                                icon: "error"
                            });
                            inputField.value = originalValue;
                        })
                        .finally(() => {
                            // Reset input field
                            inputField.style.background = "";
                            inputField.disabled = false;
                            inputField.style.display = "none";
                            displaySpan.style.display = "block";
                            this.classList.remove("editing");
                        });
                    } else {
                        // No change, just hide input
                        inputField.style.display = "none";
                        displaySpan.style.display = "block";
                        this.classList.remove("editing");
                    }
                };
                
                // Handle Enter key
                inputField.addEventListener("keydown", function(e) {
                    if (e.key === "Enter") {
                        e.preventDefault();
                        saveEdit();
                    } else if (e.key === "Escape") {
                        e.preventDefault();
                        inputField.value = originalValue;
                        inputField.style.display = "none";
                        displaySpan.style.display = "block";
                        this.classList.remove("editing");
                    }
                });
                
                // Handle blur (click outside)
                inputField.addEventListener("blur", saveEdit);
            });
        });
    });
    
    function updateLeadStatus(leadId, newStatus, nonce) {
        Swal.fire({
            title: "Xác nhận cập nhật",
            text: "Bạn có chắc muốn cập nhật trạng thái khách hàng này?",
            icon: "question",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            cancelButtonColor: "#d33",
            confirmButtonText: "Có, cập nhật!",
            cancelButtonText: "Hủy"
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: "Đang cập nhật...",
                    text: "Vui lòng chờ trong giây lát",
                    icon: "info",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                var url = "' . admin_url('admin.php') . '?page=baro-ai-leads&action=update_status&id=" + leadId + "&status=" + newStatus + "&_wpnonce=" + nonce;
                window.location.href = url;
            } else {
                // Reset dropdown to original value
                event.target.value = event.target.dataset.originalValue || "' . $status . '";
            }
        });
    }
    
    function deleteLead(leadId, nonce) {
        Swal.fire({
            title: "Xác nhận xóa",
            text: "Bạn có chắc muốn xóa khách hàng này? Hành động này không thể hoàn tác!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Có, xóa!",
            cancelButtonText: "Hủy"
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: "Đang xóa...",
                    text: "Vui lòng chờ trong giây lát",
                    icon: "info",
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                var url = "' . admin_url('admin.php') . '?page=baro-ai-leads&action=delete&id=" + leadId + "&_wpnonce=" + nonce;
                window.location.href = url;
            }
        });
    }
    
    // Show success/error messages from URL parameters
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        const feedback = urlParams.get("feedback");
        
        if (feedback === "updated") {
            Swal.fire({
                title: "Thành công!",
                text: "Trạng thái khách hàng đã được cập nhật",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        } else if (feedback === "deleted") {
            Swal.fire({
                title: "Đã xóa!",
                text: "Khách hàng đã được xóa thành công",
                icon: "success",
                timer: 3000,
                showConfirmButton: false
            });
        }
    });
    </script>';
  }

  public function register_settings() {
    register_setting('baro_ai_group', self::OPT_KEY);
    add_settings_section('baro_ai_section', 'Cấu hình API & Prompt', '__return_false', 'baro-ai-chatbot');
    add_settings_field('api_key','Gemini API Key', [$this,'field_api_key'], 'baro-ai-chatbot','baro_ai_section');
    add_settings_field('model','Model',           [$this,'field_model'],  'baro-ai-chatbot','baro_ai_section');
    add_settings_field('brand','Tên thương hiệu', [$this,'field_brand'],  'baro-ai-chatbot','baro_ai_section');
    add_settings_field('kb','Knowledge Base tĩnh',[$this,'field_kb'],     'baro-ai-chatbot','baro_ai_section');
    
    add_settings_section('baro_telegram_section', 'Cấu hình Telegram', '__return_false', 'baro-ai-chatbot');
    add_settings_field('telegram_bot_token','Telegram Bot Token', [$this,'field_telegram_bot_token'], 'baro-ai-chatbot','baro_telegram_section');
    add_settings_field('telegram_chat_id','Telegram Chat ID', [$this,'field_telegram_chat_id'], 'baro-ai-chatbot','baro_telegram_section');
    add_settings_field('registration_link', 'Link đăng ứng', [$this, 'field_registration_link'], 'baro-ai-chatbot', 'baro_telegram_section');
    
    add_settings_section('baro_popup_section', 'Cấu hình Popup Thông Báo', '__return_false', 'baro-ai-chatbot');
    add_settings_field('popup_greeting','Lời chào popup', [$this,'field_popup_greeting'], 'baro-ai-chatbot','baro_popup_section');
    add_settings_field('popup_message','Nội dung popup', [$this,'field_popup_message'], 'baro-ai-chatbot','baro_popup_section');
    add_settings_field('popup_questions','Danh sách câu hỏi popup', [$this,'field_popup_questions'], 'baro-ai-chatbot','baro_popup_section');
  }

  public function field_api_key() {
    $v = get_option(self::OPT_KEY, []);
    $mask = !empty($v['api_key']) ? str_repeat('•', 12) : '';
    echo '<input type="password" name="'.esc_attr(self::OPT_KEY).'[api_key]" value="" placeholder="AIza..." style="width:420px">';
    if ($mask) echo '<p><em>Đã lưu một API key.</em></p>';
    echo '<p class="description">Lấy từ Google AI Studio.</p>';
  }
  public function field_model() {
    $v = get_option(self::OPT_KEY, []);
    $model = isset($v['model']) ? $v['model'] : 'gemini-1.5-flash-latest';
    echo '<input type="text" name="'.esc_attr(self::OPT_KEY).'[model]" value="'.esc_attr($model).'" style="width:260px">';
    echo '<p class="description">VD: gemini-1.5-flash-latest.</p>';
  }
  public function field_brand() {
    $v = get_option(self::OPT_KEY, []);
    $brand = isset($v['brand']) ? $v['brand'] : get_bloginfo('name');
    echo '<input type="text" name="'.esc_attr(self::OPT_KEY).'[brand]" value="'.esc_attr($brand).'" style="width:260px">';
  }
  public function field_kb() {
    $v = get_option(self::OPT_KEY, []);
    $kb = isset($v['kb']) ? $v['kb'] : '';
    echo '<textarea name="'.esc_attr(self::OPT_KEY).'[kb]" rows="8" style="width:100%;max-width:720px;">'.esc_textarea($kb).'</textarea>';
    echo '<p class="description">Dán mô tả dịch vụ/sản phẩm, chính sách, giờ làm việc, hotline, link danh mục sản phẩm nội bộ…</p>';
  }
  
  public function field_telegram_bot_token() {
    $v = get_option(self::OPT_KEY, []);
    $mask = !empty($v['telegram_bot_token']) ? str_repeat('•', 12) : '';
    echo '<input type="password" name="'.esc_attr(self::OPT_KEY).'[telegram_bot_token]" value="" placeholder="1234567890:ABC..." style="width:420px">';
    if ($mask) echo '<p><em>Đã lưu một Telegram Bot Token.</em></p>';
    echo '<p class="description">Lấy từ @BotFather trên Telegram.</p>';
  }
  
  public function field_telegram_chat_id() {
    $v = get_option(self::OPT_KEY, []);
    $chat_id = isset($v['telegram_chat_id']) ? $v['telegram_chat_id'] : '';
    echo '<input type="text" name="'.esc_attr(self::OPT_KEY).'[telegram_chat_id]" value="'.esc_attr($chat_id).'" placeholder="-1001234567890" style="width:260px">';
    echo '<p class="description">Chat ID hoặc Channel ID để nhận thông báo (có thể âm).</p>';
  }

  public function field_registration_link()
  {
    $v = get_option(self::OPT_KEY, []);
    $registration_link = isset($v['registration_link']) ? $v['registration_link'] : '';
    echo '<input type="url" name="' . esc_attr(self::OPT_KEY) . '[registration_link]" value="' . esc_attr($registration_link) . '" placeholder="https://example.com/dang-ky" style="width:100%;max-width:500px;">';
    echo '<p class="description">Link đăng ứng sẽ được gửi cho khách hàng sau khi họ nhập form thành công.</p>';
  }

  public function field_popup_greeting() {
    $v = get_option(self::OPT_KEY, []);
    $greeting = isset($v['popup_greeting']) ? $v['popup_greeting'] : 'Xin chào anh chị đã quan tâm tới Thế Giới Số!';
    echo '<input type="text" name="'.esc_attr(self::OPT_KEY).'[popup_greeting]" value="'.esc_attr($greeting).'" style="width:100%;max-width:500px;">';
    echo '<p class="description">Lời chào hiển thị trong popup thông báo.</p>';
  }
  
  public function field_popup_message() {
    $v = get_option(self::OPT_KEY, []);
    $message = isset($v['popup_message']) ? $v['popup_message'] : 'Em có thể giúp gì cho Anh/Chị ạ?';
    echo '<input type="text" name="'.esc_attr(self::OPT_KEY).'[popup_message]" value="'.esc_attr($message).'" style="width:100%;max-width:500px;">';
    echo '<p class="description">Nội dung chính hiển thị trong popup thông báo.</p>';
  }
  
  public function field_popup_questions() {
    $v = get_option(self::OPT_KEY, []);
    $questions = isset($v['popup_questions']) ? $v['popup_questions'] : "Chào mừng bạn đến với Thế Giới Số!\nXin chào! Tôi có thể hỗ trợ gì cho bạn?\nChào bạn! Hãy để tôi giúp đỡ nhé!\nBạn cần tư vấn về dịch vụ nào?\nTôi sẵn sàng trả lời mọi câu hỏi!\nHãy cho tôi biết bạn quan tâm gì nhé!";
    echo '<textarea name="'.esc_attr(self::OPT_KEY).'[popup_questions]" rows="8" style="width:100%;max-width:500px;">'.esc_textarea($questions).'</textarea>';
    echo '<p class="description">Danh sách các câu hỏi/thông điệp hiển thị trong popup (mỗi dòng một câu).</p>';
  }

  public function settings_page() {
    if (isset($_POST[self::OPT_KEY])) {
      $in = wp_unslash($_POST[self::OPT_KEY]);
      $saved = get_option(self::OPT_KEY, []);
      $new = [
        'api_key' => !empty($in['api_key']) ? sanitize_text_field($in['api_key']) : ($saved['api_key'] ?? ''), 
        'model' => sanitize_text_field($in['model'] ?? 'gemini-1.5-flash-latest'), 
        'brand' => sanitize_text_field($in['brand'] ?? get_bloginfo('name')), 
        'kb' => wp_kses_post($in['kb'] ?? ''),
        'telegram_bot_token' => !empty($in['telegram_bot_token']) ? sanitize_text_field($in['telegram_bot_token']) : ($saved['telegram_bot_token'] ?? ''),
        'telegram_chat_id' => sanitize_text_field($in['telegram_chat_id'] ?? ''),
        'popup_greeting' => sanitize_text_field($in['popup_greeting'] ?? 'Xin chào anh chị đã quan tâm tới Thế Giới Số!'),
        'popup_message' => sanitize_text_field($in['popup_message'] ?? 'Em có thể giúp gì cho Anh/Chị ạ?'),
        'popup_questions' => sanitize_textarea_field($in['popup_questions'] ?? '')
      ];
      update_option(self::OPT_KEY, $new);
    }
    echo '<div class="wrap"><h1>BARO AI Chatbot (Grounded)</h1><form method="post" action="">';
    settings_fields('baro_ai_group');
    do_settings_sections('baro-ai-chatbot');
    submit_button('Lưu cấu hình');
    echo '</form><hr><p><strong>Shortcode:</strong> [ai_chatbot].</p></div>';
  }

  private function find_snippets($query, $limit = 4) {
    $query = sanitize_text_field($query);
    $pt = ['post','page'];
    if (post_type_exists('product')) $pt[] = 'product';
    if (post_type_exists('faq')) $pt[] = 'faq';
    $args = ['s' => $query, 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => $limit, 'orderby' => 'relevance', 'no_found_rows' => true, 'suppress_filters' => true];
    $q = new \WP_Query($args);
    $snips = [];
    if ($q->have_posts()) {
      while ($q->have_posts()) {
        $q->the_post();
        $content = get_post_field('post_content', get_the_ID());
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/
/', ' ', $content);
        $excerpt = mb_substr($content, 0, 700);
        $snips[] = ['title' => get_the_title(), 'url' => get_permalink(), 'text' => $excerpt];
      }
      wp_reset_postdata();
    }
    return $snips;
  }

  private function build_context($kb, $snips, $brand) {
    $kb = wp_strip_all_tags($kb ?? '');
    $identity = "Thông tin về tôi: Tôi là trợ lý ảo thông minh, đại diện tư vấn cho thương hiệu {$brand}.";
    $ctx = "=== KNOWLEDGE BASE ===\n" . $identity . "\n\n" . $kb . "\n\n";
    if (!empty($snips)) {
        $ctx .= "=== INTERNAL SNIPPETS (ALLOWED SOURCES ONLY) ===\n";
        $urls = [];
        foreach ($snips as $i => $s) {
          $title = wp_strip_all_tags($s['title']);
          $url   = esc_url_raw($s['url']);
          $text  = wp_strip_all_tags($s['text']);
          $ctx .= "- [$title] <$url>\n  \"$text\"\n";
          $urls[] = $url;
        }
    }
    return [$ctx, $urls ?? []];
  }

  private function get_site_hosts_whitelist() {
    $host = parse_url(home_url(), PHP_URL_HOST);
    return array_unique([$host, 'www.'.$host]);
  }

  private function extract_customer_info_with_ai($message) {
    $settings = get_option(self::OPT_KEY, []);
    $api_key = $settings['api_key'] ?? '';
    
    if (empty($api_key)) {
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

    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$settings['model']}:generateContent?key={$api_key}";
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

  private function extract_customer_info_fallback($message) {
    // Fallback to regex-based extraction
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

  private function send_telegram_notification($name, $phone, $email, $message, $receiver_name = '', $current_page_url = '')
  {
    $settings = get_option(self::OPT_KEY, []);
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '';

    // Log for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Attempting to send Telegram notification");
      error_log("BARO AI: Bot token exists: " . (!empty($bot_token) ? 'Yes' : 'No'));
      error_log("BARO AI: Chat ID exists: " . (!empty($chat_id) ? 'Yes' : 'No'));
    }

    if (empty($bot_token) || empty($chat_id)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Telegram not configured - Bot token or Chat ID missing");
      }
      return false;
    }

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

    // Thêm URL trang hiện tại nếu có
    if (!empty($current_page_url)) {
      $text .= "🔗 *Trang đang xem:* " . $current_page_url . "\n";
    }

    // Thêm link đăng ứng nếu có cấu hình
    $registration_link = $settings['registration_link'] ?? '';
    if (!empty($registration_link)) {
      $text .= "🔗 *Link đăng ứng:* " . $registration_link . "\n";
    }

    $text .= "\n💡 *Hành động:* Vui lòng liên hệ khách hàng sớm nhất!";

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
      'chat_id' => $chat_id,
      'text' => $text,
      'parse_mode' => 'Markdown'
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Sending to Telegram URL: " . $url);
      error_log("BARO AI: Message: " . $text);
    }

    $response = wp_remote_post($url, [
      'headers' => ['Content-Type' => 'application/json'],
      'body' => wp_json_encode($data),
      'timeout' => 10
    ]);

    if (is_wp_error($response)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Telegram API error: " . $response->get_error_message());
      }
      return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Telegram response code: " . $response_code);
      error_log("BARO AI: Telegram response body: " . $response_body);
    }

    return $response_code === 200;
  }

  private function send_admin_action_telegram_notification($action_type, $lead, $new_value = '')
  {
    $settings = get_option(self::OPT_KEY, []);
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '';

    if (empty($bot_token) || empty($chat_id)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Telegram not configured for admin notifications");
      }
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

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
      'chat_id' => $chat_id,
      'text' => $text,
      'parse_mode' => 'Markdown'
    ];

    $response = wp_remote_post($url, [
      'body' => $data,
      'timeout' => 10
    ]);

    if (is_wp_error($response)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: Telegram admin notification error: " . $response->get_error_message());
      }
      return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Telegram admin notification response code: " . $response_code);
      error_log("BARO AI: Telegram admin notification response body: " . $response_body);
    }

    return $response_code === 200;
  }



  private function extract_and_save_lead($message, $current_page_url = '')
  {
    // Use AI extraction for better accuracy
    $customer_info = $this->extract_customer_info_with_ai($message);
    
    $name = $customer_info['name'];
    $phone = $customer_info['phone'];
    $email = $customer_info['email'];
    $receiver_name = $customer_info['receiver_name'];

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Extracted customer info - Name: '$name', Phone: '$phone', Email: '$email', Receiver: '$receiver_name'");
    }

    // If no phone or email is found, it's not a lead.
    if (empty($phone) && empty($email)) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("BARO AI: No phone or email found, not saving as lead");
      }
      return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_leads';
    $result = $wpdb->insert($table_name, [
        'created_at' => current_time('mysql'),
        'name'       => sanitize_text_field($name),
        'phone'      => sanitize_text_field($phone),
        'email'      => sanitize_email($email),
      'receiver_name' => sanitize_text_field($receiver_name),
      'message' => sanitize_textarea_field($message),
      'current_page_url' => sanitize_url($current_page_url)
    ]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
      if ($result === false) {
        error_log("BARO AI: Failed to insert lead into database: " . $wpdb->last_error);
      } else {
        error_log("BARO AI: Successfully inserted lead with ID: " . $wpdb->insert_id);
      }
    }

    // Send Telegram notification
    $telegram_sent = $this->send_telegram_notification($name, $phone, $email, $message, $receiver_name, $current_page_url);

    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log("BARO AI: Telegram notification sent: " . ($telegram_sent ? 'Yes' : 'No'));
    }

    return true;
  }
}

new Baro_AI_Chatbot_Grounded(__FILE__);
