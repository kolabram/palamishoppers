<?php
/**
 * Palami Shoppers Kagoma - Bootstrap
 * Load all required files and configurations
 */

// Define root path
define('ROOT_PATH', __DIR__);
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('CLASSES_PATH', ROOT_PATH . '/classes');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Load configuration files
require_once CONFIG_PATH . '/database.php';
require_once CONFIG_PATH . '/session.php';
require_once CLASSES_PATH . '/Security.php';

// Load AuditLogger only if needed (not on every request)
// It will be loaded when needed by the autoloader or specific includes

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define constants
define('APP_NAME', 'Palami Shoppers Kagoma');
define('APP_VERSION', '1.0.0');
define('CURRENCY', 'UGX');
define('CURRENCY_SYMBOL', 'UGX ');

// Simple autoloader for classes
spl_autoload_register(function ($class_name) {
    $file = CLASSES_PATH . '/' . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    return false;
});
?>