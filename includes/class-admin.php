<?php
/**
 * Admin pages for BARO AI Chatbot
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Admin {
  private $database;
  private $telegram;

  public function __construct() {
    $this->database = new Baro_AI_Database();
    $this->telegram = new Baro_AI_Telegram();
  }

  /**
   * Add admin menu
   */
  public function add_menu() {
    add_menu_page('BARO AI Chatbot', 'BARO AI Chatbot', 'manage_options', 'baro-ai-chatbot', [$this, 'settings_page'], 'dashicons-format-chat', 30);
    add_submenu_page('baro-ai-chatbot', 'Cài đặt Chatbot', 'Cài đặt', 'manage_options', 'baro-ai-chatbot', [$this, 'settings_page']);
    add_submenu_page('baro-ai-chatbot', 'Khách hàng tiềm năng', 'Khách hàng tiềm năng', 'manage_options', 'baro-ai-leads', [$this, 'leads_page']);
    add_menu_page('Dịch vụ & Sản phẩm', 'Dịch vụ & Sản phẩm', 'manage_options', 'baro-ai-products', [$this, 'product_admin_page'], 'dashicons-archive', 31);
  }

  /**
   * Register settings
   */
  public function register_settings() {
    register_setting('baro_ai_group', 'baro_ai_settings');
    add_settings_section('baro_ai_section', 'Cấu hình API & Prompt', '__return_false', 'baro-ai-chatbot');
    add_settings_field('api_key', 'Gemini API Key', [$this, 'field_api_key'], 'baro-ai-chatbot', 'baro_ai_section');
    add_settings_field('model', 'Model', [$this, 'field_model'], 'baro-ai-chatbot', 'baro_ai_section');
    add_settings_field('brand', 'Tên thương hiệu', [$this, 'field_brand'], 'baro-ai-chatbot', 'baro_ai_section');
    add_settings_field('chatbot_title', 'Tiêu đề Chatbot', [$this, 'field_chatbot_title'], 'baro-ai-chatbot', 'baro_ai_section');
    add_settings_field('kb', 'Knowledge Base tĩnh', [$this, 'field_kb'], 'baro-ai-chatbot', 'baro_ai_section');
    
    add_settings_section('baro_telegram_section', 'Cấu hình Telegram', '__return_false', 'baro-ai-chatbot');
    add_settings_field('telegram_bot_token', 'Telegram Bot Token', [$this, 'field_telegram_bot_token'], 'baro-ai-chatbot', 'baro_telegram_section');
    add_settings_field('telegram_chat_id', 'Telegram Chat ID', [$this, 'field_telegram_chat_id'], 'baro-ai-chatbot', 'baro_telegram_section');
    add_settings_field('registration_link', 'Link đăng ứng', [$this, 'field_registration_link'], 'baro-ai-chatbot', 'baro_telegram_section');
    
    add_settings_section('baro_popup_section', 'Cấu hình Popup Thông Báo', '__return_false', 'baro-ai-chatbot');
    add_settings_field('popup_greeting', 'Lời chào popup', [$this, 'field_popup_greeting'], 'baro-ai-chatbot', 'baro_popup_section');
    add_settings_field('popup_message', 'Nội dung popup', [$this, 'field_popup_message'], 'baro-ai-chatbot', 'baro_popup_section');
    add_settings_field('popup_questions', 'Danh sách câu hỏi popup', [$this, 'field_popup_questions'], 'baro-ai-chatbot', 'baro_popup_section');
  }

  /**
   * Settings page
   */
  public function settings_page() {
    if (isset($_POST['baro_ai_settings'])) {
      $in = wp_unslash($_POST['baro_ai_settings']);
      $saved = get_option('baro_ai_settings', []);
      $new = [
        'api_key' => !empty($in['api_key']) ? sanitize_text_field($in['api_key']) : ($saved['api_key'] ?? ''),
        'model' => sanitize_text_field($in['model'] ?? 'gemini-1.5-flash-latest'),
        'brand' => sanitize_text_field($in['brand'] ?? get_bloginfo('name')),
        'chatbot_title' => sanitize_text_field($in['chatbot_title'] ?? ''),
        'kb' => wp_kses_post($in['kb'] ?? ''),
        'telegram_bot_token' => !empty($in['telegram_bot_token']) ? sanitize_text_field($in['telegram_bot_token']) : ($saved['telegram_bot_token'] ?? ''),
        'telegram_chat_id' => sanitize_text_field($in['telegram_chat_id'] ?? ''),
        'popup_greeting' => sanitize_text_field($in['popup_greeting'] ?? 'Xin chào anh chị đã quan tâm tới Thế Giới Số!'),
        'popup_message' => sanitize_text_field($in['popup_message'] ?? 'Em có thể giúp gì cho Anh/Chị ạ?'),
        'popup_questions' => sanitize_textarea_field($in['popup_questions'] ?? ''),
        'registration_link' => sanitize_url($in['registration_link'] ?? '')
      ];
      update_option('baro_ai_settings', $new);
    }
    
    echo '<div class="wrap"><h1>BARO AI Chatbot (Grounded)</h1><form method="post" action="">';
    settings_fields('baro_ai_group');
    do_settings_sections('baro-ai-chatbot');
    submit_button('Lưu cấu hình');
    echo '</form><hr><p><strong>Shortcode:</strong> [ai_chatbot].</p></div>';
  }

  /**
   * Settings fields
   */
  public function field_api_key() {
    $v = get_option('baro_ai_settings', []);
    $mask = !empty($v['api_key']) ? str_repeat('•', 12) : '';
    echo '<input type="password" name="baro_ai_settings[api_key]" value="" placeholder="AIza..." style="width:420px">';
    if ($mask) echo '<p><em>Đã lưu một API key.</em></p>';
    echo '<p class="description">Lấy từ Google AI Studio.</p>';
  }

  public function field_model() {
    $v = get_option('baro_ai_settings', []);
    $model = isset($v['model']) ? $v['model'] : 'gemini-1.5-flash-latest';
    echo '<input type="text" name="baro_ai_settings[model]" value="' . esc_attr($model) . '" style="width:260px">';
    echo '<p class="description">VD: gemini-1.5-flash-latest.</p>';
  }

  public function field_brand() {
    $v = get_option('baro_ai_settings', []);
    $brand = isset($v['brand']) ? $v['brand'] : get_bloginfo('name');
    echo '<input type="text" name="baro_ai_settings[brand]" value="' . esc_attr($brand) . '" style="width:260px">';
  }

  public function field_chatbot_title() {
    $v = get_option('baro_ai_settings', []);
    $brand = isset($v['brand']) ? $v['brand'] : get_bloginfo('name');
    $default_title = 'Tư vấn ' . $brand . ' 💬';
    $title = isset($v['chatbot_title']) ? $v['chatbot_title'] : $default_title;
    echo '<input type="text" name="baro_ai_settings[chatbot_title]" value="' . esc_attr($title) . '" style="width:400px">';
    echo '<p class="description">Tiêu đề hiển thị trên chatbot. Để trống sẽ dùng mặc định: "Tư vấn [Tên thương hiệu] 💬"</p>';
  }

  public function field_kb() {
    $v = get_option('baro_ai_settings', []);
    $kb = isset($v['kb']) ? $v['kb'] : '';
    echo '<textarea name="baro_ai_settings[kb]" rows="8" style="width:100%;max-width:720px;">' . esc_textarea($kb) . '</textarea>';
    echo '<p class="description">Dán mô tả dịch vụ/sản phẩm, chính sách, giờ làm việc, hotline, link danh mục sản phẩm nội bộ…</p>';
  }

  public function field_telegram_bot_token() {
    $v = get_option('baro_ai_settings', []);
    $mask = !empty($v['telegram_bot_token']) ? str_repeat('•', 12) : '';
    echo '<input type="password" name="baro_ai_settings[telegram_bot_token]" value="" placeholder="1234567890:ABC..." style="width:420px">';
    if ($mask) echo '<p><em>Đã lưu một Telegram Bot Token.</em></p>';
    echo '<p class="description">Lấy từ @BotFather trên Telegram.</p>';
  }

  public function field_telegram_chat_id() {
    $v = get_option('baro_ai_settings', []);
    $chat_id = isset($v['telegram_chat_id']) ? $v['telegram_chat_id'] : '';
    echo '<input type="text" name="baro_ai_settings[telegram_chat_id]" value="' . esc_attr($chat_id) . '" placeholder="-1001234567890" style="width:260px">';
    echo '<p class="description">Chat ID hoặc Channel ID để nhận thông báo (có thể âm).</p>';
  }

  public function field_registration_link() {
    $v = get_option('baro_ai_settings', []);
    $registration_link = isset($v['registration_link']) ? $v['registration_link'] : '';
    echo '<input type="url" name="baro_ai_settings[registration_link]" value="' . esc_attr($registration_link) . '" placeholder="https://example.com/dang-ky" style="width:100%;max-width:500px;">';
    echo '<p class="description">Link đăng ứng sẽ được gửi cho khách hàng sau khi họ nhập form thành công.</p>';
  }

  public function field_popup_greeting() {
    $v = get_option('baro_ai_settings', []);
    $greeting = isset($v['popup_greeting']) ? $v['popup_greeting'] : 'Xin chào anh chị đã quan tâm tới Thế Giới Số!';
    echo '<input type="text" name="baro_ai_settings[popup_greeting]" value="' . esc_attr($greeting) . '" style="width:100%;max-width:500px;">';
    echo '<p class="description">Lời chào hiển thị trong popup thông báo.</p>';
  }

  public function field_popup_message() {
    $v = get_option('baro_ai_settings', []);
    $message = isset($v['popup_message']) ? $v['popup_message'] : 'Em có thể giúp gì cho Anh/Chị ạ?';
    echo '<input type="text" name="baro_ai_settings[popup_message]" value="' . esc_attr($message) . '" style="width:100%;max-width:500px;">';
    echo '<p class="description">Nội dung chính hiển thị trong popup thông báo.</p>';
  }

  public function field_popup_questions() {
    $v = get_option('baro_ai_settings', []);
    $questions = isset($v['popup_questions']) ? $v['popup_questions'] : "Chào mừng bạn đến với Thế Giới Số!\nXin chào! Tôi có thể hỗ trợ gì cho bạn?\nChào bạn! Hãy để tôi giúp đỡ nhé!\nBạn cần tư vấn về dịch vụ nào?\nTôi sẵn sàng trả lời mọi câu hỏi!\nHãy cho tôi biết bạn quan tâm gì nhé!";
    echo '<textarea name="baro_ai_settings[popup_questions]" rows="8" style="width:100%;max-width:500px;">' . esc_textarea($questions) . '</textarea>';
    echo '<p class="description">Danh sách các câu hỏi/thông điệp hiển thị trong popup (mỗi dòng một câu).</p>';
  }

  /**
   * Handle product actions
   */
  public function handle_product_actions() {
    if (!isset($_GET['page']) || !current_user_can('manage_options')) {
      return;
    }

    $action = $_GET['action'] ?? '';

    // Handle product actions
    if ($_GET['page'] === 'baro-ai-products') {
      if ($action === 'delete' && !empty($_GET['id'])) {
        $product_id = absint($_GET['id']);
        if (check_admin_referer('baro_ai_delete_product_' . $product_id)) {
          $this->database->delete_product($product_id);
          wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=deleted'));
          exit;
        }
      }
      if ($action === 'seed_definitions') {
        if (check_admin_referer('baro_ai_seed_definitions_nonce')) {
          $this->database->seed_definitions();
          wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=seeded'));
          exit;
        }
      }
    }

    // Handle leads actions
    if ($_GET['page'] === 'baro-ai-leads') {
      if ($action === 'delete' && !empty($_GET['id'])) {
        $lead_id = absint($_GET['id']);
        if (check_admin_referer('baro_ai_delete_lead_' . $lead_id)) {
          $this->database->delete_lead($lead_id);
          wp_redirect(admin_url('admin.php?page=baro-ai-leads&feedback=deleted'));
          exit;
        }
      }
      if ($action === 'update_status' && !empty($_GET['id']) && !empty($_GET['status'])) {
        $lead_id = absint($_GET['id']);
        $status = sanitize_text_field($_GET['status']);

        if (check_admin_referer('baro_ai_update_lead_status_' . $lead_id) && in_array($status, ['chua_lien_he', 'da_lien_he', 'dang_tu_van', 'da_chot_don'])) {
          $lead = $this->database->get_lead($lead_id);
          $result = $this->database->update_lead_status($lead_id, $status);

          if ($result !== false) {
            $this->telegram->send_admin_action_notification('status_update', $lead, $status);
          }

          wp_redirect(admin_url('admin.php?page=baro-ai-leads&feedback=updated'));
          exit;
        }
      }
    }
  }

  /**
   * Handle product form
   */
  public function handle_product_form() {
    if (!isset($_POST['baro_ai_product_nonce']) || !wp_verify_nonce($_POST['baro_ai_product_nonce'], 'baro_ai_save_product') || !current_user_can('manage_options')) {
      return;
    }

    $data = [
      'name' => sanitize_text_field($_POST['name']),
      'category' => sanitize_text_field($_POST['category']),
      'price' => sanitize_text_field($_POST['price']),
      'sale_price' => sanitize_text_field($_POST['sale_price']),
      'config' => sanitize_textarea_field($_POST['config']),
      'description' => sanitize_textarea_field($_POST['description'])
    ];

    $id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $this->database->save_product($data, $id);
    wp_redirect(admin_url('admin.php?page=baro-ai-products&feedback=saved'));
    exit;
  }

  /**
   * AJAX update receiver name
   */
  public function ajax_update_receiver_name() {
    if (!wp_verify_nonce($_POST['nonce'], 'baro_ai_update_receiver_name') || !current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }

    $lead_id = absint($_POST['lead_id']);
    $receiver_name = sanitize_text_field($_POST['receiver_name']);

    $lead = $this->database->get_lead($lead_id);
    $result = $this->database->update_lead_receiver($lead_id, $receiver_name);

    if ($result !== false) {
      $this->telegram->send_admin_action_notification('receiver_update', $lead, $receiver_name);
      wp_send_json_success(['message' => 'Cập nhật thành công']);
    } else {
      wp_send_json_error(['message' => 'Cập nhật thất bại']);
    }
  }

  /**
   * Product admin page
   */
  public function product_admin_page() {
    $action = $_GET['action'] ?? 'list';
    $product_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    
    if ($action === 'add' || $action === 'edit') {
      $product = null;
      if ($product_id > 0) {
        $product = $this->database->get_product($product_id);
      }
      $this->render_product_form($product, $product_id);
      return;
    }

    $this->render_products_list();
  }

  /**
   * Render product form
   */
  private function render_product_form($product, $product_id) {
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
  }

  /**
   * Render products list
   */
  private function render_products_list() {
    $products = $this->database->get_products();
    ?>
    <div class="wrap">
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
      <h1>Dịch vụ & Sản phẩm 
        <a href="?page=baro-ai-products&action=add" class="page-title-action">Thêm mới</a> 
        <a href="<?php echo wp_nonce_url('?page=baro-ai-products&action=seed_definitions', 'baro_ai_seed_definitions_nonce'); ?>" class="page-title-action">Thêm định nghĩa dịch vụ</a>
      </h1>
      
      <?php if (!empty($_GET['feedback'])): ?>
        <div class="notice notice-success is-dismissible">
          <p>
            <?php
            switch ($_GET['feedback']) {
              case 'saved': echo 'Đã lưu thành công.'; break;
              case 'seeded': echo 'Đã thêm các định nghĩa dịch vụ mẫu thành công.'; break;
              case 'deleted': echo 'Đã xóa thành công.'; break;
            }
            ?>
          </p>
        </div>
      <?php endif; ?>
      
      <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Tên sản phẩm</th><th>Loại</th><th>Giá</th><th>Hành động</th></tr></thead>
        <tbody>
          <?php if ($products): foreach ($products as $p): ?>
            <tr>
              <td><strong><?php echo esc_html($p->name); ?></strong></td>
              <td><?php echo esc_html($p->category); ?></td>
              <td><?php echo esc_html($p->price); ?></td>
              <td>
                <a href="?page=baro-ai-products&action=edit&id=<?php echo $p->id; ?>">Sửa</a> | 
                <a href="#" onclick="deleteProduct(<?php echo $p->id; ?>, '<?php echo wp_create_nonce('baro_ai_delete_product_' . $p->id); ?>')" style="color:red;">Xóa</a>
              </td>
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
        text: "Bạn có chắc muốn xóa sản phẩm này?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "Có, xóa!",
        cancelButtonText: "Hủy"
      }).then((result) => {
        if (result.isConfirmed) {
          var url = "?page=baro-ai-products&action=delete&id=" + productId + "&_wpnonce=" + nonce;
          window.location.href = url;
        }
      });
    }
    </script>
    <?php
  }

  /**
   * Leads page
   */
  public function leads_page() {
    // Pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Get data
    $total_items = $this->database->get_leads_count();
    $total_pages = ceil($total_items / $per_page);
    $leads = $this->database->get_leads($per_page, $offset);

    // Status options
    $status_options = [
      'chua_lien_he' => 'Chưa liên hệ',
      'da_lien_he' => 'Đã liên hệ',
      'dang_tu_van' => 'Đang tư vấn',
      'da_chot_don' => 'Đã chốt đơn'
    ];

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

    // Show feedback messages
    if (!empty($_GET['feedback'])) {
      $feedback_msg = '';
      if ($_GET['feedback'] === 'deleted') $feedback_msg = 'Đã xóa khách hàng thành công.';
      if ($_GET['feedback'] === 'updated') $feedback_msg = 'Đã cập nhật trạng thái thành công.';
      if ($feedback_msg) echo '<div class="notice notice-success is-dismissible"><p>' . $feedback_msg . '</p></div>';
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
        echo '<td class="receiver-name-cell" data-lead-id="' . $lead->id . '" data-receiver-name="' . esc_attr($lead->receiver_name ?? '') . '" style="cursor: pointer;">';
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
    echo '</div>';

    // JavaScript for inline editing and actions
    echo '<script>
    // Inline editing for receiver name
    document.addEventListener("DOMContentLoaded", function() {
      const receiverCells = document.querySelectorAll(".receiver-name-cell");
      
      receiverCells.forEach(cell => {
        cell.addEventListener("dblclick", function() {
          if (this.classList.contains("editing")) return;
          
          const leadId = this.dataset.leadId;
          const displaySpan = this.querySelector(".receiver-name-display");
          const inputField = this.querySelector(".receiver-name-input");
          const originalValue = this.dataset.receiverName;
          
          displaySpan.style.display = "none";
          inputField.style.display = "block";
          this.classList.add("editing");
          inputField.focus();
          inputField.select();
          
          const saveEdit = () => {
            const newValue = inputField.value.trim();
            
            if (newValue !== originalValue) {
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
                  displaySpan.textContent = newValue || "-";
                  this.dataset.receiverName = newValue;
                  Swal.fire({
                    title: "Thành công!",
                    text: "Đã cập nhật người tiếp nhận",
                    icon: "success",
                    timer: 2000,
                    showConfirmButton: false
                  });
                } else {
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
                inputField.value = originalValue;
              })
              .finally(() => {
                inputField.style.display = "none";
                displaySpan.style.display = "block";
                this.classList.remove("editing");
              });
            } else {
              inputField.style.display = "none";
              displaySpan.style.display = "block";
              this.classList.remove("editing");
            }
          };
          
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
          var url = "' . admin_url('admin.php') . '?page=baro-ai-leads&action=update_status&id=" + leadId + "&status=" + newStatus + "&_wpnonce=" + nonce;
          window.location.href = url;
        }
      });
    }
    
    function deleteLead(leadId, nonce) {
      Swal.fire({
        title: "Xác nhận xóa",
        text: "Bạn có chắc muốn xóa khách hàng này?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "Có, xóa!",
        cancelButtonText: "Hủy"
      }).then((result) => {
        if (result.isConfirmed) {
          var url = "' . admin_url('admin.php') . '?page=baro-ai-leads&action=delete&id=" + leadId + "&_wpnonce=" + nonce;
          window.location.href = url;
        }
      });
    }
    </script>';
  }
}
