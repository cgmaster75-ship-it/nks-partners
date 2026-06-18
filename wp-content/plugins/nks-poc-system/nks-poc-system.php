<?php
/**
 * Plugin Name: NKS All System (POC, PO, NLG)
 * Description: ระบบฟอร์มขอ POC, PO และ NLG เชื่อมต่อผ่าน API และ SOAP Web Service พร้อมระบบ Export Excel (Fixed Dropdown Shape)
 * Version: 3.5
 * Author: NKS Dev Team
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class NKS_POC_System {

    private $db_version = '3.0'; 
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'nks_poc_logs';

        register_activation_hook(__FILE__, array($this, 'install_db'));
        add_action('plugins_loaded', array($this, 'update_db_check'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_csv_export'));
        
        add_shortcode('nks_poc_form', array($this, 'render_poc_form'));
        add_shortcode('nks_po_form', array($this, 'render_po_form'));
        add_shortcode('nks_nlg_poc_form', array($this, 'render_nlg_poc_form'));
        add_shortcode('nks_nlg_po_form', array($this, 'render_nlg_po_form'));
        add_shortcode('nks_approval_form', array($this, 'render_approval_form'));
        
        add_action('init', array($this, 'handle_form_submission'));
    }

    // --- 1. Database Setup ---
    public function install_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            user_email varchar(100) NOT NULL,
            username varchar(100),
            form_type varchar(20) DEFAULT 'POC' NOT NULL, 
            project_id varchar(50),
            po_number varchar(50),
            license_number varchar(50),
            ip_address varchar(50) NOT NULL,
            mac_address varchar(50) NOT NULL,
            status varchar(50) NOT NULL,
            soap_response text,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('nks_poc_db_version', $this->db_version);
    }

    public function update_db_check() {
        if (get_site_option('nks_poc_db_version') != $this->db_version) { $this->install_db(); }
    }

    // --- 2. Admin Menu ---
    public function add_admin_menu() {
        add_menu_page('NKS Logs', 'NKS Logs', 'manage_options', 'nks-system-logs', array($this, 'render_admin_page'), 'dashicons-list-view', 6);
    }

    public function handle_csv_export() {
        if (isset($_POST['nks_export_csv']) && current_user_can('manage_options')) {
            global $wpdb;
            $results = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY time DESC", ARRAY_A);
            if (empty($results)) return;
            $filename = 'nks_logs_export_' . date('Y-m-d_H-i-s') . '.csv';
            ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, array('ID', 'Date/Time', 'Username', 'Email', 'Form Type', 'Project ID', 'PO Number', 'License Number', 'IP Address', 'MAC Address', 'Status', 'Response Detail'));
            foreach ($results as $row) {
                fputcsv($output, array($row['id'], $row['time'], $row['username'], $row['user_email'], $row['form_type'], $row['project_id'], $row['po_number'], $row['license_number'], $row['ip_address'], $row['mac_address'], $row['status'], $row['soap_response']));
            }
            fclose($output);
            exit();
        }
    }

    public function render_admin_page() {
        global $wpdb;
        $filter_form_type = isset($_GET['filter_form_type']) ? sanitize_text_field($_GET['filter_form_type']) : '';
        $where_sql = ""; $query_args = array();
        if (!empty($filter_form_type)) { $where_sql = " WHERE form_type = %s"; $query_args[] = $filter_form_type; }
        
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        $count_sql = "SELECT COUNT(id) FROM $this->table_name" . $where_sql;
        if(!empty($query_args)) { $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $query_args)); } else { $total_items = $wpdb->get_var($count_sql); }
        $total_pages = ceil($total_items / $per_page);
        
        $sql = "SELECT * FROM $this->table_name" . $where_sql . " ORDER BY time DESC LIMIT %d OFFSET %d";
        $main_query_args = $query_args; $main_query_args[] = $per_page; $main_query_args[] = $offset;
        $results = $wpdb->get_results($wpdb->prepare($sql, $main_query_args));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">บันทึกการทำรายการ (System Logs)</h1>
            <form method="post" action="" style="display:inline-block; margin-left: 10px;"><input type="submit" name="nks_export_csv" class="button button-primary" value="Export to Excel (CSV)" /></form>
            <hr class="wp-header-end">
            <form method="get" action="">
                <input type="hidden" name="page" value="nks-system-logs" />
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="filter_form_type">
                            <option value="">View all forms</option>
                            <option value="POC" <?php selected($filter_form_type, 'POC'); ?>>POC Generator</option>
                            <option value="PO" <?php selected($filter_form_type, 'PO'); ?>>PO Generator</option>
                            <option value="NLG_POC" <?php selected($filter_form_type, 'NLG_POC'); ?>>NLG POC Generator</option>
                            <option value="NLG_PO" <?php selected($filter_form_type, 'NLG_PO'); ?>>NLG PO Generator</option>
                        </select>
                        <input type="submit" id="post-query-submit" class="button" value="Filter">
                    </div>
                    <div class="tablenav-pages"><span class="displaying-num"><?php echo $total_items; ?> items</span><?php echo paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','total'=>$total_pages,'current'=>$page)); ?></div>
                    <br class="clear">
                </div>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Date</th><th>User</th><th>Type</th><th>Reference</th><th>IP / MAC</th><th>Status</th><th>Response Detail</th></tr></thead>
                <tbody>
                    <?php if(!empty($results)): foreach($results as $row): ?>
                        <tr>
                            <td><?php echo $row->time; ?></td>
                            <td><strong><?php echo esc_html($row->username); ?></strong><br><small><?php echo esc_html($row->user_email); ?></small></td>
                            <td><span class="badge" style="background:#ddd; padding:2px 5px; border-radius:3px; font-weight:bold;"><?php echo esc_html($row->form_type); ?></span></td>
                            <td><?php if(strpos($row->form_type, 'PO')!==false && strpos($row->form_type,'POC')===false) echo 'PO: '.esc_html($row->po_number).'<br><small>Lic: '.esc_html($row->license_number).'</small>'; else echo 'Project: '.esc_html($row->project_id); ?></td>
                            <td>IP: <?php echo esc_html($row->ip_address); ?><br>MAC: <?php echo esc_html($row->mac_address); ?></td>
                            <td><span style="background:<?php echo (in_array($row->status,['Success']))?'#46b450':((in_array($row->status,['Repeated']))?'#ffb900':'#dc3232'); ?>; color:white; padding:2px 6px; border-radius:4px;"><?php echo esc_html($row->status); ?></span></td>
                            <td><small><?php echo esc_html(substr($row->soap_response, 0, 50)); ?>...</small></td>
                        </tr>
                    <?php endforeach; else: ?><tr><td colspan="7">ไม่พบข้อมูล</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // --- 3. Helper: Get Projects via API ---
    private function get_projects($user_email) {
        $api_url = "https://partner.netkasystem.co.th/nks_get_projects.php"; 
        $api_key = "nks_secret_2025"; 

        $request_url = add_query_arg(array('email' => $user_email, 'token' => $api_key), $api_url);
        $response = wp_remote_get($request_url, array('timeout' => 15));

        if (is_wp_error($response)) {
            if(current_user_can('manage_options')) { return array((object)array('project_id' => 'ERR', 'project_name' => 'API Connection Error: ' . $response->get_error_message())); }
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
             if(current_user_can('manage_options')) { return array((object)array('project_id' => 'ERR', 'project_name' => 'Invalid JSON response from API')); }
            return array();
        }

        if (isset($data->status) && $data->status == 'error') {
             if(current_user_can('manage_options')) { return array((object)array('project_id' => 'ERR', 'project_name' => 'API Error: ' . $data->message)); }
            return array();
        }

        if (isset($data->status) && $data->status == 'success' && !empty($data->data)) {
            $projects = array();
            foreach($data->data as $item) {
                $projects[] = (object) array('project_id' => $item->project_id, 'project_name' => $item->project_name);
            }
            return $projects;
        } else {
             if(current_user_can('manage_options')) { return array((object)array('project_id' => 'INFO', 'project_name' => 'No projects found for: ' . $user_email)); }
            return array();
        }
    }

    // --- 4. Form Submission Handler ---
    public function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        
        global $wpdb;
        $current_user = wp_get_current_user();
        $email = $current_user->user_email;
        $username = $current_user->display_name; 

        $wsdl_std = "https://partner.netkasystem.co.th/nks_soap_proxy.php?type=std";
        $wsdl_nlg = "https://partner.netkasystem.co.th/nks_soap_proxy.php?type=nlg";

        if (isset($_POST['nks_poc_submit']) && wp_verify_nonce($_POST['nks_nonce_field'], 'nks_action')) {
            $this->process_submission('POC', $wsdl_std, 'ws_olg_poc', $email, $username, $_POST);
        }
        if (isset($_POST['nks_po_submit']) && wp_verify_nonce($_POST['nks_nonce_field'], 'nks_action')) {
            $this->process_submission('PO', $wsdl_std, 'ws_olg_po', $email, $username, $_POST);
        }
        if (isset($_POST['nks_nlg_poc_submit']) && wp_verify_nonce($_POST['nks_nonce_field'], 'nks_action')) {
            $this->process_submission('NLG_POC', $wsdl_nlg, 'ws_olg_poc_NPA', $email, $username, $_POST);
        }
        if (isset($_POST['nks_nlg_po_submit']) && wp_verify_nonce($_POST['nks_nonce_field'], 'nks_action')) {
            $this->process_submission('NLG_PO', $wsdl_nlg, 'ws_olg_po_NPA', $email, $username, $_POST);
        }
    }

    private function process_submission($type, $wsdl_url, $soap_func, $email, $username, $post_data) {
        global $wpdb;
        $ip = sanitize_text_field($post_data['ip_address']);
        $mac = sanitize_text_field($post_data['mac_address']);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) wp_die('Invalid IP Address');
        if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) wp_die('Invalid MAC Address');

        $params = array();
        $project_id = '';
        $po_number = '';
        $license_number = '';

        if ($type == 'POC' || $type == 'NLG_POC') {
            $project_id = sanitize_text_field($post_data['project']);
            $params = array(array('strProject'=>$project_id, 'strIPAddress'=>$ip, 'strMacAddress'=>$mac, 'strEmail'=>$email));
        } else {
            $po_number = sanitize_text_field($post_data['po_number']);
            $license_number = sanitize_text_field($post_data['license_number']);
            $params = array(array('strLicenseNumber'=>$license_number, 'strPONumber'=>$po_number, 'strIPAddress'=>$ip, 'strMacAddress'=>$mac, 'strEmail'=>$email));
        }

        try {
            $location_url = str_replace('?wsdl', '', $wsdl_url); 
            $client = new SoapClient($wsdl_url, array('location' => $location_url, 'connection_timeout' => 10, 'exceptions' => true));
            $result = $client->__SoapCall($soap_func, $params);
            $arr = get_object_vars($result);
            $result_key = $soap_func . 'Result';
            $x = isset($arr[$result_key]) ? $arr[$result_key] : '';
            
            $status = $x;
            if (strpos($x, '||') !== false) { $parts = explode("||", $x); $status = $parts[0]; }

            $wpdb->insert($this->table_name, array(
                'time' => current_time('mysql'), 'user_email' => $email, 'username' => $username, 'form_type' => $type,
                'project_id' => $project_id, 'po_number' => $po_number, 'license_number' => $license_number,
                'ip_address' => $ip, 'mac_address' => $mac,
                'status' => $status, 'soap_response' => $x
            ));

            if ($status == 'Success') { $this->send_success_email($email, $type, $x); }
            $this->handle_redirect($type, $status, $x);

        } catch (Exception $e) { wp_die("Error connecting to Licensing Server ($type). Please try again later."); }
    }

    private function send_success_email($to_email, $type, $detail) {
        $subject = "Product Key Request Successful - " . get_bloginfo('name');
        $message = "Dear Partner,\n\nYour request for " . $type . " has been successfully processed.\nStatus: Success\nDetail: " . $detail . "\n\nPlease check your registered email or system for the Product Key.\n\nBest Regards,\nNetka System";
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($to_email, $subject, $message, $headers);
    }

    private function handle_redirect($type, $status, $full_response) {
        $home = home_url(); $referer = wp_get_referer(); if(!$referer) $referer = $_SERVER['REQUEST_URI'];
        if ($status == 'Success') { wp_redirect(add_query_arg('nks_status', 'success', $referer)); exit; }
        elseif ($status == 'Expired') { wp_redirect(add_query_arg('nks_status', 'expired', $referer)); exit; }
        elseif ($status == 'Repeated') { if ($type == 'PO' || $type == 'NLG_PO') wp_redirect($home . '/ip-mac-not-true'); else wp_redirect($home . '/approval-request?Detail=' . urlencode($full_response)); exit; }
        else { wp_redirect(add_query_arg(array('nks_status' => 'unknown', 'nks_detail' => urlencode($full_response)), $referer)); exit; }
    }

    // --- 5. Render Functions ---
    public function render_poc_form() { return $this->render_common_form('POC', 'Standard POC Request'); }
    public function render_po_form() { return $this->render_common_form('PO', 'Standard PO Request'); }
    public function render_nlg_poc_form() { return $this->render_common_form('NLG_POC', 'NLG POC Request'); }
    public function render_nlg_po_form() { return $this->render_common_form('NLG_PO', 'NLG PO Request'); }

    public function render_approval_form() {
        if (!is_user_logged_in()) return '<div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:5px;">กรุณาเข้าสู่ระบบก่อนทำรายการ</div>';
        
        $detail_str = isset($_GET['Detail']) ? sanitize_text_field(urldecode($_GET['Detail'])) : '';
        $parts = !empty($detail_str) ? explode('||', $detail_str) : array();
        
        $stage = isset($parts[0]) ? $parts[0] : 'Repeated';
        $project_id = isset($parts[1]) ? $parts[1] : '-';
        $project_name = isset($parts[4]) ? $parts[4] : '-';
        $sale_name = isset($parts[6]) ? $parts[6] : '-';
        $sale_email = isset($parts[10]) ? $parts[10] : '-';

        if (isset($_POST['nks_approval_submit']) && wp_verify_nonce($_POST['nks_approval_nonce'], 'nks_approval_action')) {
            $reason = sanitize_textarea_field($_POST['nks_reason']);
            $current_user = wp_get_current_user();
            
            $to = $sale_email;
            if (empty($to) || $to === '-') {
                $to = get_option('admin_email');
            }
            
            $subject = "POC Approval Request for Project: " . $project_id . " - " . get_bloginfo('name');
            $message = "Dear " . $sale_name . ",\n\n" .
                       "A partner has requested approval for a repeated POC key.\n\n" .
                       "Partner Details:\n" .
                       "- Partner Email: " . $current_user->user_email . "\n" .
                       "- Partner Name: " . $current_user->display_name . "\n\n" .
                       "Project Details:\n" .
                       "- Project ID: " . $project_id . "\n" .
                       "- Project Name: " . $project_name . "\n\n" .
                       "Justification/Reason:\n" . $reason . "\n\n" .
                       "Best Regards,\n" . get_bloginfo('name');
                       
            $headers = array('Content-Type: text/plain; charset=UTF-8', 'Cc: ' . $current_user->user_email);
            wp_mail($to, $subject, $message, $headers);
            
            return '<div style="padding:20px; background:#d1fae5; color:#065f46; border-radius:12px; font-family:\'Noto Sans Thai\',sans-serif; text-align:center; font-weight:bold; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">' .
                   'ส่งคำขออนุมัติไปยังทีมขาย (' . esc_html($sale_name) . ') เรียบร้อยแล้วครับ ระบบจะติดต่อกลับผ่านอีเมลของคุณโดยเร็ว' .
                   '</div>';
        }

        ob_start();
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            #nks-approval-wrapper { font-family: 'Noto Sans Thai', sans-serif; box-sizing: border-box; width: 100%; background: linear-gradient(135deg, #fef3c7 0%, #fef08a 100%); padding: 60px 20px; border-radius: 12px; display: flex; justify-content: center; align-items: center; }
            .nks-approval-card { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.5); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border-radius: 16px; padding: 30px; width: 100%; max-width: 600px; }
            .nks-approval-title { font-size: 22px; font-weight: 700; color: #1f2937; margin-bottom: 15px; text-align: center; }
            .nks-approval-desc { font-size: 15px; color: #4b5563; margin-bottom: 20px; line-height: 1.6; }
            .nks-info-box { background: #f3f4f6; padding: 15px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; color: #374151; }
            .nks-info-box strong { color: #1f2937; }
            .nks-textarea { width: 100%; height: 120px; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 15px; outline: none; transition: border-color 0.2s; resize: none; margin-bottom: 15px; }
            .nks-textarea:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2); }
            .nks-btn-approval { width: 100%; padding: 12px; font-size: 16px; font-weight: 600; color: white; background: linear-gradient(to right, #f59e0b, #d97706); border: none; border-radius: 50px; cursor: pointer; transition: transform 0.1s; }
            .nks-btn-approval:hover { background: linear-gradient(to right, #d97706, #b45309); transform: translateY(-1px); }
        </style>
        <div id="nks-approval-wrapper">
            <div class="nks-approval-card">
                <h3 class="nks-approval-title">ส่งคำขออนุมัติคีย์ทดลอง (POC Extension Approval)</h3>
                <p class="nks-approval-desc">เนื่องจากระบบตรวจพบว่าโปรเจคนี้เคยขอคีย์ทดลอง (POC) ไปแล้ว หากคุณมีความจำเป็นต้องการขอขยายเวลาหรือรับคีย์เพิ่ม กรุณากรอกเหตุผลด้านล่างเพื่อส่งข้อมูลขออนุมัติไปยังผู้ดูแลระบบหรือทีมขาย (Sales Representative) ที่ดูแลโปรเจคนี้โดยตรงครับ</p>
                
                <div class="nks-info-box">
                    <strong>Project ID:</strong> <?php echo esc_html($project_id); ?><br>
                    <strong>Project Name:</strong> <?php echo esc_html($project_name); ?><br>
                    <strong>Sales Representative:</strong> <?php echo esc_html($sale_name); ?> (<?php echo esc_html($sale_email); ?>)
                </div>
                
                <form action="" method="POST">
                    <?php wp_nonce_field('nks_approval_action', 'nks_approval_nonce'); ?>
                    <textarea name="nks_reason" required placeholder="กรุณาระบุเหตุผลหรือความจำเป็นในการขอคีย์เพิ่มเติม..." class="nks-textarea"></textarea>
                    <button type="submit" name="nks_approval_submit" class="nks-btn-approval">ส่งคำขออนุมัติ (Submit Request)</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_common_form($type, $title) {
        if (!is_user_logged_in()) return '<div style="padding:15px; background:#fee2e2; color:#b91c1c; border-radius:5px;">กรุณาเข้าสู่ระบบก่อนทำรายการ</div>';
        $current_user = wp_get_current_user();
        $submit_name = '';
        if($type == 'POC') $submit_name = 'nks_poc_submit';
        elseif($type == 'PO') $submit_name = 'nks_po_submit';
        elseif($type == 'NLG_POC') $submit_name = 'nks_nlg_poc_submit';
        elseif($type == 'NLG_PO') $submit_name = 'nks_nlg_po_submit';

        ob_start();
        $projects = null;
        if(strpos($type, 'POC') !== false) { $projects = $this->get_projects($current_user->user_email); }
        ?>
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            #nks-app-wrapper { font-family: 'Noto Sans Thai', sans-serif; box-sizing: border-box; width: 100%; background: linear-gradient(135deg, #ffe4e6 0%, #f3e8ff 50%, #dbeafe 100%); padding: 130px 20px; border-radius: 12px; position: relative; overflow: hidden; min-height: 600px; display: flex; justify-content: center; align-items: center; }
            #nks-app-wrapper * { box-sizing: border-box; }
            .nks-blob { position: absolute; border-radius: 50%; filter: blur(60px); opacity: 0.4; z-index: 0; }
            .nks-blob-1 { width: 300px; height: 300px; background: #fecdd3; top: -50px; left: -50px; }
            .nks-blob-2 { width: 300px; height: 300px; background: #bfdbfe; top: -50px; right: -50px; }
            .nks-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); border-radius: 16px; padding: 40px; width: 100%; max-width: 800px; position: relative; z-index: 10; }
            .nks-main-head { font-size: 28px; font-weight: 700; color: #1f2937; }
            .nks-sub-head { font-size: 36px; font-weight: 700; color: #1f2937; margin-top: 5px; }
            .nks-title { font-size: 28px; font-weight: 700; text-align: center; color: #1f2937; margin-bottom: 30px; line-height: 1.2; }
            .nks-title span { color: #ef4444; }
            .nks-form-group { margin-bottom: 20px; }
            .nks-label { display: block; font-size: 16px; font-weight: 500; color: #374151; margin-bottom: 8px; }
            .nks-label span { color: #ef4444; }
            
            /* -- CUSTOM DROPDOWN CSS -- */
            .nks-input { width: 100%; padding: 12px 20px; font-size: 16px; border: 1px solid #d1d5db; border-radius: 50px; background-color: #fff; color: #111827; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
            .nks-input:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2); }
            
            /* Hidden real Select */
            .nks-select { display: none; } 

            /* Custom Select UI */
            .nks-custom-select-wrapper { position: relative; user-select: none; width: 100%; }
            .nks-custom-select { position: relative; display: flex; flex-direction: column; }
            .nks-custom-select__trigger {
                position: relative; display: flex; align-items: center; justify-content: space-between;
                padding: 12px 20px; font-size: 16px; font-weight: 400; color: #111827;
                background: #fff; border: 1px solid #d1d5db; border-radius: 50px; cursor: pointer; transition: all 0.2s;
            }
            .nks-custom-select__trigger:after {
                content: ''; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23374151' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
                background-size: contain; background-repeat: no-repeat; width: 16px; height: 16px; transition: transform 0.2s;
            }
            .nks-custom-select.open .nks-custom-select__trigger:after { transform: rotate(180deg); }
            /* Removed border-radius change on open state as requested */
            .nks-custom-select.open .nks-custom-select__trigger { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2); } 

            .nks-custom-options {
                position: absolute; display: none; top: 110%; left: 0; right: 0;
                border: 1px solid #e5e7eb; border-radius: 20px; /* ROUNDED DROPDOWN BOX */
                background: #fff; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
                z-index: 100; overflow: hidden;
            }
            .nks-custom-select.open .nks-custom-options { display: block; }
            
            .nks-custom-option {
                position: relative; display: block; padding: 12px 20px; font-size: 16px; color: #374151; cursor: pointer; transition: all 0.2s; border-bottom: 1px solid #f3f4f6;
            }
            .nks-custom-option:last-child { border-bottom: none; }
            .nks-custom-option:hover, .nks-custom-option.selected { background-color: #fef2f2; color: #ef4444; }
            /* ---------------------------- */

            .nks-error { color: #ef4444; font-size: 14px; margin-top: 5px; display: none; }
            .nks-error.visible { display: block; }
            .nks-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            @media (max-width: 600px) { .nks-grid { grid-template-columns: 1fr; } }
            .nks-btn-submit { width: 100%; padding: 14px; font-size: 18px; font-weight: 600; color: white; background: linear-gradient(to right, #ef4444, #dc2626); border: none; border-radius: 50px; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.3); transition: transform 0.1s, box-shadow 0.1s; margin-top: 20px; }
            .nks-btn-submit:hover { background: linear-gradient(to right, #dc2626, #b91c1c); transform: translateY(-1px); box-shadow: 0 6px 8px -1px rgba(220, 38, 38, 0.4); }
            
            /* Modal CSS */
            .nks-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; display: none; justify-content: center; align-items: center; }
            .nks-modal-content { background: white; padding: 40px; border-radius: 16px; text-align: center; max-width: 500px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: nksFadeIn 0.3s ease-out; }
            @keyframes nksFadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
            .nks-modal-title { font-size: 26px; font-weight: bold; margin: 15px 0 10px; color: #1f2937; }
            .nks-modal-message { font-size: 16px; color: #4b5563; line-height: 1.5; margin-bottom: 15px; }
            .nks-detail-list { text-align: left; background: #f9fafb; padding: 15px; border-radius: 10px; font-size: 14px; margin-top: 10px; color: #374151; display: none; }
            .nks-detail-list strong { color: #1f2937; }
            .nks-modal-close-btn { margin-top: 25px; padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 30px; cursor: pointer; font-size: 16px; transition: background 0.2s; }
            .nks-modal-close-btn:hover { background: #4b5563; }
        </style>

        <div id="nks-modal" class="nks-modal-overlay">
            <div class="nks-modal-content">
                <div id="nks-modal-icon"></div>
                <h3 id="nks-modal-title" class="nks-modal-title"></h3>
                <p id="nks-modal-message" class="nks-modal-message"></p>
                <div id="nks-detail-box" class="nks-detail-list"></div>
                <button onclick="closeNksModal()" class="nks-modal-close-btn">Close</button>
            </div>
        </div>

        <div id="nks-app-wrapper">
            <div class="nks-blob nks-blob-1"></div>
            <div class="nks-blob nks-blob-2"></div>
            <div class="nks-card">
                <div style="text-align: center; margin-bottom: 30px;">
                    <?php if($type == 'POC'): ?>
                        <div class="nks-main-head">POC Generator</div>
                        <div class="nks-sub-head">for NSDX and NDPP</div>
                    <?php elseif($type == 'PO'): ?>
                        <div class="nks-main-head">PO Generator</div>
                        <div class="nks-sub-head">for NSDX and NDPP</div>
                    <?php elseif($type == 'NLG_POC'): ?>
                        <div class="nks-main-head">POC Generator</div>
                        <div class="nks-sub-head">for NLG, N-AIOps and Entrust</div>
                    <?php elseif($type == 'NLG_PO'): ?>
                        <div class="nks-main-head">PO Generator</div>
                        <div class="nks-sub-head">for NLG, N-AIOps and Entrust</div>
                    <?php else: ?>
                        <h2 class="nks-title"><?php echo str_replace('Request', '', $title); ?> <span>|</span> Form</h2>
                    <?php endif; ?>
                </div>
                
                <form action="" method="POST" id="nks-main-form">
                    <?php wp_nonce_field('nks_action', 'nks_nonce_field'); ?>

                    <?php if(strpos($type, 'POC') !== false): ?>
                        <div class="nks-form-group">
                            <label class="nks-label">Project <span>*</span></label>
                            <!-- Original Select (Hidden via CSS) -->
                            <select name="project" required class="nks-select">
                                <option value="">Please Select</option>
                                <?php if($projects): foreach($projects as $p): ?>
                                    <option value="<?php echo esc_attr($p->project_id); ?>"><?php echo esc_html($p->project_id . ' - ' . $p->project_name); ?></option>
                                <?php endforeach; else: ?>
                                    <option value="" disabled>No projects found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if(strpos($type, 'PO') !== false && strpos($type, 'POC') === false): ?>
                        <div class="nks-grid">
                            <div class="nks-form-group">
                                <label class="nks-label">PO Number <span>*</span></label>
                                <input type="text" name="po_number" required placeholder="Example: ext_000001" class="nks-input">
                            </div>
                            <div class="nks-form-group">
                                <label class="nks-label">License Number <span>*</span></label>
                                <input type="text" name="license_number" required placeholder="Example: 17090001" class="nks-input">
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="nks-grid">
                        <div class="nks-form-group">
                            <label class="nks-label">IP Address <span>*</span></label>
                            <input type="text" name="ip_address" required placeholder="192.168.1.1" class="nks-input ip_input">
                            <p class="nks-error ip_error">Invalid IP Address</p>
                        </div>
                        <div class="nks-form-group">
                            <label class="nks-label">MAC Address <span>*</span></label>
                            <input type="text" name="mac_address" required placeholder="78:4F:43:71:47:1B" class="nks-input mac_input">
                            <p class="nks-error mac_error">Invalid MAC Address</p>
                        </div>
                    </div>

                    <button type="submit" name="<?php echo $submit_name; ?>" class="nks-btn-submit">SUBMIT DATA</button>
                </form>
            </div>
        </div>

        <script>
            // Init Custom Select
            (function() {
                var select = document.querySelector('.nks-select');
                if (select) {
                    var wrapper = document.createElement('div');
                    wrapper.classList.add('nks-custom-select-wrapper');
                    select.parentNode.insertBefore(wrapper, select);
                    wrapper.appendChild(select);

                    var customSelect = document.createElement('div');
                    customSelect.classList.add('nks-custom-select');
                    
                    var trigger = document.createElement('div');
                    trigger.classList.add('nks-custom-select__trigger');
                    trigger.innerHTML = '<span>Please Select</span>';
                    customSelect.appendChild(trigger);

                    var optionsDiv = document.createElement('div');
                    optionsDiv.classList.add('nks-custom-options');
                    
                    // Loop options
                    for (var i = 0; i < select.options.length; i++) {
                        var opt = select.options[i];
                        if (opt.disabled) continue; 
                        var div = document.createElement('div');
                        div.classList.add('nks-custom-option');
                        div.textContent = opt.textContent;
                        div.dataset.value = opt.value;
                        
                        div.addEventListener('click', function() {
                            select.value = this.dataset.value;
                            trigger.querySelector('span').textContent = this.textContent;
                            customSelect.classList.remove('open');
                            var allOpts = customSelect.querySelectorAll('.nks-custom-option');
                            allOpts.forEach(function(o){o.classList.remove('selected')});
                            this.classList.add('selected');
                        });
                        optionsDiv.appendChild(div);
                    }
                    customSelect.appendChild(optionsDiv);
                    wrapper.appendChild(customSelect);

                    // Toggle
                    trigger.addEventListener('click', function() {
                        customSelect.classList.toggle('open');
                    });

                    // Click outside
                    document.addEventListener('click', function(e) {
                        if (!wrapper.contains(e.target)) {
                            customSelect.classList.remove('open');
                        }
                    });
                }
            })();

            function closeNksModal() {
                document.getElementById('nks-modal').style.display = 'none';
                var url = new URL(window.location.href);
                url.searchParams.delete('nks_status');
                url.searchParams.delete('nks_detail');
                window.history.replaceState({}, document.title, url);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var urlParams = new URLSearchParams(window.location.search);
                var status = urlParams.get('nks_status');
                var modal = document.getElementById('nks-modal');
                var title = document.getElementById('nks-modal-title');
                var msg = document.getElementById('nks-modal-message');
                var icon = document.getElementById('nks-modal-icon');
                var detailBox = document.getElementById('nks-detail-box');

                if (status === 'success') {
                    icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:64px;height:64px;color:#22c55e;margin:0 auto;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                    title.innerText = 'Thank You!';
                    msg.innerText = 'Thank you for your request. Product key has been generated. Please check your email.';
                    modal.style.display = 'flex';
                } else if (status === 'expired') {
                    icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:64px;height:64px;color:#ef4444;margin:0 auto;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zM12 15.75h.007v.008H12v-.008z" /></svg>';
                    title.innerText = 'Trial product key expired!';
                    msg.innerText = 'Trial product key of this project expired. Please contact our sale representative if require.';
                    modal.style.display = 'flex';
                } else if (status === 'unknown') {
                    icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:64px;height:64px;color:#f59e0b;margin:0 auto;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>';
                    
                    var rawDetail = urlParams.get('nks_detail');
                    var detailStr = rawDetail ? decodeURIComponent(rawDetail.replace(/\+/g, ' ')) : '';
                    var parts = detailStr.split('||');
                    
                    if (parts.length >= 1) {
                        var stage = parts[0] || 'Unknown';
                        var projectID = parts[1] || '-';
                        var projectName = parts[4] || '-';
                        var saleName = parts[6] || '-';
                        var emailSale = parts[10] || '-';

                        title.innerText = stage;
                        msg.innerHTML = 'Status: ' + stage + '<br>Please contact sale representative.';
                        
                        detailBox.style.display = 'block';
                        detailBox.innerHTML = '<strong>Project:</strong> ' + projectID + ' : ' + projectName + '<br>' + '<strong>Sale Contact:</strong> ' + saleName + ' (' + emailSale + ')';
                    } else {
                        title.innerText = 'Notification';
                        msg.innerText = detailStr || 'Data Not Found';
                    }
                    modal.style.display = 'flex';
                }
            });

            (function() {
                var form = document.getElementById('nks-main-form');
                if(!form) return;
                var ipInput = form.querySelector('.ip_input'); var macInput = form.querySelector('.mac_input');
                var ipError = form.querySelector('.ip_error'); var macError = form.querySelector('.mac_error');
                if(macInput) { macInput.addEventListener('keyup', function(e) { var r = macInput.value.replace(/[^a-fA-F0-9]/g, ""); var f = ""; for(var i=0; i<r.length; i++) { if(i%2 == 0 && i!=0) f += ":"; f += r[i]; } if(f.length <= 17 && e.key != "Backspace") macInput.value = f.toUpperCase(); }); }
                form.addEventListener('submit', function(e) { var ipRegex = /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/; var macRegex = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/; var valid = true; if (ipInput && !ipRegex.test(ipInput.value)) { ipError.style.display = 'block'; valid = false; } else if(ipError) { ipError.style.display = 'none'; } if (macInput && !macRegex.test(macInput.value)) { macError.style.display = 'block'; valid = false; } else if(macError) { macError.style.display = 'none'; } if (!valid) e.preventDefault(); });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}

new NKS_POC_System();