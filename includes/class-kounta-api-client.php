<?php
/**
 * Kounta API Client with Rate Limiting
 * 
 * Handles all API communication with Kounta POS with built-in rate limiting
 * and retry logic.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_API_Client {
    
    /**
     * Rate limiter instance
     * @var Kounta_Rate_Limiter
     */
    private $rate_limiter;
    
    /**
     * Account ID
     * @var string
     */
    private $account_id;
    
    /**
     * Access token
     * @var string
     */
    private $access_token;
    
    /**
     * Client ID
     * @var string
     */
    private $client_id;
    
    /**
     * Client secret
     * @var string
     */
    private $client_secret;
    
    /**
     * Refresh token
     * @var string
     */
    private $refresh_token;
    
    /**
     * API base URL
     * @var string
     */
    private $api_base_url = 'https://api.kounta.com/v1/';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->account_id = esc_attr(get_option('xwcpos_account_id'));
        $this->access_token = esc_attr(get_option('xwcpos_access_token'));
        $this->client_id = esc_attr(get_option('xwcpos_client_id'));
        $this->client_secret = esc_attr(get_option('xwcpos_client_secret'));
        $this->refresh_token = esc_attr(get_option('xwcpos_refresh_token'));

        // Initialize rate limiter (60 requests per minute as default)
        $this->rate_limiter = new Kounta_Rate_Limiter(60, 60);
    }

    /**
     * Check if API credentials are configured
     *
     * @return bool|WP_Error True if configured, WP_Error otherwise
     */
    public function check_credentials() {
        if (empty($this->account_id)) {
            return new WP_Error('missing_account_id', 'Kounta Account ID is not configured. Please configure the plugin settings.');
        }

        if (empty($this->access_token) && (empty($this->client_id) || empty($this->client_secret))) {
            return new WP_Error('missing_credentials', 'Kounta API credentials are not configured. Please configure either Access Token or Client ID/Secret in plugin settings.');
        }

        return true;
    }
    
    /**
     * Make an API call with rate limiting
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $params Query parameters
     * @param array $data Request body data
     * @return mixed API response or WP_Error
     */
    public function make_request($endpoint, $method = 'GET', $params = array(), $data = array()) {
        // Wait for rate limiter
        $this->rate_limiter->wait_if_needed();

        $url = $this->api_base_url . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        // Log request details (excluding sensitive data)
        $this->log(sprintf(
            'API Request: %s %s',
            $method,
            $endpoint
        ));

        $args = array(
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        );

        // Use OAuth token if available, otherwise use Basic Auth
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        } elseif (!empty($this->client_id) && !empty($this->client_secret)) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret);
        } else {
            $error = new WP_Error('no_credentials', 'No API credentials configured. Please configure the plugin settings.');
            $this->log('ERROR: ' . $error->get_error_message());
            return $error;
        }

        if (!empty($data) && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = is_string($data) ? $data : json_encode($data);
        }

        $start_time = microtime(true);
        $response = wp_remote_request($url, $args);
        $duration = microtime(true) - $start_time;

        // Record the request for rate limiting
        $this->rate_limiter->record_request();

        if (is_wp_error($response)) {
            $this->log(sprintf(
                'ERROR: API request failed - %s (Duration: %.2fs)',
                $response->get_error_message(),
                $duration
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Log response status
        $this->log(sprintf(
            'API Response: HTTP %d (Duration: %.2fs)',
            $status_code,
            $duration
        ));
        
        // Handle rate limiting
        if ($status_code === 429) {
            $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
            $this->log('WARNING: Rate limit hit (HTTP 429), retrying after: ' . ($retry_after ?: 'default delay'));
            $this->rate_limiter->handle_rate_limit($retry_after);
            // Retry the request
            return $this->make_request($endpoint, $method, $params, $data);
        }

        // Handle token refresh (only if using OAuth)
        if ($status_code === 401 && !empty($this->access_token)) {
            $this->log('WARNING: Authentication failed (HTTP 401), attempting token refresh');
            $refresh_result = $this->refresh_access_token();
            if ($refresh_result === true) {
                $this->log('INFO: Token refreshed successfully, retrying request');
                // Retry with new token
                return $this->make_request($endpoint, $method, $params, $data);
            }

            // Return detailed error message
            if (is_wp_error($refresh_result)) {
                $this->log('ERROR: Token refresh failed - ' . $refresh_result->get_error_message());
                return $refresh_result;
            }

            $error = new WP_Error('auth_failed', 'Authentication failed: Unable to refresh access token. Please check your API credentials in the plugin settings.');
            $this->log('ERROR: ' . $error->get_error_message());
            return $error;
        }

        if ($status_code === 401) {
            $error = new WP_Error('auth_failed', 'Authentication failed (HTTP 401). Please check your API credentials in the plugin settings.');
            $this->log('ERROR: ' . $error->get_error_message());
            return $error;
        }

        if ($status_code >= 400) {
            $error_body = json_decode($body);
            $error_msg = isset($error_body->error_description) ? $error_body->error_description : $body;
            $full_error = 'API Error ' . $status_code . ': ' . $error_msg;
            $this->log('ERROR: ' . $full_error);

            // Log additional error details for debugging
            if ($status_code >= 500) {
                $this->log('ERROR: Server error details - Endpoint: ' . $endpoint . ', Method: ' . $method);
            }

            return new WP_Error('api_error', $full_error, array('http_code' => $status_code));
        }

        $decoded = json_decode($body);

        // Check for JSON decode errors
        if ($body && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log('ERROR: Failed to decode JSON response - ' . json_last_error_msg() . ' - Body preview: ' . substr($body, 0, 200));
        }

        return $decoded;
    }

    /**
     * Make multiple parallel API requests
     *
     * @param array $requests Array of request configurations
     * @return array Array of responses
     */
    public function make_parallel_requests($requests) {
        $batch_processor = new Kounta_Batch_Processor($this);
        return $batch_processor->process_batch($requests);
    }

    /**
     * Get inventory for a site
     *
     * @param string $site_id Site ID
     * @param string $start_id Starting product ID for pagination
     * @return mixed API response
     */
    public function get_inventory($site_id, $start_id = null) {
        $params = array();
        if ($start_id) {
            $params['start'] = $start_id;
        }

        $endpoint = 'companies/' . $this->account_id . '/sites/' . $site_id . '/inventory';
        return $this->make_request($endpoint, 'GET', $params);
    }

    /**
     * Get all inventory with automatic pagination
     *
     * @param string $site_id Site ID
     * @return array All inventory items
     */
    public function get_all_inventory($site_id) {
        $all_products = array();
        $last_product_id = null;
        $page = 0;

        $this->log('Starting inventory fetch for site: ' . $site_id);

        do {
            $page++;
            $this->log("Fetching inventory page {$page}, starting from ID: " . ($last_product_id ?: 'beginning'));

            $products = $this->get_inventory($site_id, $last_product_id);

            if (is_wp_error($products)) {
                $this->log('ERROR: Inventory fetch failed - ' . $products->get_error_message());
                return $products;
            }

            if (empty($products)) {
                $this->log('No more products returned, pagination complete');
                break;
            }

            $this->log('Retrieved ' . count($products) . ' products on page ' . $page);
            $all_products = array_merge($all_products, $products);

            // Get last product ID for pagination
            $last_product = end($products);
            $last_product_id = $last_product->id ?? null;

        } while (!empty($products));

        $this->log('Inventory fetch complete: ' . count($all_products) . ' total products');
        return $all_products;
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     */
    private function log($message) {
        if (class_exists('BrewHQ_Kounta_POS_Int')) {
            $plugin = new BrewHQ_Kounta_POS_Int();
            $plugin->plugin_log('[API Client] ' . $message);
        }

        // Also log to PHP error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta API] ' . $message);
        }
    }

    /**
     * Refresh the access token
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function refresh_access_token() {
        // Check if we have refresh credentials
        if (empty($this->refresh_token) || empty($this->client_id) || empty($this->client_secret)) {
            return new WP_Error(
                'missing_refresh_credentials',
                'Cannot refresh token: Missing refresh_token, client_id, or client_secret. Please reconfigure the plugin.'
            );
        }

        $url = 'https://api.kounta.com/v1/token';

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ),
            'timeout' => 30,
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'token_refresh_failed',
                'Token refresh request failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response));

        if ($status_code !== 200) {
            $error_msg = isset($body->error_description) ? $body->error_description : 'Unknown error';
            return new WP_Error(
                'token_refresh_error',
                'Token refresh failed (HTTP ' . $status_code . '): ' . $error_msg
            );
        }

        if (isset($body->access_token)) {
            $this->access_token = $body->access_token;
            update_option('xwcpos_access_token', $body->access_token);

            if (isset($body->refresh_token)) {
                $this->refresh_token = $body->refresh_token;
                update_option('xwcpos_refresh_token', $body->refresh_token);
            }

            return true;
        }

        return new WP_Error(
            'invalid_token_response',
            'Token refresh response did not contain access_token'
        );
    }

    /**
     * Get account ID
     *
     * @return string
     */
    public function get_account_id() {
        return $this->account_id;
    }
}
