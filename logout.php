<?php
/**
 * Palami Shoppers Kagoma - Logout
 */

// Load autoloader first
require_once __DIR__ . '/bootstrap.php';

// Start session
SessionManager::startSession();

// Log logout if user is logged in
if (isset($_SESSION['palami_user_id'])) {
    try {
        // Check if AuditLogger class exists before using it
        if (class_exists('AuditLogger')) {
            $auditLogger = new AuditLogger();
            $auditLogger->log($_SESSION['palami_user_id'], 'logout');
        }
    } catch (Exception $e) {
        // Log error but continue with logout
        error_log("Logout audit error: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear remember me cookie if set
if (isset($_COOKIE['palami_username'])) {
    setcookie('palami_username', '', time() - 3600, '/');
}

// Redirect to login page
header('Location: login.php');
exit();
?>