<?php
/**
 * Retry Strategy with Exponential Backoff
 * 
 * Implements intelligent retry logic with exponential backoff and jitter
 * for handling transient failures in API calls.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Retry_Strategy {
    
    /**
     * Maximum number of retry attempts
     * @var int
     */
    private $max_attempts = 5;
    
    /**
     * Base delay in seconds
     * @var float
     */
    private $base_delay = 1.0;
    
    /**
     * Maximum delay in seconds
     * @var float
     */
    private $max_delay = 60.0;
    
    /**
     * Exponential backoff multiplier
     * @var float
     */
    private $multiplier = 2.0;
    
    /**
     * Jitter factor (0-1)
     * @var float
     */
    private $jitter = 0.1;
    
    /**
     * Constructor
     *
     * @param int $max_attempts Maximum retry attempts
     * @param float $base_delay Base delay in seconds
     */
    public function __construct($max_attempts = 5, $base_delay = 1.0) {
        $this->max_attempts = $max_attempts;
        $this->base_delay = $base_delay;
    }
    
    /**
     * Execute a callable with retry logic
     *
     * @param callable $callable Function to execute
     * @param callable $is_retryable Function to determine if error is retryable
     * @return mixed Result from callable
     * @throws Exception If all retries exhausted
     */
    public function execute($callable, $is_retryable = null) {
        $attempt = 0;
        $last_error = null;
        
        while ($attempt < $this->max_attempts) {
            $attempt++;
            
            try {
                $result = call_user_func($callable);
                
                // Check if result indicates an error
                if (is_array($result) && isset($result['error'])) {
                    // Determine if we should retry
                    $should_retry = $is_retryable ? 
                        call_user_func($is_retryable, $result) : 
                        $this->is_retryable_error($result);
                    
                    if (!$should_retry || $attempt >= $this->max_attempts) {
                        return $result;
                    }
                    
                    $last_error = $result;
                    $this->wait_before_retry($attempt);
                    continue;
                }
                
                // Success
                return $result;
                
            } catch (Exception $e) {
                $last_error = $e;
                
                // Check if exception is retryable
                if ($attempt >= $this->max_attempts) {
                    throw $e;
                }
                
                $this->wait_before_retry($attempt);
            }
        }
        
        // All retries exhausted
        if ($last_error instanceof Exception) {
            throw $last_error;
        }
        
        return $last_error;
    }
    
    /**
     * Wait before retry with exponential backoff and jitter
     *
     * @param int $attempt Current attempt number
     */
    private function wait_before_retry($attempt) {
        $delay = min(
            $this->base_delay * pow($this->multiplier, $attempt - 1),
            $this->max_delay
        );
        
        // Add jitter to prevent thundering herd
        $jitter_amount = $delay * $this->jitter * (mt_rand() / mt_getrandmax());
        $delay += $jitter_amount;
        
        // Convert to microseconds for usleep
        usleep((int)($delay * 1000000));
    }
    
    /**
     * Determine if an error is retryable
     *
     * @param array $error Error array with 'error' and 'error_description'
     * @return bool True if retryable
     */
    private function is_retryable_error($error) {
        if (!isset($error['error'])) {
            return false;
        }
        
        $error_type = strtolower($error['error']);
        
        // Retryable errors
        $retryable_errors = array(
            'timeout',
            'network_error',
            'connection_failed',
            'service_unavailable',
            'internal_server_error',
            'bad_gateway',
            'gateway_timeout',
            'rate_limit_exceeded',
        );
        
        foreach ($retryable_errors as $retryable) {
            if (strpos($error_type, $retryable) !== false) {
                return true;
            }
        }
        
        // Check HTTP status codes if available
        if (isset($error['http_code'])) {
            $code = (int)$error['http_code'];
            // Retry on 5xx errors and 429 (rate limit)
            if ($code >= 500 || $code === 429) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get retry metadata for logging
     *
     * @param int $attempt Current attempt number
     * @return array Metadata
     */
    public function get_retry_metadata($attempt) {
        $delay = min(
            $this->base_delay * pow($this->multiplier, $attempt - 1),
            $this->max_delay
        );
        
        return array(
            'attempt' => $attempt,
            'max_attempts' => $this->max_attempts,
            'next_delay' => round($delay, 2),
            'remaining_attempts' => $this->max_attempts - $attempt,
        );
    }
}

