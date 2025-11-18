<?php
/**
 * Kounta Order Logger
 * 
 * Comprehensive logging system specifically for order sync operations
 * Provides detailed request/response logging, error tracking, and diagnostic reports
 *
 * @package BrewHQ_Kounta
 * @since 2.1.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Order_Logger {
    
    /**
     * Log file name
     */
    const LOG_FILE = 'brewhq-kounta-orders.log';
    
    /**
     * Maximum log file size (10MB)
     */
    const MAX_LOG_SIZE = 10485760;
    
    /**
     * Log an order sync attempt
     *
     * @param int $order_id WooCommerce order ID
     * @param string $stage Stage of sync (prepare|upload|verify|success|failure)
     * @param array $data Additional data to log
     */
    public static function log_order_sync($order_id, $stage, $data = array()) {
        $order = wc_get_order($order_id);
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'order_id' => $order_id,
            'stage' => $stage,
            'order_total' => $order ? $order->get_total() : 'N/A',
            'order_status' => $order ? $order->get_status() : 'N/A',
            'customer_email' => $order ? $order->get_billing_email() : 'N/A',
            'data' => $data,
        );
        
        self::write_log($log_entry);
    }
    
    /**
     * Log API request
     *
     * @param int $order_id Order ID
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $request_data Request payload
     */
    public static function log_api_request($order_id, $endpoint, $method, $request_data) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'type' => 'API_REQUEST',
            'order_id' => $order_id,
            'endpoint' => $endpoint,
            'method' => $method,
            'request_data' => $request_data,
            'request_size' => strlen(json_encode($request_data)) . ' bytes',
        );
        
        self::write_log($log_entry);
    }
    
    /**
     * Log API response
     *
     * @param int $order_id Order ID
     * @param mixed $response API response
     * @param int $http_code HTTP status code
     * @param float $duration Request duration in seconds
     */
    public static function log_api_response($order_id, $response, $http_code = null, $duration = null) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'type' => 'API_RESPONSE',
            'order_id' => $order_id,
            'http_code' => $http_code,
            'duration' => $duration ? round($duration, 3) . 's' : 'N/A',
            'response' => $response,
            'response_size' => strlen(json_encode($response)) . ' bytes',
        );
        
        self::write_log($log_entry);
    }
    
    /**
     * Log order failure with full diagnostic data
     *
     * @param int $order_id Order ID
     * @param array $error Error details
     * @param array $order_data Order data that was sent
     * @param int $retry_count Current retry count
     */
    public static function log_order_failure($order_id, $error, $order_data = array(), $retry_count = 0) {
        $order = wc_get_order($order_id);
        
        $diagnostic_data = array(
            'timestamp' => current_time('mysql'),
            'type' => 'ORDER_FAILURE',
            'order_id' => $order_id,
            'retry_count' => $retry_count,
            'error' => $error,
            'order_data' => $order_data,
            'order_details' => array(
                'total' => $order ? $order->get_total() : 'N/A',
                'status' => $order ? $order->get_status() : 'N/A',
                'payment_method' => $order ? $order->get_payment_method() : 'N/A',
                'customer_email' => $order ? $order->get_billing_email() : 'N/A',
                'customer_name' => $order ? $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() : 'N/A',
                'item_count' => $order ? count($order->get_items()) : 0,
                'created_date' => $order ? $order->get_date_created()->format('Y-m-d H:i:s') : 'N/A',
            ),
            'system_info' => array(
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'N/A',
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
            ),
        );
        
        self::write_log($diagnostic_data);
        
        // Also save to order meta for easy access
        if ($order) {
            $order->update_meta_data('_kounta_last_error', $diagnostic_data);
            $order->save();
        }
    }

    /**
     * Write log entry to file
     *
     * @param array $log_entry Log entry data
     */
    private static function write_log($log_entry) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/' . self::LOG_FILE;

        // Rotate log if too large
        if (file_exists($log_file) && filesize($log_file) > self::MAX_LOG_SIZE) {
            self::rotate_log($log_file);
        }

        // Format log entry
        $formatted_entry = self::format_log_entry($log_entry);

        // Write to file
        $file = fopen($log_file, 'a');
        if ($file) {
            fwrite($file, $formatted_entry . "\n");
            fclose($file);
        }

        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta Order] ' . json_encode($log_entry));
        }
    }

    /**
     * Format log entry for readability
     *
     * @param array $log_entry Log entry data
     * @return string Formatted log entry
     */
    private static function format_log_entry($log_entry) {
        $separator = str_repeat('=', 80);
        $timestamp = isset($log_entry['timestamp']) ? $log_entry['timestamp'] : current_time('mysql');
        $type = isset($log_entry['type']) ? $log_entry['type'] : 'LOG';

        $formatted = "\n{$separator}\n";
        $formatted .= "[{$timestamp}] {$type}\n";
        $formatted .= "{$separator}\n";

        // Format each field
        foreach ($log_entry as $key => $value) {
            if ($key === 'timestamp' || $key === 'type') {
                continue;
            }

            $formatted .= strtoupper(str_replace('_', ' ', $key)) . ":\n";

            if (is_array($value) || is_object($value)) {
                $formatted .= json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                $formatted .= $value . "\n";
            }

            $formatted .= "\n";
        }

        return $formatted;
    }

    /**
     * Rotate log file
     *
     * @param string $log_file Log file path
     */
    private static function rotate_log($log_file) {
        $backup_file = $log_file . '.' . date('Y-m-d-His') . '.bak';
        rename($log_file, $backup_file);

        // Keep only last 5 backup files
        $upload_dir = wp_upload_dir();
        $backup_files = glob($upload_dir['basedir'] . '/' . self::LOG_FILE . '.*.bak');

        if (count($backup_files) > 5) {
            usort($backup_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });

            // Delete oldest files
            $to_delete = array_slice($backup_files, 0, count($backup_files) - 5);
            foreach ($to_delete as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Get recent order logs
     *
     * @param int $limit Number of entries to retrieve
     * @param int $order_id Optional: filter by order ID
     * @return array Log entries
     */
    public static function get_recent_logs($limit = 50, $order_id = null) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/' . self::LOG_FILE;

        if (!file_exists($log_file)) {
            return array();
        }

        $content = file_get_contents($log_file);
        $entries = explode(str_repeat('=', 80), $content);

        // Parse entries
        $parsed_entries = array();
        foreach ($entries as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            // Filter by order ID if specified
            if ($order_id && strpos($entry, "ORDER_ID:\n{$order_id}") === false) {
                continue;
            }

            $parsed_entries[] = $entry;
        }

        // Return most recent entries
        return array_slice(array_reverse($parsed_entries), 0, $limit);
    }

    /**
     * Generate diagnostic report for an order
     *
     * @param int $order_id Order ID
     * @return string Diagnostic report
     */
    public static function generate_diagnostic_report($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return "Order #{$order_id} not found.";
        }

        $report = "=== KOUNTA ORDER SYNC DIAGNOSTIC REPORT ===\n\n";
        $report .= "Generated: " . current_time('mysql') . "\n\n";

        // Order Information
        $report .= "--- ORDER INFORMATION ---\n";
        $report .= "Order ID: #{$order_id}\n";
        $report .= "Order Total: " . $order->get_currency() . $order->get_total() . "\n";
        $report .= "Order Status: " . $order->get_status() . "\n";
        $report .= "Payment Method: " . $order->get_payment_method_title() . "\n";
        $report .= "Customer: " . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . "\n";
        $report .= "Email: " . $order->get_billing_email() . "\n";
        $report .= "Created: " . $order->get_date_created()->format('Y-m-d H:i:s') . "\n";
        $report .= "Items: " . count($order->get_items()) . "\n\n";

        // Kounta Sync Status
        $report .= "--- KOUNTA SYNC STATUS ---\n";
        $kounta_id = $order->get_meta('_kounta_id');
        $report .= "Kounta ID: " . ($kounta_id ? $kounta_id : 'Not synced') . "\n";

        $upload_time = $order->get_meta('_kounta_upload_time');
        $report .= "Upload Time: " . ($upload_time ? $upload_time : 'Never') . "\n";

        $last_error = $order->get_meta('_kounta_last_error');
        if ($last_error) {
            $report .= "\n--- LAST ERROR ---\n";
            $report .= json_encode($last_error, JSON_PRETTY_PRINT) . "\n";
        }

        // Recent Log Entries
        $report .= "\n--- RECENT LOG ENTRIES ---\n";
        $logs = self::get_recent_logs(10, $order_id);
        foreach ($logs as $log) {
            $report .= $log . "\n";
        }

        return $report;
    }

    /**
     * Clear order logs
     */
    public static function clear_logs() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/' . self::LOG_FILE;

        if (file_exists($log_file)) {
            unlink($log_file);
        }

        // Also clear backup files
        $backup_files = glob($upload_dir['basedir'] . '/' . self::LOG_FILE . '.*.bak');
        foreach ($backup_files as $file) {
            unlink($file);
        }
    }

    /**
     * Send enhanced error notification email
     *
     * @param int $order_id Order ID
     * @param array $error Error details
     * @param array $order_data Order data that was sent
     */
    public static function send_error_notification($order_id, $error, $order_data = array()) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Get admin email from settings or use default
        $admin_email = get_option('xwcpos_error_notification_email', get_option('admin_email'));

        if (empty($admin_email)) {
            return;
        }

        $subject = sprintf('[BrewHQ Kounta] Order Sync Failed - Order #%d', $order_id);

        // Build HTML email
        $message = self::build_error_email_html($order, $error, $order_data);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        );

        wp_mail($admin_email, $subject, $message, $headers);
    }

    /**
     * Build HTML email for error notification
     *
     * @param WC_Order $order Order object
     * @param array $error Error details
     * @param array $order_data Order data
     * @return string HTML email content
     */
    private static function build_error_email_html($order, $error, $order_data) {
        $order_id = $order->get_id();
        $order_url = admin_url('post.php?post=' . $order_id . '&action=edit');

        $html = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">';
        $html .= '<div style="max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">';

        // Header
        $html .= '<div style="background-color: #d9534f; color: white; padding: 15px; border-radius: 5px 5px 0 0;">';
        $html .= '<h2 style="margin: 0;">⚠️ Kounta Order Sync Failed</h2>';
        $html .= '</div>';

        // Content
        $html .= '<div style="background-color: white; padding: 20px; border-radius: 0 0 5px 5px;">';

        // Order Info
        $html .= '<h3 style="color: #d9534f; border-bottom: 2px solid #d9534f; padding-bottom: 5px;">Order Information</h3>';
        $html .= '<table style="width: 100%; margin-bottom: 20px;">';
        $html .= '<tr><td style="padding: 5px;"><strong>Order ID:</strong></td><td style="padding: 5px;">#' . $order_id . '</td></tr>';
        $html .= '<tr><td style="padding: 5px;"><strong>Order Total:</strong></td><td style="padding: 5px;">' . $order->get_currency() . $order->get_total() . '</td></tr>';
        $html .= '<tr><td style="padding: 5px;"><strong>Customer:</strong></td><td style="padding: 5px;">' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . '</td></tr>';
        $html .= '<tr><td style="padding: 5px;"><strong>Email:</strong></td><td style="padding: 5px;">' . $order->get_billing_email() . '</td></tr>';
        $html .= '<tr><td style="padding: 5px;"><strong>Payment Method:</strong></td><td style="padding: 5px;">' . $order->get_payment_method_title() . '</td></tr>';
        $html .= '<tr><td style="padding: 5px;"><strong>Created:</strong></td><td style="padding: 5px;">' . $order->get_date_created()->format('Y-m-d H:i:s') . '</td></tr>';
        $html .= '</table>';

        // Error Details
        $html .= '<h3 style="color: #d9534f; border-bottom: 2px solid #d9534f; padding-bottom: 5px;">Error Details</h3>';
        $html .= '<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 15px; margin-bottom: 20px;">';
        $html .= '<p><strong>Error Type:</strong> ' . esc_html($error['error'] ?? 'Unknown') . '</p>';
        $html .= '<p><strong>Description:</strong> ' . esc_html($error['error_description'] ?? 'No description available') . '</p>';

        if (isset($error['http_code'])) {
            $html .= '<p><strong>HTTP Code:</strong> ' . esc_html($error['http_code']) . '</p>';
        }

        if (isset($error['duration'])) {
            $html .= '<p><strong>Request Duration:</strong> ' . esc_html($error['duration']) . 's</p>';
        }

        $html .= '</div>';

        // Order Data Summary
        if (!empty($order_data)) {
            $html .= '<h3 style="color: #333; border-bottom: 2px solid #ccc; padding-bottom: 5px;">Order Data Sent to Kounta</h3>';
            $html .= '<div style="background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 20px; overflow-x: auto;">';
            $html .= '<pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; font-size: 12px;">' . esc_html(json_encode($order_data, JSON_PRETTY_PRINT)) . '</pre>';
            $html .= '</div>';
        }

        // Action Buttons
        $html .= '<div style="margin-top: 30px; text-align: center;">';
        $html .= '<a href="' . esc_url($order_url) . '" style="display: inline-block; background-color: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 5px;">View Order in WooCommerce</a>';
        $html .= '</div>';

        $html .= '</div>'; // End content
        $html .= '</div>'; // End container

        // Footer
        $html .= '<div style="text-align: center; margin-top: 20px; color: #666; font-size: 12px;">';
        $html .= '<p>This is an automated notification from ' . get_bloginfo('name') . '</p>';
        $html .= '<p>Timestamp: ' . current_time('mysql') . '</p>';
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }
}



