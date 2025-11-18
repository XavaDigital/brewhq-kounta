<?php
/**
 * Batch Processor for Parallel API Requests
 * 
 * Processes multiple API requests in parallel using WordPress HTTP API
 * with configurable concurrency limits.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

class Kounta_Batch_Processor {
    
    /**
     * API client instance
     * @var Kounta_API_Client
     */
    private $api_client;
    
    /**
     * Maximum concurrent requests
     * @var int
     */
    private $max_concurrent = 5;
    
    /**
     * Constructor
     *
     * @param Kounta_API_Client $api_client API client instance
     * @param int $max_concurrent Maximum concurrent requests
     */
    public function __construct($api_client, $max_concurrent = 5) {
        $this->api_client = $api_client;
        $this->max_concurrent = $max_concurrent;
    }
    
    /**
     * Process a batch of requests
     *
     * @param array $requests Array of request configurations
     * @return array Array of responses
     */
    public function process_batch($requests) {
        $results = array();
        $chunks = array_chunk($requests, $this->max_concurrent);
        
        foreach ($chunks as $chunk) {
            $chunk_results = $this->process_chunk($chunk);
            $results = array_merge($results, $chunk_results);
        }
        
        return $results;
    }
    
    /**
     * Process a chunk of requests in parallel
     *
     * @param array $chunk Array of request configurations
     * @return array Array of responses
     */
    private function process_chunk($chunk) {
        // For WordPress, we'll use a pseudo-parallel approach
        // True parallel requires curl_multi which may not be available
        $results = array();
        
        foreach ($chunk as $index => $request) {
            $endpoint = $request['endpoint'] ?? '';
            $method = $request['method'] ?? 'GET';
            $params = $request['params'] ?? array();
            $data = $request['data'] ?? array();
            
            $response = $this->api_client->make_request($endpoint, $method, $params, $data);
            
            $results[$index] = array(
                'request' => $request,
                'response' => $response,
                'success' => !is_wp_error($response),
                'error' => is_wp_error($response) ? $response->get_error_message() : null,
            );
        }
        
        return $results;
    }
    
    /**
     * Process database updates in batch
     *
     * @param array $updates Array of database update operations
     * @return int Number of successful updates
     */
    public function batch_database_updates($updates) {
        global $wpdb;
        
        $success_count = 0;
        
        // Group updates by table
        $grouped_updates = array();
        foreach ($updates as $update) {
            $table = $update['table'];
            if (!isset($grouped_updates[$table])) {
                $grouped_updates[$table] = array();
            }
            $grouped_updates[$table][] = $update;
        }
        
        // Process each table's updates
        foreach ($grouped_updates as $table => $table_updates) {
            $success_count += $this->batch_update_table($table, $table_updates);
        }
        
        return $success_count;
    }
    
    /**
     * Batch update a single table
     *
     * @param string $table Table name
     * @param array $updates Array of update operations
     * @return int Number of successful updates
     */
    private function batch_update_table($table, $updates) {
        global $wpdb;

        $success_count = 0;
        $error_count = 0;
        $zero_rows_count = 0;

        // Use INSERT ... ON DUPLICATE KEY UPDATE for better performance
        if (count($updates) > 0) {
            foreach ($updates as $update) {
                $where = $update['where'] ?? array();
                $data = $update['data'] ?? array();

                if (empty($where) || empty($data)) {
                    $error_count++;
                    $this->log_error('Batch update skipped: empty where or data clause', array(
                        'table' => $table,
                        'where' => $where,
                        'data' => $data,
                    ));
                    continue;
                }

                $result = $wpdb->update($table, $data, $where);

                if ($result === false) {
                    $error_count++;
                    $this->log_error('Database update failed', array(
                        'table' => $table,
                        'where' => $where,
                        'data' => $data,
                        'error' => $wpdb->last_error,
                    ));
                } elseif ($result === 0) {
                    // 0 rows affected - might be because data is the same or row doesn't exist
                    $zero_rows_count++;
                } else {
                    $success_count++;
                }
            }
        }

        // Log summary if there were issues
        if ($error_count > 0 || $zero_rows_count > 5) {
            $this->log_error('Batch update summary', array(
                'table' => $table,
                'total_updates' => count($updates),
                'successful' => $success_count,
                'errors' => $error_count,
                'zero_rows_affected' => $zero_rows_count,
            ));
        }

        return $success_count;
    }

    /**
     * Log error with context
     *
     * @param string $message Error message
     * @param array $context Context data
     */
    private function log_error($message, $context = array()) {
        $log_message = '[Batch Processor] ' . $message;
        if (!empty($context)) {
            $log_message .= ' - Context: ' . json_encode($context);
        }

        error_log($log_message);

        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[BrewHQ Kounta] ' . $log_message);
        }
    }
    
    /**
     * Batch insert records
     *
     * @param string $table Table name
     * @param array $records Array of records to insert
     * @return int Number of successful inserts
     */
    public function batch_insert($table, $records) {
        global $wpdb;

        if (empty($records)) {
            $this->log_error('Batch insert called with empty records', array('table' => $table));
            return 0;
        }

        $success_count = 0;
        $error_count = 0;

        foreach ($records as $index => $record) {
            if (empty($record)) {
                $error_count++;
                $this->log_error('Batch insert skipped: empty record', array(
                    'table' => $table,
                    'index' => $index,
                ));
                continue;
            }

            $result = $wpdb->insert($table, $record);

            if ($result === false) {
                $error_count++;
                $this->log_error('Database insert failed', array(
                    'table' => $table,
                    'index' => $index,
                    'record' => $record,
                    'error' => $wpdb->last_error,
                ));
            } else {
                $success_count++;
            }
        }

        // Log summary if there were errors
        if ($error_count > 0) {
            $this->log_error('Batch insert summary', array(
                'table' => $table,
                'total_records' => count($records),
                'successful' => $success_count,
                'errors' => $error_count,
            ));
        }

        return $success_count;
    }
}

