<?php
/**
 * Palami Shoppers Kagoma - Low Stock Alerts
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();
$productManager = new ProductManager();

$message = '';
$error = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'resolve_alert':
                $alertId = (int)$_POST['alert_id'];
                
                $stmt = $db->prepare("
                    UPDATE low_stock_alerts 
                    SET is_resolved = 1, resolved_at = NOW() 
                    WHERE alert_id = ?
                ");
                $stmt->execute([$alertId]);
                
                $auditLogger->log($_SESSION['palami_user_id'], 'resolve_low_stock_alert', 'low_stock_alerts', $alertId);
                echo json_encode(['success' => true]);
                break;
                
            case 'restock_product':
                $productId = (int)$_POST['product_id'];
                $quantity = (int)$_POST['quantity'];
                $notes = Security::sanitizeInput($_POST['notes'] ?? 'Restock from low stock alert');
                
                // Get current stock
                $stmt = $db->prepare("SELECT current_stock, min_stock_level FROM products WHERE product_id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                
                if (!$product) {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                    break;
                }
                
                $newStock = $product['current_stock'] + $quantity;
                
                // Update stock
                $stmt = $db->prepare("UPDATE products SET current_stock = ? WHERE product_id = ?");
                $stmt->execute([$newStock, $productId]);
                
                // Log inventory transaction
                $stmt = $db->prepare("
                    INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                        quantity_change, previous_stock, new_stock, notes)
                    VALUES (?, ?, 'purchase', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $productId,
                    $_SESSION['palami_user_id'],
                    $quantity,
                    $product['current_stock'],
                    $newStock,
                    $notes
                ]);
                
                // Resolve alert
                $stmt = $db->prepare("
                    UPDATE low_stock_alerts 
                    SET is_resolved = 1, resolved_at = NOW() 
                    WHERE product_id = ? AND is_resolved = 0
                ");
                $stmt->execute([$productId]);
                
                $auditLogger->log($_SESSION['palami_user_id'], 'restock_product', 'products', $productId);
                echo json_encode(['success' => true, 'new_stock' => $newStock]);
                break;
                
            case 'get_product_details':
                $productId = (int)$_POST['product_id'];
                $stmt = $db->prepare("
                    SELECT p.*, l.alert_id, l.alert_type, l.current_stock as alert_stock, 
                           l.threshold, l.created_at as alert_created
                    FROM products p
                    JOIN low_stock_alerts l ON p.product_id = l.product_id
                    WHERE p.product_id = ? AND l.is_resolved = 0
                ");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                
                if ($product) {
                    echo json_encode(['success' => true, 'product' => $product]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found or no active alert']);
                }
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle bulk action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!SessionManager::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['bulk_action'];
            $alertIds = isset($_POST['alert_ids']) ? $_POST['alert_ids'] : [];
            
            if (empty($alertIds)) {
                $error = 'Please select at least one alert.';
            } else {
                $ids = implode(',', array_map('intval', $alertIds));
                
                if ($action === 'resolve') {
                    $stmt = $db->prepare("
                        UPDATE low_stock_alerts 
                        SET is_resolved = 1, resolved_at = NOW() 
                        WHERE alert_id IN ($ids)
                    ");
                    $stmt->execute();
                    $message = 'Selected alerts resolved successfully!';
                    $auditLogger->log($_SESSION['palami_user_id'], 'bulk_resolve_alerts', 'low_stock_alerts', null);
                } elseif ($action === 'delete') {
                    $stmt = $db->prepare("DELETE FROM low_stock_alerts WHERE alert_id IN ($ids)");
                    $stmt->execute();
                    $message = 'Selected alerts deleted successfully!';
                    $auditLogger->log($_SESSION['palami_user_id'], 'bulk_delete_alerts', 'low_stock_alerts', null);
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get all low stock alerts
$alerts = [];
$stats = [
    'total' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
    'restock_needed' => 0
];

try {
    $stmt = $db->prepare("
        SELECT l.*, p.product_name, p.barcode, p.unit_price, p.min_stock_level,
               p.category, p.supplier, p.product_id
        FROM low_stock_alerts l
        JOIN products p ON l.product_id = p.product_id
        WHERE l.is_resolved = 0
        ORDER BY 
            CASE l.alert_type
                WHEN 'out_of_stock' THEN 1
                WHEN 'low_stock' THEN 2
                ELSE 3
            END,
            l.created_at DESC
    ");
    $stmt->execute();
    $alerts = $stmt->fetchAll();
    
    // Calculate stats
    $stats['total'] = count($alerts);
    foreach ($alerts as $alert) {
        if ($alert['alert_type'] === 'out_of_stock') {
            $stats['out_of_stock']++;
        } elseif ($alert['alert_type'] === 'low_stock') {
            $stats['low_stock']++;
        } else {
            $stats['restock_needed']++;
        }
    }
} catch (Exception $e) {
    $error = 'Failed to load alerts: ' . $e->getMessage();
}

// Get resolved alerts for history
$resolvedAlerts = [];
try {
    $stmt = $db->prepare("
        SELECT l.*, p.product_name, p.barcode
        FROM low_stock_alerts l
        JOIN products p ON l.product_id = p.product_id
        WHERE l.is_resolved = 1
        ORDER BY l.resolved_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $resolvedAlerts = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Low Stock Alerts</title>
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
        
        .btn-barcode {
            background: #2196F3;
            color: white;
        }
        
        .btn-barcode:hover {
            background: #1976D2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        
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
        
        table tr td:first-child {
            padding-left: 18px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-primary {
            background: #cce5ff;
            color: #004085;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 4px solid #1a237e;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card.danger {
            border-left-color: #e74c3c;
        }
        
        .stat-card.warning {
            border-left-color: #f39c12;
        }
        
        .stat-card.success {
            border-left-color: #27ae60;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: #1a237e;
        }
        
        .stat-card .number.danger {
            color: #e74c3c;
        }
        
        .stat-card .number.warning {
            color: #f39c12;
        }
        
        .stat-card .number.success {
            color: #27ae60;
        }
        
        .stat-card .label {
            color: #7f8c8d;
            font-size: 13px;
            margin-top: 5px;
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
        
        .form-group .product-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .form-group .product-info .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        
        .form-group .product-info .info-row:last-child {
            border-bottom: none;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        /* Action Bar */
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .action-bar select {
            padding: 8px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .action-bar select:focus {
            border-color: #1a237e;
            outline: none;
        }
        
        .checkbox-column {
            width: 40px;
            text-align: center;
        }
        
        .checkbox-column input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #1a237e;
        }
        
        /* Status Indicators */
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-dot.danger {
            background: #e74c3c;
            animation: pulse-dot 1.5s infinite;
        }
        
        .status-dot.warning {
            background: #f39c12;
            animation: pulse-dot 2s infinite;
        }
        
        .status-dot.success {
            background: #27ae60;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 64px;
            display: block;
            margin-bottom: 20px;
            color: #bbdefb;
        }
        
        .empty-state h3 {
            color: #1a237e;
            margin-bottom: 10px;
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
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .modal-content {
                padding: 20px;
                width: 95%;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
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
                    <?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? 'Administrator'); ?>
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
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="users.php" class="nav-link">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                </a>
            </li>

            <li class="nav-item">
                <button class="nav-link active">
                    <i class="fas fa-boxes"></i>
                    <span>Products</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="products.php" class="dropdown-item">
                            <i class="fas fa-list"></i>
                            All Products
                        </a>
                    </li>
                    <li>
                        <a href="products.php?action=add" class="dropdown-item">
                            <i class="fas fa-plus-circle"></i>
                            Add Product
                        </a>
                    </li>
                    <li>
                        <a href="categories.php" class="dropdown-item">
                            <i class="fas fa-tags"></i>
                            Categories
                        </a>
                    </li>
                    <li>
                        <a href="barcode.php" class="dropdown-item">
                            <i class="fas fa-barcode"></i>
                            Generate Barcode
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a href="low-stock.php" class="dropdown-item active">
                            <i class="fas fa-exclamation-triangle"></i>
                            Low Stock Alerts
                            <?php if ($stats['total'] > 0): ?>
                                <span class="badge-nav danger"><?php echo $stats['total']; ?></span>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
                        </a>
                    </li>
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
                </ul>
            </li>

            <li class="nav-item">
                <a href="audit.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Logs</span>
                </a>
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
                    <li><a href="suppliers.php" class="dropdown-item"><i class="fas fa-truck"></i> Suppliers</a></li>
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
                <h2><i class="fas fa-exclamation-triangle" style="color:#ffd700;"></i> Low Stock Alerts</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / <a href="products.php">Products</a> / Low Stock Alerts
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="products.php" class="btn btn-primary">
                    <i class="fas fa-boxes"></i> View All Products
                </a>
                <a href="products.php?action=add" class="btn btn-success">
                    <i class="fas fa-plus-circle"></i> Add Product
                </a>
            </div>
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

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $stats['total'] > 0 ? 'danger' : 'success'; ?>">
                <div class="number <?php echo $stats['total'] > 0 ? 'danger' : 'success'; ?>">
                    <?php echo $stats['total']; ?>
                </div>
                <div class="label">Total Alerts</div>
            </div>
            <div class="stat-card danger">
                <div class="number danger"><?php echo $stats['out_of_stock']; ?></div>
                <div class="label">
                    <i class="fas fa-times-circle" style="color:#e74c3c;"></i> Out of Stock
                </div>
            </div>
            <div class="stat-card warning">
                <div class="number warning"><?php echo $stats['low_stock']; ?></div>
                <div class="label">
                    <i class="fas fa-exclamation-circle" style="color:#f39c12;"></i> Low Stock
                </div>
            </div>
            <div class="stat-card">
                <div class="number" style="color:#3498db;"><?php echo $stats['restock_needed']; ?></div>
                <div class="label">
                    <i class="fas fa-truck" style="color:#3498db;"></i> Restock Needed
                </div>
            </div>
        </div>

        <!-- Active Alerts -->
        <?php if (empty($alerts)): ?>
            <div class="table-container">
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color:#27ae60;"></i>
                    <h3>All Products are Well Stocked! 🎉</h3>
                    <p>No low stock alerts at this time. Your inventory is in good shape.</p>
                    <br>
                    <a href="products.php" class="btn btn-primary">
                        <i class="fas fa-boxes"></i> View Products
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="table-container">
                <div class="action-bar">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                        <label for="selectAll" style="font-size:13px;color:#555;">Select All</label>
                    </div>
                    <select id="bulkAction">
                        <option value="">Bulk Actions</option>
                        <option value="resolve">Resolve Alerts</option>
                        <option value="delete">Delete Alerts</option>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="executeBulkAction()">
                        <i class="fas fa-check"></i> Apply
                    </button>
                    <span style="color:#95a5a6;font-size:13px;margin-left:auto;">
                        Showing <?php echo count($alerts); ?> alerts
                    </span>
                </div>

                <div class="table-responsive">
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="bulk_action" id="bulkActionInput">
                        <input type="hidden" name="alert_ids" id="bulkAlertIds">
                        
                        <table>
                            <thead>
                                <tr>
                                    <th class="checkbox-column">
                                        <input type="checkbox" id="selectAllTable" onchange="toggleSelectAllTable()">
                                    </th>
                                    <th>Product</th>
                                    <th>Barcode</th>
                                    <th>Current Stock</th>
                                    <th>Threshold</th>
                                    <th>Alert Type</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alerts as $alert): ?>
                                <tr>
                                    <td class="checkbox-column">
                                        <input type="checkbox" class="alert-checkbox" name="alert_ids[]" value="<?php echo $alert['alert_id']; ?>">
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:#1a237e;">
                                            <?php echo htmlspecialchars($alert['product_name']); ?>
                                        </div>
                                        <div style="font-size:12px;color:#95a5a6;">
                                            <?php echo htmlspecialchars($alert['category'] ?? 'Uncategorized'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;">
                                            <?php echo htmlspecialchars($alert['barcode']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <strong style="color: <?php echo $alert['alert_type'] == 'out_of_stock' ? '#e74c3c' : '#f39c12'; ?>;">
                                            <?php echo $alert['current_stock']; ?>
                                        </strong>
                                    </td>
                                    <td><?php echo $alert['threshold']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $alert['alert_type'] == 'out_of_stock' ? 'badge-danger' : ($alert['alert_type'] == 'low_stock' ? 'badge-warning' : 'badge-info'); ?>">
                                            <?php
                                                $icons = [
                                                    'out_of_stock' => '🚫',
                                                    'low_stock' => '⚠️',
                                                    'restock_needed' => '📦'
                                                ];
                                                $labels = [
                                                    'out_of_stock' => 'Out of Stock',
                                                    'low_stock' => 'Low Stock',
                                                    'restock_needed' => 'Restock Needed'
                                                ];
                                                echo ($icons[$alert['alert_type']] ?? '⚠️') . ' ' . ($labels[$alert['alert_type']] ?? $alert['alert_type']);
                                            ?>
                                        </span>
                                    </td>
                                    <td style="font-size:13px;color:#7f8c8d;">
                                        <?php echo date('d M Y H:i', strtotime($alert['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                            <button class="btn btn-success btn-sm" onclick="openRestockModal(<?php echo $alert['product_id']; ?>)">
                                                <i class="fas fa-arrow-up"></i> Restock
                                            </button>
                                            <button class="btn btn-primary btn-sm" onclick="resolveAlert(<?php echo $alert['alert_id']; ?>)">
                                                <i class="fas fa-check"></i> Resolve
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Resolved Alerts History -->
        <?php if (!empty($resolvedAlerts)): ?>
        <div style="margin-top:30px;">
            <h3 style="color:#1a237e;margin-bottom:15px;">
                <i class="fas fa-history"></i> Recently Resolved Alerts
            </h3>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Barcode</th>
                                <th>Alert Type</th>
                                <th>Resolved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resolvedAlerts as $alert): ?>
                            <tr>
                                <td>
                                    <span style="font-weight:600;color:#1a237e;">
                                        <?php echo htmlspecialchars($alert['product_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;">
                                        <?php echo htmlspecialchars($alert['barcode']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge badge-success">
                                        <?php echo ucfirst(str_replace('_', ' ', $alert['alert_type'])); ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo date('d M Y H:i', strtotime($alert['resolved_at'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ==========================================
    RESTOCK MODAL
    ========================================== -->
    <div id="restockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-arrow-up" style="color:#27ae60;"></i> Restock Product</h3>
                <button class="modal-close" onclick="closeModal('restockModal')">&times;</button>
            </div>
            <div id="restockProductInfo">
                <div class="form-group">
                    <div class="product-info" id="productInfoDisplay">
                        <div class="info-row">
                            <span><strong>Product:</strong></span>
                            <span id="restockProductName">Loading...</span>
                        </div>
                        <div class="info-row">
                            <span><strong>Current Stock:</strong></span>
                            <span id="restockCurrentStock">-</span>
                        </div>
                        <div class="info-row">
                            <span><strong>Min Stock Level:</strong></span>
                            <span id="restockMinStock">-</span>
                        </div>
                        <div class="info-row">
                            <span><strong>Alert Type:</strong></span>
                            <span id="restockAlertType">-</span>
                        </div>
                    </div>
                </div>
            </div>
            <form id="restockForm" onsubmit="return submitRestock()">
                <input type="hidden" name="product_id" id="restockProductId">
                <input type="hidden" name="ajax_action" value="restock_product">
                
                <div class="form-group">
                    <label>Quantity to Add <span class="required">*</span></label>
                    <input type="number" name="quantity" id="restockQuantity" required min="1" value="10">
                    <div class="help-text">Enter the quantity to add to current stock</div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="restockNotes" rows="2" placeholder="Restock notes (optional)"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('restockModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-arrow-up"></i> Restock Product
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
        // Select All Functions
        // ========================================
        function toggleSelectAllTable() {
            const checked = document.getElementById('selectAllTable').checked;
            document.querySelectorAll('.alert-checkbox').forEach(cb => {
                cb.checked = checked;
            });
        }
        
        function toggleSelectAll() {
            const checked = document.getElementById('selectAll').checked;
            document.querySelectorAll('.alert-checkbox').forEach(cb => {
                cb.checked = checked;
            });
            document.getElementById('selectAllTable').checked = checked;
        }
        
        // ========================================
        // Bulk Action
        // ========================================
        function executeBulkAction() {
            const action = document.getElementById('bulkAction').value;
            if (!action) {
                alert('Please select an action');
                return;
            }
            
            const selected = document.querySelectorAll('.alert-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one alert');
                return;
            }
            
            if (!confirm(`Are you sure you want to ${action} ${selected.length} alert(s)?`)) {
                return;
            }
            
            const ids = Array.from(selected).map(cb => cb.value);
            document.getElementById('bulkActionInput').value = action;
            document.getElementById('bulkAlertIds').value = ids.join(',');
            document.getElementById('bulkForm').submit();
        }
        
        // ========================================
        // Resolve Alert
        // ========================================
        function resolveAlert(alertId) {
            if (!confirm('Are you sure you want to resolve this alert?')) return;
            
            fetch('low-stock.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=resolve_alert&alert_id=' + alertId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to resolve alert');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error resolving alert');
            });
        }
        
        // ========================================
        // Restock Functions
        // ========================================
        function openRestockModal(productId) {
            // Fetch product details
            fetch('low-stock.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=get_product_details&product_id=' + productId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    document.getElementById('restockProductId').value = p.product_id;
                    document.getElementById('restockProductName').textContent = p.product_name;
                    document.getElementById('restockCurrentStock').textContent = p.current_stock;
                    document.getElementById('restockMinStock').textContent = p.min_stock_level;
                    
                    const alertLabels = {
                        'out_of_stock': '🚫 Out of Stock',
                        'low_stock': '⚠️ Low Stock',
                        'restock_needed': '📦 Restock Needed'
                    };
                    document.getElementById('restockAlertType').textContent = alertLabels[p.alert_type] || p.alert_type;
                    
                    // Set recommended quantity
                    const recommended = p.min_stock_level * 2 - p.current_stock;
                    document.getElementById('restockQuantity').value = Math.max(recommended, 10);
                    document.getElementById('restockNotes').value = 'Restocking from low stock alert. Current stock: ' + p.current_stock;
                    
                    openModal('restockModal');
                } else {
                    alert('Failed to load product details: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading product details');
            });
        }
        
        function submitRestock() {
            const productId = document.getElementById('restockProductId').value;
            const quantity = parseInt(document.getElementById('restockQuantity').value);
            const notes = document.getElementById('restockNotes').value;
            
            if (!quantity || quantity < 1) {
                alert('Please enter a valid quantity');
                return false;
            }
            
            if (!confirm(`Are you sure you want to add ${quantity} units to this product?`)) {
                return false;
            }
            
            const formData = new FormData();
            formData.append('ajax_action', 'restock_product');
            formData.append('product_id', productId);
            formData.append('quantity', quantity);
            formData.append('notes', notes);
            
            fetch('low-stock.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Product restocked successfully!\nNew stock: ' + data.new_stock);
                    location.reload();
                } else {
                    alert('Failed to restock: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error restocking product');
            });
            
            return false;
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
            
            // Auto-refresh alerts every 60 seconds
            setTimeout(function() {
                location.reload();
            }, 60000);
        });
    </script>

</body>
</html>