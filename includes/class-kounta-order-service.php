<?php
/**
 * Order Service with Robust Retry Logic
 * 
 * Handles order creation with intelligent retry logic, error classification,
 * and failed order queue management.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Order_Service {
    
    /**
     * API client instance
     * @var Kounta_API_Client
     */
    private $api_client;
    
    /**
     * Retry strategy instance
     * @var Kounta_Retry_Strategy
     */
    private $retry_strategy;
    
    /**
     * Main plugin instance (for backward compatibility)
     * @var BrewHQ_Kounta_POS_Int
     */
    private $plugin;
    
    /**
     * Constructor
     *
     * @param BrewHQ_Kounta_POS_Int $plugin Main plugin instance
     */
    public function __construct($plugin = null) {
        $this->api_client = new Kounta_API_Client();
        $this->retry_strategy = new Kounta_Retry_Strategy(5, 1.0); // 5 attempts, 1s base delay
        $this->plugin = $plugin;

        // Load order logger
        require_once XWCPOS_PLUGIN_DIR . 'includes/class-kounta-order-logger.php';
    }
    
    /**
     * Create order with retry logic
     *
     * @param int $order_id WooCommerce order ID
     * @return array Response with success/error status
     */
    public function create_order_with_retry($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return array(
                'success' => false,
                'error' => 'invalid_order',
                'error_description' => 'Order not found',
            );
        }
        
        // Check if order already has Kounta ID
        $kounta_id = $order->get_meta('_kounta_id');
        if ($kounta_id) {
            $order->add_order_note('Upload attempted. Order already exists. Order#: ' . $kounta_id);
            return array(
                'success' => false,
                'error' => 'duplicate_order',
                'error_description' => 'Order already has Kounta ID: ' . $kounta_id,
            );
        }
        
        $order->add_order_note('Starting optimized order upload with retry logic');

        // Log sync start
        Kounta_Order_Logger::log_order_sync($order_id, 'prepare', array(
            'message' => 'Starting order preparation',
        ));

        // Prepare order data
        $order_data = $this->prepare_order_data($order);

        if (isset($order_data['error'])) {
            Kounta_Order_Logger::log_order_failure($order_id, $order_data, array(), 0);
            return $order_data;
        }

        // Log prepared data
        Kounta_Order_Logger::log_order_sync($order_id, 'prepared', array(
            'message' => 'Order data prepared successfully',
            'item_count' => count($order_data['lines']),
            'total' => $order_data['payments'][0]['amount'] ?? 0,
        ));
        
        // Execute with retry logic
        try {
            $attempt = 0;
            $result = $this->retry_strategy->execute(
                function() use ($order_data, $order, $order_id, &$attempt) {
                    $attempt++;
                    Kounta_Order_Logger::log_order_sync($order_id, 'upload_attempt', array(
                        'attempt' => $attempt,
                        'message' => "Upload attempt #{$attempt}",
                    ));
                    return $this->upload_order($order_data, $order, $order_id, $attempt);
                },
                function($error) {
                    return $this->is_retryable_order_error($error);
                }
            );

            // Handle result
            if (is_array($result) && isset($result['error'])) {
                $order->add_order_note(sprintf(
                    'Order upload failed after all retries. Error: %s - %s',
                    $result['error'],
                    $result['error_description'] ?? 'No description'
                ));

                // Log comprehensive failure
                Kounta_Order_Logger::log_order_failure($order_id, $result, $order_data, $attempt);

                // Send email notification if enabled
                if (get_option('xwcpos_send_order_error_emails', true)) {
                    Kounta_Order_Logger::send_error_notification($order_id, $result, $order_data);
                }

                // Add to failed queue for later retry
                $this->add_to_failed_queue($order_id, $result);

                return array(
                    'success' => false,
                    'error' => $result['error'],
                    'error_description' => $result['error_description'] ?? 'Unknown error',
                );
            }
            
            // Success - save Kounta ID
            if ($result) {
                update_post_meta($order_id, '_kounta_id', $result);
                update_post_meta($order_id, '_kounta_upload_time', current_time('mysql'));
                $order->add_order_note('Order uploaded to Kounta successfully. Order#: ' . $result);

                // Log success
                Kounta_Order_Logger::log_order_sync($order_id, 'success', array(
                    'kounta_order_id' => $result,
                    'attempts' => $attempt,
                    'message' => 'Order uploaded successfully',
                ));

                return array(
                    'success' => true,
                    'order_id' => $result,
                );
            }
            
            return array(
                'success' => false,
                'error' => 'unknown_error',
                'error_description' => 'Upload returned no result',
            );
            
        } catch (Exception $e) {
            $order->add_order_note('Order upload exception: ' . $e->getMessage());

            $error_data = array(
                'error' => 'exception',
                'error_description' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
            );

            // Log exception with full details
            Kounta_Order_Logger::log_order_failure($order_id, $error_data, $order_data ?? array(), $attempt ?? 0);

            $this->add_to_failed_queue($order_id, $error_data);

            return array(
                'success' => false,
                'error' => 'exception',
                'error_description' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Prepare order data for Kounta API
     *
     * @param WC_Order $order WooCommerce order
     * @return array Order data or error
     */
    private function prepare_order_data($order) {
        try {
            // Get customer ID (create if needed)
            $customer_id = $this->get_or_create_customer($order);
            
            if (!$customer_id) {
                return array(
                    'error' => 'customer_creation_failed',
                    'error_description' => 'Failed to create or find customer',
                );
            }
            
            // Prepare order items
            $order_items = array();
            $skipped_items = array();

            foreach ($order->get_items() as $item) {
                $item_data = $this->prepare_order_item($item, $order);
                if ($item_data) {
                    $order_items[] = $item_data;
                } else {
                    $skipped_items[] = array(
                        'name' => $item->get_name(),
                        'product_id' => $item->get_product_id(),
                        'variation_id' => $item->get_variation_id(),
                    );
                }
            }

            // Log skipped items
            if (!empty($skipped_items)) {
                error_log('[BrewHQ Kounta Order] Order ' . $order->get_id() . ' has ' . count($skipped_items) . ' items without Kounta mapping: ' . json_encode($skipped_items));
            }

            // Add shipping as line item if present
            if ($order->get_shipping_total() > 0) {
                $shipping_product_id = get_option('xwcpos_shipping_product_id');
                if ($shipping_product_id) {
                    $order_items[] = array(
                        'product_id' => intval($shipping_product_id),
                        'quantity' => 1,
                        'unit_price' => floatval($order->get_shipping_total()),
                    );
                    error_log('[BrewHQ Kounta Order] Added shipping to order ' . $order->get_id() . ': $' . $order->get_shipping_total());
                } else {
                    error_log('[BrewHQ Kounta Order] WARNING: Order ' . $order->get_id() . ' has shipping ($' . $order->get_shipping_total() . ') but no shipping product ID configured');
                }
            }

            if (empty($order_items)) {
                $error_msg = 'No valid items in order. Total items: ' . count($order->get_items()) . ', Skipped: ' . count($skipped_items);
                error_log('[BrewHQ Kounta Order] ERROR: ' . $error_msg . ' - Skipped items: ' . json_encode($skipped_items));
                return array(
                    'error' => 'no_items',
                    'error_description' => $error_msg,
                    'skipped_items' => $skipped_items,
                );
            }

            // Prepare payment data
            $payment_data = array($this->prepare_payment_data($order));

            $site_id = get_option('xwcpos_site_id');

            // Build order data
            $order_data = array(
                'status' => 'SUBMITTED',
                'sale_number' => strval($order->get_id()),
                'order_type' => 'Delivery',
                'customer_id' => $customer_id,
                'site_id' => intval($site_id),
                'lines' => $order_items,
                'payments' => $payment_data,
                'complete_when_paid' => false,
                'pass_thru_printing' => false,
                'placed_at' => $order->get_date_created()->format('Y-m-d\TH:i:s\Z'),
            );

            return $order_data;

        } catch (Exception $e) {
            return array(
                'error' => 'preparation_failed',
                'error_description' => $e->getMessage(),
            );
        }
    }

    /**
     * Upload order to Kounta
     *
     * @param array $order_data Order data
     * @param WC_Order $order WooCommerce order
     * @param int $order_id Order ID
     * @param int $attempt Attempt number
     * @return mixed Kounta order ID or error array
     */
    private function upload_order($order_data, $order, $order_id, $attempt = 1) {
        $account_id = get_option('xwcpos_account_id');
        $endpoint = 'companies/' . $account_id . '/orders';

        // Check if order already exists
        $existing_order = $this->find_order_by_sale_number($order_data);
        if ($existing_order) {
            Kounta_Order_Logger::log_order_sync($order_id, 'duplicate_found', array(
                'kounta_order_id' => $existing_order->id,
                'message' => 'Order already exists in Kounta',
            ));
            return $existing_order->id;
        }

        // Log API request
        Kounta_Order_Logger::log_api_request($order_id, $endpoint, 'POST', $order_data);

        // Create order
        $start_time = microtime(true);
        $result = $this->api_client->make_request($endpoint, 'POST', array(), $order_data);
        $duration = microtime(true) - $start_time;

        // Get HTTP code if available
        $http_code = null;
        if (is_wp_error($result)) {
            $http_code = $result->get_error_code();
        }

        // Log API response
        Kounta_Order_Logger::log_api_response($order_id, $result, $http_code, $duration);

        if (is_wp_error($result)) {
            return array(
                'error' => 'api_error',
                'error_description' => $result->get_error_message(),
                'http_code' => $http_code,
                'duration' => round($duration, 3),
            );
        }

        // Handle different response types
        if ($result === null || $result === '') {
            // API returned null - verify order was created
            sleep(1); // Brief wait for eventual consistency
            $verify_order = $this->find_order_by_sale_number($order_data);

            if ($verify_order) {
                return $verify_order->id;
            }

            return array(
                'error' => 'verification_failed',
                'error_description' => 'Order upload returned null and verification failed',
            );
        }

        if (is_object($result) && isset($result->error)) {
            return array(
                'error' => $result->error,
                'error_description' => $result->error_message ?? $result->error_description ?? 'Unknown error',
            );
        }

        if (is_object($result) && isset($result->id)) {
            return $result->id;
        }

        return array(
            'error' => 'unexpected_response',
            'error_description' => 'Unexpected API response format',
        );
    }

    /**
     * Find order by sale number
     *
     * @param array $order_data Order data with sale_number
     * @return object|false Order object or false
     */
    private function find_order_by_sale_number($order_data) {
        if (!isset($order_data['sale_number']) || !isset($order_data['placed_at'])) {
            return false;
        }

        $account_id = get_option('xwcpos_account_id');
        $endpoint = 'companies/' . $account_id . '/orders';

        // Calculate date range
        $order_date = new DateTime($order_data['placed_at']);
        $order_date->modify('+1 day');
        $tomorrow = $order_date->format('Y-m-d');
        $order_date->modify('-2 day');
        $yesterday = $order_date->format('Y-m-d');

        // Calculate total
        $total = 0;
        if (isset($order_data['payments'])) {
            foreach ($order_data['payments'] as $payment) {
                $total += $payment['amount'] ?? 0;
            }
        }

        $total_lte = $total + 0.05;
        $total_gte = $total - 0.05;

        // Search for order
        $params = array(
            'created_gte' => $yesterday,
            'created_lte' => $tomorrow,
            'value_gte' => $total_gte,
            'value_lte' => $total_lte,
        );

        $orders = $this->api_client->make_request($endpoint, 'GET', $params);

        if (is_wp_error($orders) || !is_array($orders)) {
            return false;
        }

        foreach ($orders as $order) {
            if (isset($order->sale_number) && strpos($order->sale_number, $order_data['sale_number']) === 0) {
                return $order;
            }
        }

        return false;
    }

    /**
     * Get or create customer
     *
     * @param WC_Order $order WooCommerce order
     * @return string|false Customer ID or false
     */
    private function get_or_create_customer($order) {
        // Use plugin method if available for backward compatibility
        if ($this->plugin && method_exists($this->plugin, 'get_kounta_customer')) {
            $customer_id = $this->plugin->get_kounta_customer($order);
            if (!$customer_id) {
                error_log('[BrewHQ Kounta Order] Failed to get/create customer via plugin method for order ' . $order->get_id());
            }
            return $customer_id;
        }

        // Fallback implementation
        $customer_email = $order->get_billing_email();

        if (empty($customer_email)) {
            error_log('[BrewHQ Kounta Order] Order ' . $order->get_id() . ' has no billing email');
            return false;
        }

        $customer_details = array(
            'email' => $customer_email,
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        );

        $account_id = get_option('xwcpos_account_id');
        $endpoint = 'companies/' . $account_id . '/customers';

        // Search by email
        $result = $this->api_client->make_request($endpoint, 'GET', array('email' => $customer_email));

        if (is_wp_error($result)) {
            error_log('[BrewHQ Kounta Order] Failed to search for customer: ' . $result->get_error_message());
        } elseif (is_object($result) && isset($result->id)) {
            error_log('[BrewHQ Kounta Order] Found existing customer: ' . $result->id . ' for email: ' . $customer_email);
            return $result->id;
        }

        // Create customer
        error_log('[BrewHQ Kounta Order] Creating new customer for email: ' . $customer_email);
        $result = $this->api_client->make_request($endpoint, 'POST', array(), $customer_details);

        if (is_wp_error($result)) {
            error_log('[BrewHQ Kounta Order] Failed to create customer: ' . $result->get_error_message() . ' - Details: ' . json_encode($customer_details));
            return false;
        }

        if (is_object($result) && isset($result->id)) {
            error_log('[BrewHQ Kounta Order] Created new customer: ' . $result->id . ' for email: ' . $customer_email);
            return $result->id;
        }

        error_log('[BrewHQ Kounta Order] Unexpected response when creating customer: ' . json_encode($result));
        return false;
    }

    /**
     * Prepare order item data
     *
     * @param WC_Order_Item_Product $item Order item
     * @param WC_Order $order WooCommerce order
     * @return array|null Item data or null
     */
    private function prepare_order_item($item, $order) {
        // Use plugin method if available
        if ($this->plugin && method_exists($this->plugin, 'get_order_item_for_upload')) {
            $item_data = $this->plugin->get_order_item_for_upload($item, $order);
            if (!$item_data) {
                error_log('[BrewHQ Kounta Order] Plugin method returned null for item: ' . $item->get_name() . ' (Product ID: ' . $item->get_product_id() . ')');
            }
            return $item_data;
        }

        // Fallback implementation
        $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
        $kounta_product_id = get_post_meta($product_id, '_xwcpos_item_id', true);

        if (!$kounta_product_id) {
            error_log('[BrewHQ Kounta Order] Product ' . $product_id . ' (' . $item->get_name() . ') has no Kounta product ID mapping');
            return null;
        }

        $quantity = $item->get_quantity();
        $total = floatval($item->get_total());

        if ($quantity <= 0) {
            error_log('[BrewHQ Kounta Order] Invalid quantity for product ' . $product_id . ': ' . $quantity);
            return null;
        }

        $unit_price = $total / $quantity;

        return array(
            'product_id' => $kounta_product_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
        );
    }

    /**
     * Prepare payment data
     *
     * @param WC_Order $order WooCommerce order
     * @return array Payment data
     */
    private function prepare_payment_data($order) {
        // Use plugin method if available
        if ($this->plugin && method_exists($this->plugin, 'get_payment_method_for_upload')) {
            return $this->plugin->get_payment_method_for_upload($order);
        }

        // Fallback implementation
        $payment_gateways = json_decode(get_option('xwcpos_payment_gateways'), true);
        $wc_method = $order->get_payment_method();

        $payment_data = array(
            'method_id' => isset($payment_gateways[$wc_method]['kounta_pm']) ?
                intval($payment_gateways[$wc_method]['kounta_pm']) : 0,
            'amount' => floatval($order->get_total()),
        );

        return $payment_data;
    }

    /**
     * Determine if order error is retryable
     *
     * @param array $error Error array
     * @return bool True if retryable
     */
    private function is_retryable_order_error($error) {
        if (!isset($error['error'])) {
            return false;
        }

        $error_type = strtolower($error['error']);

        // Non-retryable errors (business logic errors)
        $non_retryable = array(
            'invalid_customer',
            'invalid_product',
            'duplicate_order',
            'invalid_payment',
            'validation_error',
            'no_items',
            'customer_creation_failed',
        );

        foreach ($non_retryable as $type) {
            if (strpos($error_type, $type) !== false) {
                return false;
            }
        }

        // Retryable errors (transient failures)
        $retryable = array(
            'timeout',
            'network',
            'connection',
            'service_unavailable',
            'internal_server_error',
            'bad_gateway',
            'gateway_timeout',
            'verification_failed',
            'api_error',
        );

        foreach ($retryable as $type) {
            if (strpos($error_type, $type) !== false) {
                return true;
            }
        }

        // Check HTTP codes
        if (isset($error['http_code'])) {
            $code = (int)$error['http_code'];
            // Retry on 5xx errors and 429
            if ($code >= 500 || $code === 429) {
                return true;
            }
            // Don't retry on 4xx errors (except 429)
            if ($code >= 400 && $code < 500) {
                return false;
            }
        }

        // Default to retryable for unknown errors
        return true;
    }

    /**
     * Add order to failed queue
     *
     * @param int $order_id Order ID
     * @param array $error Error details
     */
    private function add_to_failed_queue($order_id, $error) {
        $queue = get_option('xwcpos_failed_orders', array());

        $queue[$order_id] = array(
            'order_id' => $order_id,
            'error' => $error,
            'failed_at' => current_time('mysql'),
            'retry_count' => isset($queue[$order_id]['retry_count']) ?
                $queue[$order_id]['retry_count'] + 1 : 1,
        );

        update_option('xwcpos_failed_orders', $queue);
    }

    /**
     * Get failed orders queue
     *
     * @return array Failed orders
     */
    public function get_failed_orders() {
        return get_option('xwcpos_failed_orders', array());
    }

    /**
     * Retry failed orders
     *
     * @param int $limit Maximum orders to retry
     * @return array Results
     */
    public function retry_failed_orders($limit = 10) {
        $failed_orders = $this->get_failed_orders();
        $results = array(
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        );

        $count = 0;
        foreach ($failed_orders as $order_id => $data) {
            if ($count >= $limit) {
                break;
            }

            // Skip if too many retries
            if ($data['retry_count'] > 10) {
                $results['skipped']++;
                continue;
            }

            $result = $this->create_order_with_retry($order_id);

            if ($result['success']) {
                // Remove from failed queue
                unset($failed_orders[$order_id]);
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $count++;
        }

        update_option('xwcpos_failed_orders', $failed_orders);

        return $results;
    }

    /**
     * Clear failed order from queue
     *
     * @param int $order_id Order ID
     */
    public function clear_failed_order($order_id) {
        $queue = get_option('xwcpos_failed_orders', array());
        unset($queue[$order_id]);
        update_option('xwcpos_failed_orders', $queue);
    }
}



