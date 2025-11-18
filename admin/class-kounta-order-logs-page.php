<?php
/**
 * Kounta Order Logs Admin Page
 * 
 * Provides a UI for viewing, searching, and managing order sync logs
 *
 * @package BrewHQ_Kounta
 * @since 2.1.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Order_Logs_Page {
    
    /**
     * Initialize the page
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_kounta_clear_order_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_kounta_download_order_log', array($this, 'ajax_download_log'));
        add_action('wp_ajax_kounta_get_diagnostic_report', array($this, 'ajax_get_diagnostic_report'));
    }
    
    /**
     * Add menu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'xwcpos-integration',
            'Order Sync Logs',
            'Order Sync Logs',
            'manage_options',
            'kounta-order-logs',
            array($this, 'render_page')
        );
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_kounta-order-logs') {
            return;
        }
        
        wp_enqueue_style('kounta-order-logs', plugins_url('css/order-logs.css', __FILE__), array(), '1.0.0');
        wp_enqueue_script('kounta-order-logs', plugins_url('js/order-logs.js', __FILE__), array('jquery'), '1.0.0', true);
        
        wp_localize_script('kounta-order-logs', 'kountaOrderLogs', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kounta_order_logs'),
        ));
    }
    
    /**
     * Render the page
     */
    public function render_page() {
        // Load logger
        require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-order-logger.php';
        
        // Get filter parameters
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        
        // Get log file info
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/brewhq-kounta-orders.log';
        $log_exists = file_exists($log_file);
        $log_size = $log_exists ? size_format(filesize($log_file)) : 'N/A';
        $log_modified = $log_exists ? date('Y-m-d H:i:s', filemtime($log_file)) : 'N/A';
        
        // Get recent logs
        $logs = $log_exists ? Kounta_Order_Logger::get_recent_logs($limit, $order_id) : array();
        
        // Get failed orders from queue
        $failed_queue = get_option('xwcpos_failed_orders', array());
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Order Sync Logs</h1>
            <a href="#" class="page-title-action" id="refresh-logs">Refresh</a>
            <hr class="wp-header-end">

            <p class="description" style="margin-top: 10px; margin-bottom: 20px;">
                View and manage order synchronization logs. Monitor failed orders, download diagnostic reports, and troubleshoot sync issues.
            </p>

            <div class="kounta-logs-header">
                <div class="log-stats">
                    <div class="stat-box">
                        <span class="stat-label">Log File Size</span>
                        <span class="stat-value"><?php echo esc_html($log_size); ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Last Modified</span>
                        <span class="stat-value"><?php echo esc_html($log_modified); ?></span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-label">Failed Orders</span>
                        <span class="stat-value <?php echo count($failed_queue) > 0 ? 'has-errors' : ''; ?>">
                            <?php echo count($failed_queue); ?>
                        </span>
                    </div>
                </div>

                <div class="log-actions">
                    <button class="button" id="download-log">Download Log</button>
                    <button class="button" id="clear-logs">Clear Logs</button>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="kounta-logs-filters">
                <h3 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600;">Filter Logs</h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="kounta-order-logs" />

                    <div class="filter-group">
                        <label for="order_id">Order ID</label>
                        <input type="number" name="order_id" id="order_id" value="<?php echo esc_attr($order_id); ?>" placeholder="e.g. 12345" />
                    </div>

                    <div class="filter-group">
                        <label for="limit">Show Entries</label>
                        <select name="limit" id="limit">
                            <option value="25" <?php selected($limit, 25); ?>>Last 25</option>
                            <option value="50" <?php selected($limit, 50); ?>>Last 50</option>
                            <option value="100" <?php selected($limit, 100); ?>>Last 100</option>
                            <option value="200" <?php selected($limit, 200); ?>>Last 200</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="button button-primary">Apply Filters</button>
                        <a href="?page=kounta-order-logs" class="button">Clear</a>
                    </div>
                </form>
            </div>
            
            <!-- Failed Orders Queue -->
            <?php if (count($failed_queue) > 0): ?>
            <div class="kounta-failed-orders">
                <h2 style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; color: #d63638; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-warning" style="font-size: 20px;"></span>
                    Failed Orders Queue (<?php echo count($failed_queue); ?>)
                </h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Order ID</th>
                            <th>Error</th>
                            <th style="width: 180px;">Failed At</th>
                            <th style="width: 100px;">Retry Count</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_queue as $failed_order): ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('post.php?post=' . $failed_order['order_id'] . '&action=edit'); ?>" target="_blank">
                                    <strong>#<?php echo esc_html($failed_order['order_id']); ?></strong>
                                </a>
                            </td>
                            <td>
                                <strong style="color: #d63638;"><?php echo esc_html($failed_order['error']['error'] ?? 'Unknown'); ?></strong><br/>
                                <small style="color: #646970;"><?php echo esc_html($failed_order['error']['error_description'] ?? 'No description'); ?></small>
                            </td>
                            <td><?php echo esc_html($failed_order['failed_at']); ?></td>
                            <td><span class="badge" style="background: #f0f0f1; padding: 3px 8px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($failed_order['retry_count']); ?></span></td>
                            <td>
                                <button class="button button-small get-diagnostic-report" data-order-id="<?php echo esc_attr($failed_order['order_id']); ?>">
                                    View Report
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Log Entries -->
            <div class="kounta-log-entries">
                <h2 style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-media-text" style="font-size: 20px;"></span>
                    Recent Log Entries
                    <?php if (!empty($logs)): ?>
                        <span style="background: #f0f0f1; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; color: #646970;">
                            <?php echo count($logs); ?>
                        </span>
                    <?php endif; ?>
                </h2>

                <?php if (empty($logs)): ?>
                    <div class="notice notice-info inline" style="margin: 0;">
                        <p><strong>No log entries found.</strong> <?php echo $order_id ? 'Try removing the order ID filter.' : 'Logs will appear here when orders are synced.'; ?></p>
                    </div>
                <?php else: ?>
                    <div class="log-entries-container">
                        <?php foreach ($logs as $index => $log): ?>
                            <div class="log-entry">
                                <div class="log-entry-header">
                                    <span class="log-entry-number">Entry #<?php echo count($logs) - $index; ?></span>
                                </div>
                                <pre><?php echo esc_html($log); ?></pre>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Diagnostic Report Modal -->
            <div id="diagnostic-modal" class="kounta-modal" style="display: none;">
                <div class="kounta-modal-content">
                    <span class="kounta-modal-close">&times;</span>
                    <h2 style="margin-top: 0; font-size: 18px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-chart-bar" style="font-size: 24px;"></span>
                        Diagnostic Report
                    </h2>
                    <div id="diagnostic-report-content">
                        <p style="text-align: center; padding: 40px; color: #646970;">
                            <span class="spinner is-active" style="float: none; margin: 0;"></span>
                            Loading diagnostic report...
                        </p>
                    </div>
                    <div class="modal-actions">
                        <button class="button button-primary" id="download-diagnostic">Download Report</button>
                        <button class="button" id="close-diagnostic">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('kounta_order_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-order-logger.php';
        Kounta_Order_Logger::clear_logs();

        wp_send_json_success('Logs cleared successfully');
    }

    /**
     * AJAX: Download log
     */
    public function ajax_download_log() {
        check_ajax_referer('kounta_order_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/brewhq-kounta-orders.log';

        if (!file_exists($log_file)) {
            wp_die('Log file not found');
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="kounta-order-logs-' . date('Y-m-d-His') . '.log"');
        header('Content-Length: ' . filesize($log_file));

        readfile($log_file);
        exit;
    }

    /**
     * AJAX: Get diagnostic report
     */
    public function ajax_get_diagnostic_report() {
        check_ajax_referer('kounta_order_logs', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
        }

        require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-order-logger.php';
        $report = Kounta_Order_Logger::generate_diagnostic_report($order_id);

        wp_send_json_success(array(
            'report' => $report,
            'order_id' => $order_id,
        ));
    }
}

// Initialize the page
new Kounta_Order_Logs_Page();


