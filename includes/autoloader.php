<?php
/**
 * Autoloader for BrewHQ Kounta Classes
 * 
 * Automatically loads class files when they are needed.
 *
 * @package BrewHQ_Kounta
 * @since 2.0.0
 */

if (!defined('WPINC')) {
    die;
}

// Define the includes directory
if (!defined('BREWHQ_KOUNTA_INCLUDES_DIR')) {
    define('BREWHQ_KOUNTA_INCLUDES_DIR', plugin_dir_path(__FILE__));
}

/**
 * Autoload BrewHQ Kounta classes
 *
 * @param string $class_name Class name to load
 */
function brewhq_kounta_autoloader($class_name) {
    // Only autoload our classes
    if (strpos($class_name, 'Kounta_') !== 0) {
        return;
    }
    
    // Convert class name to file name
    // Kounta_API_Client => class-kounta-api-client.php
    $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
    $file_path = BREWHQ_KOUNTA_INCLUDES_DIR . $file_name;
    
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Register the autoloader
spl_autoload_register('brewhq_kounta_autoloader');

// Manually require classes that don't follow the naming convention
// (if any exist in the future)

