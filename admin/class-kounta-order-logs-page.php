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
        // Check if we're on the Order Sync Logs page
        // The hook format for submenu pages is: {parent-slug}_page_{menu-slug}
        if ($hook !== 'kounta-pos-integration_page_kounta-order-logs') {
            return;
        }

        // Use timestamp for aggressive cache busting during development
        $version = '2.1.0.' . filemtime(plugin_dir_path(__FILE__) . 'css/order-logs.css');

        wp_enqueue_style('kounta-order-logs', plugins_url('css/order-logs.css', __FILE__), array(), $version);
        wp_enqueue_script('kounta-order-logs', plugins_url('js/order-logs.js', __FILE__), array('jquery'), $version, true);

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
                    <?php
                    // Group logs by order ID
                    $grouped_logs = $this->group_logs_by_order($logs);
                    $entry_counter = count($logs);
                    ?>

                    <!-- DEBUG PANEL - ALWAYS VISIBLE FOR NOW -->
                    <div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 8px; font-family: monospace;">
                        <strong style="font-size: 14px; color: #856404;">🐛 DEBUG: Log Grouping Analysis</strong><br><br>
                        <strong>Total log entries:</strong> <?php echo count($logs); ?><br>
                        <strong>Total groups created:</strong> <?php echo count($grouped_logs); ?><br><br>

                        <?php foreach ($grouped_logs as $oid => $ologs): ?>
                            <?php $color = ($oid === 'separator') ? '#dc3545' : '#28a745'; ?>
                            <div style="background: rgba(0,0,0,0.05); padding: 8px; margin: 5px 0; border-left: 4px solid <?php echo $color; ?>;">
                                <strong>Group:</strong> <?php echo esc_html($oid); ?> → <strong><?php echo count($ologs); ?></strong> <?php echo count($ologs) === 1 ? 'entry' : 'entries'; ?>

                                <?php if (count($ologs) > 0): ?>
                                    <?php $first_parsed = $this->parse_log_entry($ologs[0]); ?>
                                    <br><small style="color: #666;">Stage: <?php echo esc_html($first_parsed['stage']); ?>, Timestamp: <?php echo esc_html($first_parsed['timestamp']); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="log-entries-container">
                        <?php
                        // First, show all order groups
                        foreach ($grouped_logs as $order_id => $order_logs):
                            $is_separator = ($order_id === 'separator');

                            // Skip separator group for now
                            if ($is_separator) {
                                continue;
                            }
                        ?>
                                <!-- Order group -->
                                <div class="log-order-group">
                                    <div class="log-order-group-header">
                                        <span class="log-order-group-title">
                                            <span class="dashicons dashicons-cart"></span>
                                            Order #<?php echo esc_html($order_id); ?>
                                        </span>
                                        <span class="log-order-group-count">
                                            <?php echo count($order_logs); ?> <?php echo count($order_logs) === 1 ? 'entry' : 'entries'; ?>
                                        </span>
                                    </div>
                                    <div class="log-order-group-entries">
                                        <?php foreach ($order_logs as $log): ?>
                                            <?php
                                            $parsed = $this->parse_log_entry($log);
                                            $stage_class = $this->get_stage_class($parsed['stage'] ?: $parsed['type']);
                                            $stage_icon = $parsed['stage'] ? $this->get_stage_icon($parsed['stage']) : $this->get_type_icon($parsed['type']);
                                            $display_stage = $parsed['stage'] ?: $parsed['type'];
                                            ?>
                                            <div class="log-entry <?php echo esc_attr($stage_class); ?>">
                                                <div class="log-entry-header">
                                                    <span class="log-entry-number">Entry #<?php echo $entry_counter--; ?></span>
                                                    <?php if ($display_stage): ?>
                                                        <span class="log-entry-stage">
                                                            <span class="stage-icon"><?php echo $stage_icon; ?></span>
                                                            <span class="stage-text"><?php echo esc_html(strtoupper(str_replace('_', ' ', $display_stage))); ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($parsed['timestamp']): ?>
                                                        <span class="log-entry-timestamp">
                                                            <?php echo esc_html($parsed['timestamp']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="log-entry-body">
                                                    <div class="log-entry-columns">
                                                        <!-- Column 1: Order Info -->
                                                        <div class="log-column log-column-order-info">
                                                            <?php if ($parsed['order_id']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Order ID:</span>
                                                                    <span class="log-field-value">#<?php echo esc_html($parsed['order_id']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['order_total']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Total:</span>
                                                                    <span class="log-field-value">$<?php echo esc_html($parsed['order_total']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['customer_email']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Customer:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['customer_email']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Column 2: Status -->
                                                        <div class="log-column log-column-status">
                                                            <?php if ($parsed['order_status']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Status:</span>
                                                                    <span class="log-field-value log-status-badge status-<?php echo esc_attr($parsed['order_status']); ?>">
                                                                        <?php echo esc_html($parsed['order_status']); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['method'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Method:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['method']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['endpoint'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Endpoint:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['endpoint']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['http_code'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">HTTP Code:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['http_code']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['duration'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Duration:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['duration']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Column 3: Data/Message -->
                                                        <div class="log-column log-column-data">
                                                            <?php if ($parsed['message']): ?>
                                                                <div class="log-message">
                                                                    <?php echo esc_html($parsed['message']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['data']): ?>
                                                                <details class="log-data-details">
                                                                    <summary>View Details</summary>
                                                                    <pre class="log-data-content"><?php echo esc_html($parsed['data']); ?></pre>
                                                                </details>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Full log toggle -->
                                                    <details class="log-full-details">
                                                        <summary>View Full Log Entry</summary>
                                                        <pre class="log-entry-full-text"><?php echo esc_html($log); ?></pre>
                                                    </details>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                        <?php endforeach; ?>

                        <?php
                        // Now show separator group (other entries) in a collapsible section
                        if (isset($grouped_logs['separator']) && count($grouped_logs['separator']) > 0):
                        ?>
                            <div class="log-other-entries" style="margin-top: 40px;">
                                <details style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px; padding: 0; overflow: hidden;">
                                    <summary style="padding: 16px 20px; cursor: pointer; font-weight: 600; color: #646970; user-select: none; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                                        <span>
                                            <span class="dashicons dashicons-info" style="margin-right: 8px;"></span>
                                            Other Log Entries (<?php echo count($grouped_logs['separator']); ?>)
                                        </span>
                                        <span style="font-size: 12px; font-weight: 400; color: #a7aaad;">Click to expand</span>
                                    </summary>
                                    <div style="padding: 16px; background: #fff; border-top: 1px solid #dcdcde;">
                                        <?php foreach ($grouped_logs['separator'] as $log): ?>
                                            <?php
                                            $parsed = $this->parse_log_entry($log);
                                            $stage_class = $this->get_stage_class($parsed['stage'] ?: $parsed['type']);
                                            $stage_icon = $parsed['stage'] ? $this->get_stage_icon($parsed['stage']) : $this->get_type_icon($parsed['type']);
                                            $display_stage = $parsed['stage'] ?: $parsed['type'];
                                            ?>
                                            <div class="log-entry <?php echo esc_attr($stage_class); ?> log-entry-separator" style="margin-bottom: 12px;">
                                                <div class="log-entry-header">
                                                    <span class="log-entry-number">Entry #<?php echo $entry_counter--; ?></span>
                                                    <?php if ($display_stage): ?>
                                                        <span class="log-entry-stage">
                                                            <span class="stage-icon"><?php echo $stage_icon; ?></span>
                                                            <span class="stage-text"><?php echo esc_html(strtoupper(str_replace('_', ' ', $display_stage))); ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($parsed['timestamp']): ?>
                                                        <span class="log-entry-timestamp">
                                                            <?php echo esc_html($parsed['timestamp']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="log-entry-body">
                                                    <div class="log-entry-columns">
                                                        <!-- Column 1: Order Info -->
                                                        <div class="log-column log-column-order-info">
                                                            <?php if ($parsed['order_id']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Order ID:</span>
                                                                    <span class="log-field-value">#<?php echo esc_html($parsed['order_id']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['order_total']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Total:</span>
                                                                    <span class="log-field-value">$<?php echo esc_html($parsed['order_total']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['customer_email']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Customer:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['customer_email']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Column 2: Status -->
                                                        <div class="log-column log-column-status">
                                                            <?php if ($parsed['order_status']): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Status:</span>
                                                                    <span class="log-field-value log-status-badge status-<?php echo esc_attr($parsed['order_status']); ?>">
                                                                        <?php echo esc_html($parsed['order_status']); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['method'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Method:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['method']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['endpoint'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Endpoint:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['endpoint']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['http_code'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">HTTP Code:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['http_code']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($parsed['duration'])): ?>
                                                                <div class="log-field">
                                                                    <span class="log-field-label">Duration:</span>
                                                                    <span class="log-field-value"><?php echo esc_html($parsed['duration']); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <!-- Column 3: Data/Message -->
                                                        <div class="log-column log-column-data">
                                                            <?php if ($parsed['message']): ?>
                                                                <div class="log-message">
                                                                    <?php echo esc_html($parsed['message']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($parsed['data']): ?>
                                                                <details class="log-data-details">
                                                                    <summary>View Details</summary>
                                                                    <pre class="log-data-content"><?php echo esc_html($parsed['data']); ?></pre>
                                                                </details>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>

                                                    <!-- Full log toggle -->
                                                    <details class="log-full-details">
                                                        <summary>View Full Log Entry</summary>
                                                        <pre class="log-entry-full-text"><?php echo esc_html($log); ?></pre>
                                                    </details>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                        <?php endif; ?>
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

    /**
     * Group log entries by order ID
     *
     * @param array $logs Log entries
     * @return array Grouped logs
     */
    private function group_logs_by_order($logs) {
        $grouped = array();

        foreach ($logs as $log) {
            $parsed = $this->parse_log_entry($log);
            $order_id = $parsed['order_id'];

            if ($order_id) {
                if (!isset($grouped[$order_id])) {
                    $grouped[$order_id] = array();
                }
                $grouped[$order_id][] = $log;
            } else {
                // Entries without order ID go into a separator group
                if (!isset($grouped['separator'])) {
                    $grouped['separator'] = array();
                }
                $grouped['separator'][] = $log;
            }
        }

        return $grouped;
    }

    /**
     * Parse log entry to extract key information
     *
     * @param string $log Raw log entry
     * @return array Parsed data
     */
    private function parse_log_entry($log) {
        $parsed = array(
            'timestamp' => '',
            'type' => '',
            'stage' => '',
            'order_id' => '',
            'order_total' => '',
            'order_status' => '',
            'customer_email' => '',
            'message' => '',
            'data' => '',
        );

        // Extract timestamp - look for [YYYY-MM-DD HH:MM:SS] pattern at the start
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/', $log, $matches)) {
            $parsed['timestamp'] = $matches[1];
        }

        // Extract type (LOG, API_REQUEST, API_RESPONSE, etc.)
        if (preg_match('/\]\s+([A-Z_]+)/', $log, $matches)) {
            $parsed['type'] = trim($matches[1]);
        }

        // Extract stage - more flexible pattern
        if (preg_match('/STAGE:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
            $parsed['stage'] = trim($matches[1]);
        }

        // Extract order ID - more flexible pattern to handle different line endings
        if (preg_match('/ORDER\s+ID:\s*[\r\n]+(\d+)/', $log, $matches)) {
            $parsed['order_id'] = trim($matches[1]);
        }

        // Extract order total
        if (preg_match('/ORDER\s+TOTAL:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
            $parsed['order_total'] = trim($matches[1]);
        }

        // Extract order status
        if (preg_match('/ORDER\s+STATUS:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
            $parsed['order_status'] = trim($matches[1]);
        }

        // Extract customer email
        if (preg_match('/CUSTOMER\s+EMAIL:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
            $parsed['customer_email'] = trim($matches[1]);
        }

        // Extract message from DATA section
        if (preg_match('/"message":\s*"([^"]+)"/', $log, $matches)) {
            $parsed['message'] = trim($matches[1]);
        }

        // Extract DATA section
        if (preg_match('/DATA:\s*[\r\n]+(.*?)(?=\n\n|\n[A-Z]+:|$)/s', $log, $matches)) {
            $parsed['data'] = trim($matches[1]);
        }

        // For API_REQUEST/API_RESPONSE, extract relevant fields
        if ($parsed['type'] === 'API_REQUEST') {
            if (preg_match('/METHOD:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
                $parsed['method'] = trim($matches[1]);
            }
            if (preg_match('/ENDPOINT:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
                $parsed['endpoint'] = trim($matches[1]);
            }
        }

        if ($parsed['type'] === 'API_RESPONSE') {
            if (preg_match('/HTTP\s+CODE:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
                $parsed['http_code'] = trim($matches[1]);
            }
            if (preg_match('/DURATION:\s*[\r\n]+([^\r\n]+)/', $log, $matches)) {
                $parsed['duration'] = trim($matches[1]);
            }
        }

        return $parsed;
    }

    /**
     * Get CSS class for stage
     *
     * @param string $stage Stage name
     * @return string CSS class
     */
    private function get_stage_class($stage) {
        $classes = array(
            'success' => 'log-stage-success',
            'verification_success' => 'log-stage-success',
            'duplicate_prevented' => 'log-stage-prevented',
            'duplicate_found' => 'log-stage-prevented',
            'duplicate_attempt' => 'log-stage-warning',
            'upload_triggered' => 'log-stage-info',
            'status_change' => 'log-stage-neutral',
            'status_ignored' => 'log-stage-neutral',
            'prepare' => 'log-stage-info',
            'prepared' => 'log-stage-info',
            'upload_attempt' => 'log-stage-info',
            'verification_attempt' => 'log-stage-info',
            'verification_retry' => 'log-stage-info',
            'verification_exhausted' => 'log-stage-warning',
            'verification_failed' => 'log-stage-error',
            'failure' => 'log-stage-error',
            'ORDER_FAILURE' => 'log-stage-error',
        );

        return isset($classes[$stage]) ? $classes[$stage] : 'log-stage-default';
    }

    /**
     * Get icon for stage
     *
     * @param string $stage Stage name
     * @return string Icon HTML
     */
    private function get_stage_icon($stage) {
        $icons = array(
            'success' => '✅',
            'verification_success' => '✅',
            'duplicate_prevented' => '🛡️',
            'duplicate_found' => '🛡️',
            'duplicate_attempt' => '⚠️',
            'upload_triggered' => '🚀',
            'status_change' => '🔄',
            'status_ignored' => '⏭️',
            'prepare' => '📋',
            'prepared' => '📋',
            'upload_attempt' => '📤',
            'verification_attempt' => '🔍',
            'verification_retry' => '🔄',
            'verification_exhausted' => '⚠️',
            'verification_failed' => '❌',
            'failure' => '❌',
            'ORDER_FAILURE' => '❌',
        );

        return isset($icons[$stage]) ? $icons[$stage] : '📝';
    }

    /**
     * Get icon for log type
     *
     * @param string $type Log type
     * @return string Icon HTML
     */
    private function get_type_icon($type) {
        $icons = array(
            'API_REQUEST' => '📡',
            'API_RESPONSE' => '📨',
            'LOG' => '📝',
        );

        return isset($icons[$type]) ? $icons[$type] : '📝';
    }
}

// Initialize the page
new Kounta_Order_Logs_Page();


