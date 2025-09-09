<?php
/**
 * Plugin Name: BARO AI Chatbot (Grounded)
 * Description: Chatbot AI tư vấn dựa trên Knowledge Base & nội dung nội bộ. Shortcode [ai_chatbot]. Trả lời chỉ khi "grounded".
 * Version: 1.5.2
 * Author: Baro
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Chatbot_Grounded {
  const OPT_KEY = 'baro_ai_settings';

  public function __construct($plugin_file) {
    add_shortcode('ai_chatbot', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'assets']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('admin_menu', [$this, 'settings_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_post_baro_ai_save_product', [$this, 'handle_product_form']);
    add_action('admin_init', [$this, 'handle_product_actions']);
    register_activation_hook($plugin_file, [$this, 'activate']);
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
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_leads);

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
  }

  public function shortcode($atts = []) {
    $defaults = [
      'title' => 'Tư vấn nhanh',
      'placeholder' => 'Nhập câu hỏi về dịch vụ/sản phẩm...',
    ];
    $a = shortcode_atts($defaults, $atts);
    wp_enqueue_script('baro-ai-chat');
    wp_enqueue_style('baro-ai-chat');
    $nonce = wp_create_nonce('wp_rest');
    $settings = get_option(self::OPT_KEY, []);
    $brand = isset($settings['brand']) ? esc_html($settings['brand']) : get_bloginfo('name');
    ob_start(); ?>
    <div id="baro-ai-root" class="baro-ai-root" data-title="<?php echo esc_attr($a['title']); ?>"
         data-placeholder="<?php echo esc_attr($a['placeholder']); ?>" data-brand="<?php echo $brand; ?>"></div>
    <script>
      window.BARO_AI_CFG = {
        restBase: "<?php echo esc_js(esc_url_raw(trailingslashit(get_rest_url(null, 'baro-ai/v1')))); ?>",
        nonce: "<?php echo esc_js($nonce); ?>"
      };
    </script>
    <?php return ob_get_clean();
  }

  public function assets() {
    $base = plugin_dir_url(__FILE__);
    wp_register_script('baro-ai-chat', $base . 'assets/chat.js', [], '1.5.2', true);
    wp_register_style('baro-ai-chat', $base . 'assets/chat.css', [], '1.5.2');
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
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/^\*\s(.*?)$/m', '<li>$1</li>', $text);
    if (strpos($text, '<li>') !== false) {
        $text = '<ul>' . str_replace("\n", "", $text) . '</ul>';
    }
    $text = nl2br($text);
    return $text;
  }

  public function handle_chat(\WP_REST_Request $req) {
    if (!$this->check_rate_limit()) {
      return new \WP_REST_Response(['error'=>'Too many requests'], 429);
    }
    $body = $req->get_json_params();
    $user_msg = trim(sanitize_text_field($body['message'] ?? ''));
    if ($user_msg === '') {
      return new \WP_REST_Response(['error'=>'Empty message'], 400);
    }
    if ($this->extract_and_save_lead($user_msg)) {
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
    $system = "Bạn là một chuyên viên tư vấn bán hàng chuyên nghiệp của {$brand}. Vai trò của bạn là trả lời các câu hỏi của khách hàng một cách ngắn gọn, thân thiện bằng tiếng Việt, và chỉ dựa vào thông tin được cung cấp trong CONTEXT.\n\nQUY TẮC:\n1. CHUYÊN MÔN: Chỉ trả lời các câu hỏi liên quan trực tiếp đến sản phẩm, dịch vụ, chính sách được mô tả trong CONTEXT.\n2. TỪ CHỐI: Nếu người dùng hỏi về bất cứ điều gì ngoài CONTEXT, bạn PHẢI lịch sự từ chối. Sau đó, trả về JSON: `{\"grounded\": false, \"request_contact\": false, \"answer\": \"Xin lỗi, tôi chỉ có thể tư vấn về các sản phẩm và dịch vụ của chúng tôi.\"}`.\n3. KHAI THÁC THÔNG TIN: Nếu câu hỏi của người dùng có liên quan đến sản phẩm/dịch vụ nhưng không thể trả lời bằng CONTEXT, hãy yêu cầu họ cung cấp Tên, SĐT và Email để chuyên gia hỗ trợ. Ví dụ: \"Để tư vấn chi tiết hơn, bạn vui lòng cho mình xin Tên, SĐT và Email để chuyên viên của chúng tôi liên hệ nhé.\". Sau đó, trả về JSON: `{\"grounded\": false, \"request_contact\": true, \"answer\": \"...\"}`.\n4. TRẢ LỜI THÔNG THƯỜNG: Nếu bạn có thể trả lời câu hỏi bằng CONTEXT, hãy trả lời và trích dẫn nguồn (nếu có). Sau đó, trả về JSON: `{\"grounded\": true, \"answer\": \"...\", \"sources\": []}`.\n5. ĐỊNH DẠNG: Luôn sử dụng Markdown để định dạng câu trả lời cho dễ đọc. Dùng **chữ in đậm** để nhấn mạnh các tiêu đề hoặc thông tin quan trọng. Dùng dấu * ở đầu dòng để tạo danh sách.\n\nLUÔN LUÔN trả lời bằng một đối tượng JSON hợp lệ theo các quy tắc trên.\n\nCONTEXT:\n{$contextText}\n\nALLOWED_SOURCES:\n" . implode("\n", $urls);
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
    if (!isset($_GET['page']) || $_GET['page'] !== 'baro-ai-products' || !current_user_can('manage_options')) return;
    $action = $_GET['action'] ?? '';
    if ($action === 'delete' && !empty($_GET['id'])) {
        $product_id = absint($_GET['id']);
        if (check_admin_referer('baro_ai_delete_product_' . $product_id)) {
            global $wpdb; $wpdb->delete($wpdb->prefix . 'baro_ai_products', ['id' => $product_id], ['%d']);
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
    <div class="wrap"><h1>Dịch vụ & Sản phẩm <a href="?page=baro-ai-products&action=add" class="page-title-action">Thêm mới</a> <a href="<?php echo wp_nonce_url('?page=baro-ai-products&action=seed_definitions', 'baro_ai_seed_definitions_nonce'); ?>" class="page-title-action">Thêm định nghĩa dịch vụ</a></h1>
        <?php 
        if (!empty($_GET['feedback'])) {
            $feedback_msg = '';
            if ($_GET['feedback'] === 'saved') $feedback_msg = 'Đã lưu thành công.';
            if ($_GET['feedback'] === 'seeded') $feedback_msg = 'Đã thêm các định nghĩa dịch vụ mẫu thành công.';
            if ($_GET['feedback'] === 'deleted') $feedback_msg = 'Đã xóa thành công.';
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
                    <td><a href="?page=baro-ai-products&action=edit&id=<?php echo $p->id; ?>">Sửa</a> | <a href="<?php echo wp_nonce_url('?page=baro-ai-products&action=delete&id=' . $p->id, 'baro_ai_delete_product_' . $p->id); ?>" onclick="return confirm('Bạn có chắc muốn xóa?')" style="color:red;">Xóa</a></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4">Chưa có sản phẩm nào.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
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
    $leads = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1000");
    echo '<div class="wrap"><h1>Khách hàng tiềm năng</h1><p>Danh sách thông tin khách hàng thu thập được từ chatbot.</p><table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:170px;">Thời gian</th><th>Tên</th><th>SĐT</th><th>Email</th><th>Tin nhắn gốc</th></tr></thead><tbody>';
    if ($leads) {
        foreach ($leads as $lead) {
            echo '<tr><td>' . esc_html(date('d/m/Y H:i:s', strtotime($lead->created_at))) . '</td><td>' . (isset($lead->name) ? esc_html($lead->name) : '') . '</td><td><strong>' . esc_html($lead->phone) . '</strong></td><td>' . (isset($lead->email) ? esc_html($lead->email) : '') . '</td><td>' . esc_html($lead->message) . '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="5">Chưa có dữ liệu.</td></tr>';
    }
    echo '</tbody></table></div>';
  }

  public function register_settings() {
    register_setting('baro_ai_group', self::OPT_KEY);
    add_settings_section('baro_ai_section', 'Cấu hình API & Prompt', '__return_false', 'baro-ai-chatbot');
    add_settings_field('api_key','Gemini API Key', [$this,'field_api_key'], 'baro-ai-chatbot','baro_ai_section');
    add_settings_field('model','Model',           [$this,'field_model'],  'baro-ai-chatbot','baro_ai_section');
    add_settings_field('brand','Tên thương hiệu', [$this,'field_brand'],  'baro-ai-chatbot','baro_ai_section');
    add_settings_field('kb','Knowledge Base tĩnh',[$this,'field_kb'],     'baro-ai-chatbot','baro_ai_section');
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

  public function settings_page() {
    if (isset($_POST[self::OPT_KEY])) {
      $in = wp_unslash($_POST[self::OPT_KEY]);
      $saved = get_option(self::OPT_KEY, []);
      $new = ['api_key' => !empty($in['api_key']) ? sanitize_text_field($in['api_key']) : ($saved['api_key'] ?? ''), 'model' => sanitize_text_field($in['model'] ?? 'gemini-1.5-flash-latest'), 'brand' => sanitize_text_field($in['brand'] ?? get_bloginfo('name')), 'kb' => wp_kses_post($in['kb'] ?? '')];
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
        $content = preg_replace('/\s+/', ' ', $content);
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

  private function extract_and_save_lead($message) {
    preg_match('/(0[3|5|7|8|9])([0-9]{8})\b/', $message, $phone_matches);
    $phone = !empty($phone_matches[0]) ? $phone_matches[0] : '';
    preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', $message, $email_matches);
    $email = !empty($email_matches[0]) ? $email_matches[0] : '';
    if (empty($phone) && empty($email)) return false;
    $name = '';
    if (preg_match('/(tên tôi là|tên của tôi là|tên mình là|mình tên là)\s*([^\d\n,]+)/ui', $message, $name_matches)) {
        $name = trim($name_matches[2]);
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'baro_ai_leads';
    $wpdb->insert($table_name, ['created_at' => current_time('mysql'), 'name' => sanitize_text_field($name), 'phone' => sanitize_text_field($phone), 'email' => sanitize_email($email), 'message' => sanitize_textarea_field($message)]);
    return true;
  }
}

new Baro_AI_Chatbot_Grounded(__FILE__);
