<?php
/**
 * Palami Shoppers Kagoma - User Management
 * Supermarket Management System
 */

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../classes/Security.php';
require_once '../classes/AuditLogger.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();
$message = '';
$error = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'toggle_status':
                $userId = (int)$_POST['user_id'];
                $status = (int)$_POST['status'];
                
                $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
                $stmt->execute([$status, $userId]);
                
                $auditLogger->log($_SESSION['user_id'], 'toggle_user_status', 'users', $userId);
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_user':
                $userId = (int)$_POST['user_id'];
                
                // Check if user exists and is not the current user
                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    break;
                }
                
                $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $auditLogger->log($_SESSION['user_id'], 'delete_user', 'users', $userId);
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!SessionManager::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            if ($_POST['action'] === 'add_user') {
                // Validate inputs
                $username = Security::sanitizeInput($_POST['username']);
                $fullName = Security::sanitizeInput($_POST['full_name']);
                $email = Security::sanitizeInput($_POST['email']);
                $phone = Security::sanitizeInput($_POST['phone']);
                $role = Security::sanitizeInput($_POST['role']);
                $password = $_POST['password'];
                $confirmPassword = $_POST['confirm_password'];
                
                // Validate
                if (!Security::validateUsername($username)) {
                    $error = 'Username must be 3-50 characters (letters, numbers, underscore)';
                } elseif (!Security::validateEmail($email)) {
                    $error = 'Invalid email address';
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters';
                } elseif ($password !== $confirmPassword) {
                    $error = 'Passwords do not match';
                } elseif (!Security::validatePassword($password)) {
                    $error = 'Password must contain at least one uppercase, lowercase, and number';
                } else {
                    // Check if username or email exists
                    $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
                    $stmt->execute([$username, $email]);
                    if ($stmt->fetch()) {
                        $error = 'Username or email already exists';
                    } else {
                        // Insert user
                        $hashedPassword = Security::hashPassword($password);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password_hash, full_name, email, phone, role, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$username, $hashedPassword, $fullName, $email, $phone, $role]);
                        $newUserId = $db->lastInsertId();
                        
                        $auditLogger->log($_SESSION['user_id'], 'create_user', 'users', $newUserId);
                        $message = 'User created successfully!';
                    }
                }
            } elseif ($_POST['action'] === 'edit_user') {
                $userId = (int)$_POST['user_id'];
                $fullName = Security::sanitizeInput($_POST['full_name']);
                $email = Security::sanitizeInput($_POST['email']);
                $phone = Security::sanitizeInput($_POST['phone']);
                $role = Security::sanitizeInput($_POST['role']);
                
                // Check if email is taken by another user
                $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    $error = 'Email already in use by another user';
                } else {
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET full_name = ?, email = ?, phone = ?, role = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$fullName, $email, $phone, $role, $userId]);
                    
                    // Update password if provided
                    if (!empty($_POST['new_password'])) {
                        $newPassword = $_POST['new_password'];
                        $confirmNewPassword = $_POST['confirm_new_password'];
                        
                        if ($newPassword !== $confirmNewPassword) {
                            $error = 'New passwords do not match';
                        } elseif (strlen($newPassword) >= 8) {
                            $hashedPassword = Security::hashPassword($newPassword);
                            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                            $stmt->execute([$hashedPassword, $userId]);
                        }
                    }
                    
                    if (empty($error)) {
                        $auditLogger->log($_SESSION['user_id'], 'update_user', 'users', $userId);
                        $message = 'User updated successfully!';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get all users
$users = [];
try {
    $stmt = $db->query("
        SELECT u.*, 
               (SELECT COUNT(*) FROM sales WHERE user_id = u.user_id) as sales_count,
               (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.user_id) as activity_count
        FROM users u
        ORDER BY u.user_id DESC
    ");
    $users = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load users: ' . $e->getMessage();
}

// Get roles for dropdown
$roles = ['admin', 'cashier', 'inventory_manager'];
$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - User Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ========================================
                   RESET & BASE STYLES
                ======================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            color: #333;
        }
        
        a {
            text-decoration: none;
        }
        
        /* ========================================
                   HEADER STYLES
                ======================================== */
        .header {
            background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%);
            color: white;
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 15px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-logo img {
            height: 45px;
            width: auto;
            filter: brightness(0) invert(1);
        }
        
        .header-logo h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffd700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: 0.5px;
        }
        
        .header-logo .subtitle {
            font-size: 11px;
            opacity: 0.8;
            font-weight: 300;
            color: #bbdefb;
            letter-spacing: 1px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .header-right .user-info {
            text-align: right;
        }
        
        .header-right .user-name {
            font-weight: 600;
            color: #ffd700;
            font-size: 15px;
        }
        
        .header-right .user-role {
            font-size: 11px;
            opacity: 0.8;
            color: #bbdefb;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .logout-btn {
            color: white;
            text-decoration: none;
            padding: 8px 20px;
            background: rgba(255, 215, 0, 0.15);
            border-radius: 6px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 215, 0, 0.25);
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: rgba(255, 215, 0, 0.25);
            border-color: #ffd700;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 215, 0, 0.2);
        }
        
        /* ========================================
                   NAVIGATION STYLES
                ======================================== */
        .nav-container {
            background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%);
            padding: 0 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: sticky;
            top: 69px;
            z-index: 999;
            border-bottom: 4px solid #ffd700;
        }
        
        .nav {
            display: flex;
            gap: 2px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex-wrap: wrap;
        }
        
        .nav-item {
            position: relative;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #bbdefb;
            text-decoration: none;
            padding: 15px 22px;
            font-weight: 500;
            font-size: 14px;
            border-bottom: 3px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            background: transparent;
            border-top: none;
            border-left: none;
            border-right: none;
            font-family: inherit;
            letter-spacing: 0.3px;
            position: relative;
        }
        
        .nav-link i {
            font-size: 16px;
            transition: all 0.3s ease;
            color: #64b5f6;
        }
        
        .nav-link .arrow {
            font-size: 10px;
            margin-left: 6px;
            transition: transform 0.3s ease;
            color: #64b5f6;
        }
        
        .nav-link:hover {
            color: #ffffff;
            background: rgba(100, 181, 246, 0.15);
            border-bottom-color: #64b5f6;
            transform: translateY(-2px);
        }
        
        .nav-link:hover i {
            color: #ffd700;
            transform: translateY(-2px) scale(1.1);
        }
        
        .nav-link:hover .arrow {
            transform: rotate(180deg);
            color: #ffd700;
        }
        
        .nav-link.active {
            color: #ffffff;
            border-bottom-color: #ffd700;
            background: rgba(255, 215, 0, 0.1);
        }
        
        .nav-link.active i {
            color: #ffd700;
        }
        
        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: #ffd700;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { width: 0; left: 50%; }
            to { width: 100%; left: 0; }
        }
        
        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            width: 0;
            height: 3px;
            background: #ffd700;
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }
        
        .nav-link:hover::before {
            width: 80%;
        }
        
        .nav-link.active::before {
            width: 100%;
        }
        
        /* ========================================
                   DROPDOWN SUBMENU STYLES
                ======================================== */
        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 250px;
            background: #ffffff;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scaleY(0.9);
            transform-origin: top center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            list-style: none;
            padding: 8px 0;
            z-index: 1000;
            border-top: 4px solid #ffd700;
        }
        
        .nav-item:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scaleY(1);
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #333;
            text-decoration: none;
            font-size: 13.5px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            position: relative;
        }
        
        .dropdown-item i {
            width: 22px;
            color: #1a237e;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(90deg, #e3f2fd 0%, #f5f9ff 100%);
            border-left-color: #ffd700;
            padding-left: 28px;
            color: #0d47a1;
        }
        
        .dropdown-item:hover i {
            color: #ffd700;
            transform: scale(1.15) rotate(-5deg);
        }
        
        .dropdown-item.active {
            background: #e3f2fd;
            border-left-color: #ffd700;
            color: #0d47a1;
            font-weight: 600;
        }
        
        .dropdown-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #64b5f6, #ffd700, #64b5f6, transparent);
            margin: 6px 15px;
        }
        
        .badge-nav {
            background: #ffd700;
            color: #1a237e;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            margin-left: auto;
            transition: all 0.3s ease;
        }
        
        .badge-nav.danger {
            background: #e74c3c;
            color: white;
            animation: pulse-badge 2s infinite;
        }
        
        .badge-nav.success {
            background: #27ae60;
            color: white;
        }
        
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .notification-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #e74c3c;
            border-radius: 50%;
            margin-left: 5px;
            animation: pulse-dot 1.5s infinite;
            box-shadow: 0 0 10px rgba(231, 76, 60, 0.5);
        }
        
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.5); opacity: 0.7; }
        }
        
        /* ========================================
                   MAIN CONTENT STYLES
                ======================================== */
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h2 {
            color: #1a237e;
            font-size: 24px;
            font-weight: 700;
        }
        
        .page-header h2 i {
            color: #ffd700;
            margin-right: 10px;
        }
        
        .page-header .breadcrumb {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .page-header .breadcrumb a {
            color: #1a237e;
        }
        
        /* Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Stats Cards */
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-mini {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .stat-mini .number {
            font-size: 28px;
            font-weight: 700;
            color: #1a237e;
        }
        
        .stat-mini .label {
            color: #7f8c8d;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Action Buttons */
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1a237e, #0d47a1);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 35, 126, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
        }
        
        .btn-outline {
            background: transparent;
            color: #1a237e;
            border: 2px solid #1a237e;
        }
        
        .btn-outline:hover {
            background: #1a237e;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            background: #e3f2fd;
            padding: 14px 18px;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            border-bottom: 2px solid #bbdefb;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        table td {
            padding: 14px 18px;
            border-bottom: 1px solid #e3f2fd;
            font-size: 14px;
            transition: background 0.3s ease;
        }
        
        table tr:hover td {
            background: #f5f9ff;
        }
        
        table tr:last-child td {
            border-bottom: none;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-admin {
            background: #e74c3c;
            color: white;
        }
        
        .badge-cashier {
            background: #3498db;
            color: white;
        }
        
        .badge-inventory {
            background: #f39c12;
            color: white;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal.active {
            display: flex;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e3f2fd;
        }
        
        .modal-header h3 {
            color: #1a237e;
            font-size: 20px;
        }
        
        .modal-header h3 i {
            color: #ffd700;
            margin-right: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #95a5a6;
            transition: color 0.3s;
        }
        
        .modal-close:hover {
            color: #e74c3c;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #e74c3c;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1a237e;
            outline: none;
        }
        
        .form-group .help-text {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 4px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        /* Role indicator */
        .role-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .role-indicator i {
            font-size: 14px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 10px 15px;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .header-left {
                justify-content: center;
            }
            
            .header-logo {
                flex-direction: column;
                text-align: center;
            }
            
            .header-logo h1 {
                font-size: 16px;
            }
            
            .header-right {
                justify-content: center;
            }
            
            .nav-container {
                padding: 0 10px;
                overflow-x: auto;
                top: auto;
            }
            
            .nav {
                flex-wrap: nowrap;
                gap: 0;
                min-width: max-content;
            }
            
            .nav-link {
                padding: 12px 14px;
                font-size: 12px;
                white-space: nowrap;
            }
            
            .nav-link span:not(.arrow) {
                display: none;
            }
            
            .nav-link i {
                font-size: 18px;
            }
            
            .dropdown-menu {
                position: static;
                box-shadow: none;
                border-radius: 0;
                border-top: none;
                background: #e3f2fd;
                padding: 5px 0;
            }
            
            .nav-item:hover .dropdown-menu {
                transform: none;
            }
            
            .dropdown-item {
                padding: 8px 20px 8px 40px;
            }
            
            .container {
                padding: 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 20px;
                width: 95%;
            }
            
            .stats-mini {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stats-mini {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- ==========================================
    HEADER
    ========================================== -->
    <header class="header">
        <div class="header-left">
            <div class="header-logo">
                <img src="../assets/images/logo.png" alt="Palami Shoppers Kagoma" onerror="this.style.display='none'">
                <div>
                    <h1>Palami Shoppers Kagoma</h1>
                    <div class="subtitle">Supermarket Management System</div>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <div class="user-name">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?>
                </div>
                <div class="user-role">
                    <i class="fas fa-shield-alt"></i>
                    Administrator
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </header>

    <!-- ==========================================
    NAVIGATION WITH SUBMENUS
    ========================================== -->
    <nav class="nav-container">
        <ul class="nav">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <button class="nav-link <?php echo $currentPage == 'users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="users.php" class="dropdown-item <?php echo $currentPage == 'users.php' && !isset($_GET['action']) ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            Manage Users
                            <span class="badge-nav"><?php echo count($users); ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="users.php?action=add" class="dropdown-item <?php echo isset($_GET['action']) && $_GET['action'] == 'add' ? 'active' : ''; ?>">
                            <i class="fas fa-user-plus"></i>
                            Add New User
                        </a>
                    </li>
                    <li>
                        <a href="roles.php" class="dropdown-item">
                            <i class="fas fa-user-tag"></i>
                            Roles &amp; Permissions
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a href="activity.php" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            User Activity
                            <span class="badge-nav danger">
                                <?php
                                    $stmt = $db->query("SELECT COUNT(*) as count FROM audit_logs WHERE log_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                                    $count = $stmt->fetch();
                                    echo $count['count'] ?? 0;
                                ?>
                            </span>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-boxes"></i>
                    <span>Products</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="products.php" class="dropdown-item"><i class="fas fa-list"></i> All Products</a></li>
                    <li><a href="products.php?action=add" class="dropdown-item"><i class="fas fa-plus-circle"></i> Add Product</a></li>
                    <li><a href="categories.php" class="dropdown-item"><i class="fas fa-tags"></i> Categories</a></li>
                    <li><a href="barcode.php" class="dropdown-item"><i class="fas fa-barcode"></i> Generate Barcode</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="low-stock.php" class="dropdown-item"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Sales</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="sales.php" class="dropdown-item"><i class="fas fa-receipt"></i> All Sales</a></li>
                    <li><a href="../cashier/pos.php" class="dropdown-item" target="_blank"><i class="fas fa-cash-register"></i> New Sale (POS)</a></li>
                    <li><a href="sales.php?action=returns" class="dropdown-item"><i class="fas fa-undo"></i> Returns</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="invoices.php" class="dropdown-item"><i class="fas fa-file-invoice"></i> Invoices</a></li>
                    <li><a href="payments.php" class="dropdown-item"><i class="fas fa-credit-card"></i> Payment Methods</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="reports.php?type=sales" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                    <li><a href="reports.php?type=inventory" class="dropdown-item"><i class="fas fa-warehouse"></i> Inventory Report</a></li>
                    <li><a href="reports.php?type=products" class="dropdown-item"><i class="fas fa-box"></i> Product Report</a></li>
                    <li><a href="reports.php?type=users" class="dropdown-item"><i class="fas fa-users"></i> User Activity Report</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="reports.php?action=export" class="dropdown-item"><i class="fas fa-file-export"></i> Export Reports</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Logs</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="audit.php" class="dropdown-item"><i class="fas fa-list-ul"></i> All Logs</a></li>
                    <li><a href="audit.php?filter=login" class="dropdown-item"><i class="fas fa-sign-in-alt"></i> Login History</a></li>
                    <li><a href="audit.php?filter=sales" class="dropdown-item"><i class="fas fa-shopping-cart"></i> Sales Logs</a></li>
                    <li><a href="audit.php?filter=inventory" class="dropdown-item"><i class="fas fa-archive"></i> Inventory Changes</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    <span>Inventory</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="inventory.php" class="dropdown-item"><i class="fas fa-list"></i> Stock Overview</a></li>
                    <li><a href="inventory.php?action=adjust" class="dropdown-item"><i class="fas fa-edit"></i> Adjust Stock</a></li>
                    <li><a href="inventory.php?action=transfer" class="dropdown-item"><i class="fas fa-exchange-alt"></i> Stock Transfer</a></li>
                    <li><a href="suppliers.php" class="dropdown-item"><i class="fas fa-truck"></i> Suppliers</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="inventory.php?action=history" class="dropdown-item"><i class="fas fa-history"></i> Transaction History</a></li>
                </ul>
            </li>
        </ul>
    </nav>

    <!-- ==========================================
    MAIN CONTENT
    ========================================== -->
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h2><i class="fas fa-users"></i> User Management</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / User Management
                </div>
            </div>
            <button class="btn btn-primary" onclick="openModal('addUserModal')">
                <i class="fas fa-user-plus"></i> Add New User
            </button>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Mini Stats -->
        <div class="stats-mini">
            <div class="stat-mini">
                <div class="number"><?php echo count($users); ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#27ae60;">
                    <?php
                        $active = array_filter($users, function($u) { return $u['is_active'] == 1; });
                        echo count($active);
                    ?>
                </div>
                <div class="label">Active Users</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#e74c3c;">
                    <?php
                        $inactive = array_filter($users, function($u) { return $u['is_active'] == 0; });
                        echo count($inactive);
                    ?>
                </div>
                <div class="label">Inactive Users</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#f39c12;">
                    <?php
                        $admins = array_filter($users, function($u) { return $u['role'] == 'admin'; });
                        echo count($admins);
                    ?>
                </div>
                <div class="label">Administrators</div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-users" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No users found. Click "Add New User" to create one.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><strong>#<?php echo $user['user_id']; ?></strong></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#1a237e,#64b5f6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">
                                            <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            <div style="font-size:12px;color:#95a5a6;">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>" style="color:#1a237e;">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $user['role']; ?>">
                                        <?php
                                            $roleIcons = [
                                                'admin' => 'fa-shield-alt',
                                                'cashier' => 'fa-cash-register',
                                                'inventory_manager' => 'fa-warehouse'
                                            ];
                                        ?>
                                        <i class="fas <?php echo $roleIcons[$user['role']] ?? 'fa-user'; ?>"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $user['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $user['is_active'] ? '✅ Active' : '❌ Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size:12px;color:#7f8c8d;">
                                        <div>Sales: <?php echo $user['sales_count'] ?? 0; ?></div>
                                        <div>Actions: <?php echo $user['activity_count'] ?? 0; ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <button class="btn btn-warning btn-sm" onclick="editUser(<?php echo $user['user_id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm <?php echo $user['is_active'] ? 'btn-danger' : 'btn-success'; ?>" 
                                                onclick="toggleUser(<?php echo $user['user_id']; ?>, <?php echo $user['is_active'] ? 0 : 1; ?>)">
                                            <i class="fas <?php echo $user['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger btn-sm" onclick="deleteUser(<?php echo $user['user_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ==========================================
    ADD USER MODAL
    ========================================== -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateForm('add')">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="full_name" required placeholder="Enter full name">
                    </div>
                    <div class="form-group">
                        <label>Username <span class="required">*</span></label>
                        <input type="text" name="username" required placeholder="Enter username">
                        <div class="help-text">3-50 characters, letters, numbers, underscore only</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" required placeholder="Enter email address">
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" placeholder="Enter phone number">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin">Administrator</option>
                        <option value="cashier">Cashier</option>
                        <option value="inventory_manager">Inventory Manager</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" id="addPassword" required placeholder="Enter password">
                        <div class="help-text">Min 8 characters with uppercase, lowercase, and number</div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password <span class="required">*</span></label>
                        <input type="password" name="confirm_password" required placeholder="Confirm password">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
    EDIT USER MODAL
    ========================================== -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit User</h3>
                <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateForm('edit')">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="edit_username" disabled style="background:#f5f5f5;">
                        <div class="help-text">Username cannot be changed</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="edit_phone">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" id="edit_role" required>
                        <option value="admin">Administrator</option>
                        <option value="cashier">Cashier</option>
                        <option value="inventory_manager">Inventory Manager</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>New Password <span style="color:#95a5a6;font-weight:400;">(leave blank to keep current)</span></label>
                        <input type="password" name="new_password" id="edit_new_password" placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_new_password" placeholder="Confirm new password">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        // ========================================
        // Modal Functions
        // ========================================
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // Close modal on outside click
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // ========================================
        // Edit User Function
        // ========================================
        function editUser(userId) {
            // Fetch user data via AJAX
            fetch('get_user.php?id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_user_id').value = data.user.user_id;
                        document.getElementById('edit_full_name').value = data.user.full_name;
                        document.getElementById('edit_username').value = data.user.username;
                        document.getElementById('edit_email').value = data.user.email;
                        document.getElementById('edit_phone').value = data.user.phone || '';
                        document.getElementById('edit_role').value = data.user.role;
                        document.getElementById('edit_new_password').value = '';
                        document.getElementById('edit_new_password').placeholder = 'Leave blank to keep current';
                        
                        openModal('editUserModal');
                    } else {
                        alert('Failed to load user data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user data');
                });
        }
        
        // ========================================
        // Toggle User Status
        // ========================================
        function toggleUser(userId, status) {
            const action = status ? 'activate' : 'deactivate';
            if (!confirm(`Are you sure you want to ${action} this user?`)) return;
            
            fetch('users.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=toggle_status&user_id=' + userId + '&status=' + status
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update user status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating user status');
            });
        }
        
        // ========================================
        // Delete User
        // ========================================
        function deleteUser(userId) {
            if (!confirm('⚠️ Are you sure you want to permanently delete this user?\nThis action cannot be undone!')) return;
            if (!confirm('Are you absolutely sure?')) return;
            
            fetch('users.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=delete_user&user_id=' + userId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to delete user: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting user');
            });
        }
        
        // ========================================
        // Form Validation
        // ========================================
        function validateForm(type) {
            if (type === 'add') {
                const password = document.getElementById('addPassword').value;
                if (password.length < 8) {
                    alert('Password must be at least 8 characters long');
                    return false;
                }
                if (!/[A-Z]/.test(password)) {
                    alert('Password must contain at least one uppercase letter');
                    return false;
                }
                if (!/[a-z]/.test(password)) {
                    alert('Password must contain at least one lowercase letter');
                    return false;
                }
                if (!/[0-9]/.test(password)) {
                    alert('Password must contain at least one number');
                    return false;
                }
            }
            
            if (type === 'edit') {
                const newPassword = document.getElementById('edit_new_password').value;
                const confirmPassword = document.querySelector('input[name="confirm_new_password"]').value;
                
                if (newPassword && newPassword !== confirmPassword) {
                    alert('New passwords do not match');
                    return false;
                }
                
                if (newPassword && newPassword.length < 8) {
                    alert('New password must be at least 8 characters long');
                    return false;
                }
            }
            
            return true;
        }
        
        // ========================================
        // Mobile Navigation Toggle
        // ========================================
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.nav-item');
            
            navItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const dropdown = item.querySelector('.dropdown-menu');
                
                if (link && dropdown && link.tagName === 'BUTTON') {
                    link.addEventListener('click', function(e) {
                        if (window.innerWidth <= 768) {
                            e.preventDefault();
                            const isOpen = dropdown.style.display === 'block';
                            
                            document.querySelectorAll('.dropdown-menu').forEach(m => {
                                m.style.display = 'none';
                            });
                            
                            dropdown.style.display = isOpen ? 'none' : 'block';
                        }
                    });
                }
            });
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    if (!e.target.closest('.nav-item')) {
                        document.querySelectorAll('.dropdown-menu').forEach(m => {
                            m.style.display = 'none';
                        });
                    }
                }
            });
        });
    </script>

</body>
</html>