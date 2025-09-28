<?php
/**
 * Database operations for BARO AI Chatbot
 */

if (!defined('ABSPATH')) exit;

class Baro_AI_Database {
  private $wpdb;
  private $table_leads;
  private $table_products;

  public function __construct() {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->table_leads = $wpdb->prefix . 'baro_ai_leads';
    $this->table_products = $wpdb->prefix . 'baro_ai_products';
  }

  /**
   * Create database tables
   */
  public function create_tables() {
    $charset_collate = $this->wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Create leads table
    $sql_leads = "CREATE TABLE {$this->table_leads} (
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

    // Create products table
    $sql_products = "CREATE TABLE {$this->table_products} (
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

    // Add missing columns if they don't exist
    $this->add_missing_columns();
  }

  /**
   * Add missing columns to existing tables
   */
  private function add_missing_columns() {
    $columns = $this->wpdb->get_col("DESCRIBE {$this->table_leads}");
    
    if (!in_array('status', $columns)) {
      $this->wpdb->query("ALTER TABLE {$this->table_leads} ADD COLUMN status varchar(20) DEFAULT 'chua_lien_he' NOT NULL");
      $this->wpdb->query("UPDATE {$this->table_leads} SET status = 'chua_lien_he' WHERE status IS NULL OR status = ''");
    }

    if (!in_array('receiver_name', $columns)) {
      $this->wpdb->query("ALTER TABLE {$this->table_leads} ADD COLUMN receiver_name varchar(100) DEFAULT '' NOT NULL");
    }

    if (!in_array('current_page_url', $columns)) {
      $this->wpdb->query("ALTER TABLE {$this->table_leads} ADD COLUMN current_page_url varchar(500) DEFAULT '' NOT NULL");
    }
  }

  /**
   * Insert a new lead
   */
  public function insert_lead($data) {
    return $this->wpdb->insert($this->table_leads, [
      'created_at' => current_time('mysql'),
      'name' => sanitize_text_field($data['name']),
      'phone' => sanitize_text_field($data['phone']),
      'email' => sanitize_email($data['email']),
      'receiver_name' => sanitize_text_field($data['receiver_name']),
      'message' => sanitize_textarea_field($data['message']),
      'current_page_url' => sanitize_url($data['current_page_url'])
    ]);
  }

  /**
   * Get leads with pagination
   */
  public function get_leads($per_page = 20, $offset = 0) {
    return $this->wpdb->get_results($this->wpdb->prepare(
      "SELECT * FROM {$this->table_leads} ORDER BY created_at DESC LIMIT %d OFFSET %d",
      $per_page,
      $offset
    ));
  }

