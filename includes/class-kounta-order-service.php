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
        
        // Prepare order data
        $order_data = $this->prepare_order_data($order);
        
        if (isset($order_data['error'])) {
            return $order_data;
        }
        
        // Execute with retry logic
        try {
            $result = $this->retry_strategy->execute(
                function() use ($order_data, $order) {
                    return $this->upload_order($order_data, $order);
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
            
            $this->add_to_failed_queue($order_id, array(
                'error' => 'exception',
                'error_description' => $e->getMessage(),
            ));
            
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
            foreach ($order->get_items() as $item) {
                $item_data = $this->prepare_order_item($item, $order);
                if ($item_data) {
                    $order_items[] = $item_data;
                }
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
                }
            }

            if (empty($order_items)) {
                return array(
                    'error' => 'no_items',
                    'error_description' => 'No valid items in order',
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
     * @return mixed Kounta order ID or error array
     */
    private function upload_order($order_data, $order) {
        $account_id = get_option('xwcpos_account_id');
        $endpoint = 'companies/' . $account_id . '/orders';

        // Check if order already exists
        $existing_order = $this->find_order_by_sale_number($order_data);
        if ($existing_order) {
            return $existing_order->id;
        }

        // Create order
        $result = $this->api_client->make_request($endpoint, 'POST', array(), $order_data);

        if (is_wp_error($result)) {
            return array(
                'error' => 'api_error',
                'error_description' => $result->get_error_message(),
                'http_code' => $result->get_error_code(),
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
            return $this->plugin->get_kounta_customer($order);
        }

        // Fallback implementation
        $customer_details = array(
            'email' => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        );

        $account_id = get_option('xwcpos_account_id');
        $endpoint = 'companies/' . $account_id . '/customers';

        // Search by email
        $result = $this->api_client->make_request($endpoint, 'GET', array('email' => $customer_details['email']));

        if (!is_wp_error($result) && is_object($result) && isset($result->id)) {
            return $result->id;
        }

        // Create customer
        $result = $this->api_client->make_request($endpoint, 'POST', array(), $customer_details);

        if (!is_wp_error($result) && is_object($result) && isset($result->id)) {
            return $result->id;
        }

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
            return $this->plugin->get_order_item_for_upload($item, $order);
        }

        // Fallback implementation
        $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
        $kounta_product_id = get_post_meta($product_id, '_xwcpos_item_id', true);

        if (!$kounta_product_id) {
            return null;
        }

        return array(
            'product_id' => $kounta_product_id,
            'quantity' => $item->get_quantity(),
            'unit_price' => floatval($item->get_total()) / $item->get_quantity(),
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



