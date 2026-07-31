<?php
/**
 * Palami Shoppers Kagoma - Get User Data for Edit Modal
 */

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../classes/Security.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT user_id, username, full_name, email, phone, role, is_active FROM users WHERE user_id = ?");
    $stmt->execute([$_GET['id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>