  /**
   * Get total leads count
   */
  public function get_leads_count() {
    return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table_leads}");
  }

  /**
   * Update lead status
   */
  public function update_lead_status($lead_id, $status) {
    return $this->wpdb->update(
      $this->table_leads,
      ['status' => $status],
      ['id' => $lead_id],
      ['%s'],
      ['%d']
    );
  }

  /**
   * Update lead receiver name
   */
  public function update_lead_receiver($lead_id, $receiver_name) {
    return $this->wpdb->update(
      $this->table_leads,
      ['receiver_name' => $receiver_name],
      ['id' => $lead_id],
      ['%s'],
      ['%d']
    );
  }

  /**
   * Delete lead
   */
  public function delete_lead($lead_id) {
    return $this->wpdb->delete($this->table_leads, ['id' => $lead_id], ['%d']);
  }

  /**
   * Get lead by ID
   */
  public function get_lead($lead_id) {
    return $this->wpdb->get_row($this->wpdb->prepare(
      "SELECT * FROM {$this->table_leads} WHERE id = %d",
      $lead_id
    ));
  }

  /**
   * Insert or update product
   */
  public function save_product($data, $product_id = 0) {
    $product_data = [
      'name' => sanitize_text_field($data['name']),
      'category' => sanitize_text_field($data['category']),
      'price' => sanitize_text_field($data['price']),
      'sale_price' => sanitize_text_field($data['sale_price']),
      'config' => sanitize_textarea_field($data['config']),
      'description' => sanitize_textarea_field($data['description'])
    ];

    if ($product_id > 0) {
      return $this->wpdb->update($this->table_products, $product_data, ['id' => $product_id]);
    } else {
      return $this->wpdb->insert($this->table_products, $product_data);
    }
  }

  /**
   * Get all products
   */
  public function get_products() {
    return $this->wpdb->get_results("SELECT * FROM {$this->table_products} ORDER BY category, name ASC");
  }

  /**
   * Get product by ID
   */
  public function get_product($product_id) {
    return $this->wpdb->get_row($this->wpdb->prepare(
      "SELECT * FROM {$this->table_products} WHERE id = %d",
      $product_id
    ));
  }

  /**
   * Delete product
   */
  public function delete_product($product_id) {
    return $this->wpdb->delete($this->table_products, ['id' => $product_id], ['%d']);
  }

  /**
   * Search products by keywords
   */
  public function search_products($keywords) {
    $product_keywords = ['cloud', 'vps', 'hosting', 'email', 'marketing', 'workspace', 'it', 'support', 'web', 'development', 'giá', 'khuyến mãi', 'cấu hình'];
    $found_keywords = array_intersect($keywords, $product_keywords);
    
    if (empty($found_keywords)) {
      return [];
    }

    $sql = "SELECT * FROM {$this->table_products} WHERE ";
    $conditions = [];
    
    foreach ($found_keywords as $keyword) {
      $conditions[] = $this->wpdb->prepare(
        "(name LIKE %s OR category LIKE %s OR description LIKE %s)",
        "%{$keyword}%",
        "%{$keyword}%",
        "%{$keyword}%"
      );
    }
    
    $sql .= implode(' OR ', $conditions);
    return $this->wpdb->get_results($sql);
  }

  /**
   * Seed sample products
   */
  public function seed_sample_products() {
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
      $exists = $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT id FROM {$this->table_products} WHERE name = %s",
        $product['name']
      ));
      
      if (!$exists) {
        $this->wpdb->insert($this->table_products, $product);
      }
    }
  }

  /**
   * Seed definitions into database
   */
  public function seed_definitions() {
    $definitions = [
      ['name' => 'VPS', 'category' => 'Định nghĩa chung', 'description' => 'Là một máy chủ ảo được tạo ra bằng cách chia một máy chủ vật lý thành nhiều máy chủ ảo độc lập. Mỗi VPS có hệ điều hành và tài nguyên (CPU, RAM, ổ cứng) riêng, hoạt động như một máy chủ riêng biệt với chi phí thấp hơn, phù hợp cho website có lượng truy cập lớn, máy chủ game, hoặc các dự án cần quyền quản trị cao.'],
      ['name' => 'Cloud Hosting', 'category' => 'Định nghĩa chung', 'description' => 'Là dịch vụ lưu trữ website trên một mạng lưới gồm nhiều máy chủ ảo hóa (đám mây). Dữ liệu được phân tán, giúp website có độ ổn định và thời gian hoạt động (uptime) rất cao, khả năng mở rộng tài nguyên linh hoạt, và bạn chỉ trả tiền cho những gì bạn sử dụng. Rất lý tưởng cho các trang thương mại điện tử và doanh nghiệp cần sự tin cậy.'],
      ['name' => 'Email Marketing', 'category' => 'Định nghĩa chung', 'description' => 'Là một chiến lược tiếp thị kỹ thuật số sử dụng email để quảng bá sản phẩm, dịch vụ và xây dựng mối quan hệ với khách hàng. Nó cho phép doanh nghiệp gửi thông điệp được cá nhân hóa đến đúng đối tượng, với chi phí thấp và khả năng đo lường hiệu quả chi tiết.'],
      ['name' => 'Google Workspace', 'category' => 'Định nghĩa chung', 'description' => 'Là bộ công cụ làm việc văn phòng và cộng tác trực tuyến của Google, bao gồm các ứng dụng như Gmail với tên miền riêng, Google Drive, Docs, Sheets, và Google Meet. Nó giúp các đội nhóm làm việc hiệu quả, linh hoạt từ mọi nơi và trên mọi thiết bị với độ bảo mật cao.'],
      ['name' => 'IT Support', 'category' => 'Định nghĩa chung', 'description' => 'Là dịch vụ hỗ trợ kỹ thuật công nghệ thông tin, chịu trách nhiệm giải quyết các sự cố, bảo trì và đảm bảo hệ thống máy tính, phần mềm, và mạng của một tổ chức hoạt động ổn định. Vai trò của IT Support là giúp người dùng khắc phục vấn đề kỹ thuật và duy trì hiệu suất công việc.'],
      ['name' => 'Web Development', 'category' => 'Định nghĩa chung', 'description' => 'Là công việc tạo ra các trang web và ứng dụng web. Quá trình này bao gồm hai phần chính: Front-end (giao diện người dùng nhìn thấy và tương tác) và Back-end (phần máy chủ xử lý logic và dữ liệu). Đây là một lĩnh vực kết hợp giữa thiết kế, lập trình và quản lý cơ sở dữ liệu.'],
    ];

    foreach ($definitions as $def) {
      $exists = $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT id FROM {$this->table_products} WHERE name = %s AND category = %s",
        $def['name'],
        $def['category']
      ));
      
      if (!$exists) {
        $this->wpdb->insert($this->table_products, [
          'name' => $def['name'],
          'category' => $def['category'],
          'description' => $def['description'],
          'price' => '',
          'sale_price' => '',
          'config' => ''
        ]);
      }
    }
  }
}
