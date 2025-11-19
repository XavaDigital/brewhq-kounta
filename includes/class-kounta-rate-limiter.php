<?php
/**
 * Rate Limiter using Token Bucket Algorithm
 * 
 * Implements a token bucket rate limiter to prevent hitting API rate limits
 * while maximizing throughput.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Rate_Limiter {
    
    /**
     * Maximum number of tokens (requests) allowed
     * @var int
     */
    private $max_tokens;
    
    /**
     * Time window in seconds
     * @var int
     */
    private $time_window;
    
    /**
     * Current number of tokens available
     * @var float
     */
    private $tokens;
    
    /**
     * Last refill timestamp
     * @var float
     */
    private $last_refill;
    
    /**
     * Refill rate (tokens per second)
     * @var float
     */
    private $refill_rate;
    
    /**
     * Option key for persistent storage
     * @var string
     */
    private $option_key = 'kounta_rate_limiter_state';
    
    /**
     * Constructor
     *
     * @param int $max_requests Maximum requests allowed
     * @param int $time_window Time window in seconds
     */
    public function __construct($max_requests = 60, $time_window = 60) {
        $this->max_tokens = $max_requests;
        $this->time_window = $time_window;
        $this->refill_rate = $max_requests / $time_window;
        
        // Load state from database
        $this->load_state();
    }
    
    /**
     * Load rate limiter state from database
     */
    private function load_state() {
        $state = get_transient($this->option_key);
        
        if ($state === false) {
            // Initialize with full bucket
            $this->tokens = $this->max_tokens;
            $this->last_refill = microtime(true);
        } else {
            $this->tokens = $state['tokens'];
            $this->last_refill = $state['last_refill'];
            
            // Refill tokens based on time elapsed
            $this->refill_tokens();
        }
    }
    
    /**
     * Save rate limiter state to database
     */
    private function save_state() {
        $state = array(
            'tokens' => $this->tokens,
            'last_refill' => $this->last_refill,
        );
        
        // Store for 2x the time window to handle edge cases
        set_transient($this->option_key, $state, $this->time_window * 2);
    }
    
    /**
     * Refill tokens based on elapsed time
     */
    private function refill_tokens() {
        $now = microtime(true);
        $elapsed = $now - $this->last_refill;
        
        // Add tokens based on elapsed time
        $tokens_to_add = $elapsed * $this->refill_rate;
        $this->tokens = min($this->max_tokens, $this->tokens + $tokens_to_add);
        $this->last_refill = $now;
    }
    
    /**
     * Check if a request can be made
     *
     * @return bool
     */
    public function can_make_request() {
        $this->refill_tokens();
        return $this->tokens >= 1;
    }
    
    /**
     * Wait if needed before making a request
     */
    public function wait_if_needed() {
        $this->refill_tokens();
        
        if ($this->tokens < 1) {
            // Calculate wait time needed
            $tokens_needed = 1 - $this->tokens;
            $wait_seconds = $tokens_needed / $this->refill_rate;
            
            // Add small buffer to avoid edge cases
            $wait_microseconds = ($wait_seconds + 0.1) * 1000000;
            
            usleep((int)$wait_microseconds);
            
            // Refill after waiting
            $this->refill_tokens();
        }
    }
    
    /**
     * Record a request (consume a token)
     */
    public function record_request() {
        $this->refill_tokens();
        $this->tokens = max(0, $this->tokens - 1);
        $this->save_state();
    }
    
    /**
     * Handle rate limit response from API
     *
     * @param int $retry_after Seconds to wait (from Retry-After header)
     */
    public function handle_rate_limit($retry_after = null) {
        // Empty the bucket
        $this->tokens = 0;
        $this->save_state();

        // Determine wait time
        // If Retry-After header is provided, use it
        // Otherwise, use a conservative default (2 seconds instead of full time window)
        if ($retry_after) {
            $wait_seconds = (int)$retry_after;
        } else {
            // Default to 2 seconds - enough time for rate limit to reset
            // without waiting the full 60 second window
            $wait_seconds = 2;
        }

        // Log the wait time for debugging
        error_log(sprintf(
            '[Kounta Rate Limiter] Rate limit hit, waiting %d seconds before retry',
            $wait_seconds
        ));

        sleep($wait_seconds);

        // Refill after waiting
        $this->refill_tokens();
    }
}

