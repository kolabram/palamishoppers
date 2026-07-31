<?php
/**
 * Palami Shoppers Kagoma - Products Management
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
$action = $_GET['action'] ?? 'list';

// FIXED: Get the correct user ID from session
$userId = 0;
if (isset($_SESSION['palami_user_id'])) {
    $userId = $_SESSION['palami_user_id'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
} elseif (isset($_SESSION['id'])) {
    $userId = $_SESSION['id'];
}

// If still 0, try to get from database
if ($userId == 0 && isset($_SESSION['username'])) {
    try {
        $stmt = $db->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if ($user) {
            $userId = $user['user_id'];
            $_SESSION['user_id'] = $userId;
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'toggle_status':
                $productId = (int)$_POST['product_id'];
                $status = (int)$_POST['status'];
                
                $stmt = $db->prepare("UPDATE products SET is_active = ? WHERE product_id = ?");
                $stmt->execute([$status, $productId]);
                
                if ($userId > 0) {
                    $auditLogger->log($userId, 'toggle_product_status', 'products', $productId);
                }
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_product':
                $productId = (int)$_POST['product_id'];
                
                // Check if product has sales
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id = ?");
                $stmt->execute([$productId]);
                $sales = $stmt->fetch();
                
                if ($sales['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete product with sales history']);
                    break;
                }
                
                $stmt = $db->prepare("DELETE FROM products WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                if ($userId > 0) {
                    $auditLogger->log($userId, 'delete_product', 'products', $productId);
                }
                echo json_encode(['success' => true]);
                break;
                
            case 'get_product':
                $productId = (int)$_POST['product_id'];
                $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                
                if ($product) {
                    echo json_encode(['success' => true, 'product' => $product]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Product not found']);
                }
                break;
                
            case 'check_barcode':
                $barcode = Security::sanitizeInput($_POST['barcode']);
                $excludeId = isset($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : 0;
                
                $query = "SELECT product_id FROM products WHERE barcode = ?";
                $params = [$barcode];
                if ($excludeId > 0) {
                    $query .= " AND product_id != ?";
                    $params[] = $excludeId;
                }
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $exists = $stmt->fetch();
                
                echo json_encode(['exists' => $exists !== false]);
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
            if ($_POST['action'] === 'add_product') {
                // Validate inputs
                $barcode = Security::sanitizeInput($_POST['barcode']);
                $productName = Security::sanitizeInput($_POST['product_name']);
                $description = Security::sanitizeInput($_POST['description']);
                $category = Security::sanitizeInput($_POST['category']);
                $unitPrice = (float)$_POST['unit_price'];
                $costPrice = (float)$_POST['cost_price'];
                $currentStock = (int)$_POST['current_stock'];
                $minStockLevel = (int)$_POST['min_stock_level'];
                $maxStockLevel = (int)$_POST['max_stock_level'];
                $supplier = Security::sanitizeInput($_POST['supplier']);
                $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                
                // Validate
                if (empty($barcode)) {
                    $barcode = Security::generateBarcode();
                }
                
                if (empty($productName)) {
                    $error = 'Product name is required';
                } elseif ($unitPrice <= 0) {
                    $error = 'Unit price must be greater than 0';
                } elseif ($currentStock < 0) {
                    $error = 'Stock cannot be negative';
                } else {
                    // Check if barcode exists
                    $stmt = $db->prepare("SELECT product_id FROM products WHERE barcode = ?");
                    $stmt->execute([$barcode]);
                    if ($stmt->fetch()) {
                        $error = 'Barcode already exists. Please scan a different barcode or leave blank to auto-generate.';
                    } else {
                        // Insert product with category_id
                        $stmt = $db->prepare("
                            INSERT INTO products (barcode, product_name, description, category, category_id,
                                unit_price, cost_price, current_stock, min_stock_level, 
                                max_stock_level, supplier, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([
                            $barcode, $productName, $description, $category, $categoryId,
                            $unitPrice, $costPrice, $currentStock, $minStockLevel,
                            $maxStockLevel, $supplier
                        ]);
                        $productId = $db->lastInsertId();
                        
                        // Log inventory transaction
                        if ($userId > 0 && $currentStock > 0) {
                            $stmt = $db->prepare("
                                INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                                    quantity_change, previous_stock, new_stock, notes)
                                VALUES (?, ?, 'purchase', ?, 0, ?, 'Initial stock')
                            ");
                            $stmt->execute([$productId, $userId, $currentStock, $currentStock]);
                        } elseif ($currentStock > 0) {
                            $stmt = $db->prepare("
                                INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                                    quantity_change, previous_stock, new_stock, notes)
                                VALUES (?, 0, 'purchase', ?, 0, ?, 'Initial stock (system)')
                            ");
                            $stmt->execute([$productId, $currentStock, $currentStock]);
                        }
                        
                        if ($userId > 0) {
                            $auditLogger->log($userId, 'create_product', 'products', $productId);
                        }
                        $message = 'Product created successfully! Barcode: ' . $barcode;
                        
                        // Check low stock
                        if ($currentStock <= $minStockLevel) {
                            $stmt = $db->prepare("
                                INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                                VALUES (?, 'low_stock', ?, ?)
                            ");
                            $stmt->execute([$productId, $currentStock, $minStockLevel]);
                        }
                        
                        header('Location: products.php?success=created');
                        exit;
                    }
                }
            } elseif ($_POST['action'] === 'edit_product') {
                $productId = (int)$_POST['product_id'];
                $barcode = Security::sanitizeInput($_POST['barcode']);
                $productName = Security::sanitizeInput($_POST['product_name']);
                $description = Security::sanitizeInput($_POST['description']);
                $category = Security::sanitizeInput($_POST['category']);
                $unitPrice = (float)$_POST['unit_price'];
                $costPrice = (float)$_POST['cost_price'];
                $currentStock = (int)$_POST['current_stock'];
                $minStockLevel = (int)$_POST['min_stock_level'];
                $maxStockLevel = (int)$_POST['max_stock_level'];
                $supplier = Security::sanitizeInput($_POST['supplier']);
                $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                
                if (empty($productName)) {
                    $error = 'Product name is required';
                } elseif ($unitPrice <= 0) {
                    $error = 'Unit price must be greater than 0';
                } else {
                    // Check if barcode exists for another product
                    $stmt = $db->prepare("SELECT product_id FROM products WHERE barcode = ? AND product_id != ?");
                    $stmt->execute([$barcode, $productId]);
                    if ($stmt->fetch()) {
                        $error = 'Barcode already exists. Please scan a different barcode.';
                    } else {
                        // Get old product data
                        $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
                        $stmt->execute([$productId]);
                        $oldProduct = $stmt->fetch();
                        
                        if (!$oldProduct) {
                            $error = 'Product not found';
                        } else {
                            // Update product with category_id
                            $stmt = $db->prepare("
                                UPDATE products 
                                SET barcode = ?, product_name = ?, description = ?, category = ?, category_id = ?,
                                    unit_price = ?, cost_price = ?, current_stock = ?,
                                    min_stock_level = ?, max_stock_level = ?, supplier = ?
                                WHERE product_id = ?
                            ");
                            $stmt->execute([
                                $barcode, $productName, $description, $category, $categoryId,
                                $unitPrice, $costPrice, $currentStock,
                                $minStockLevel, $maxStockLevel, $supplier,
                                $productId
                            ]);
                            
                            // Log inventory change if stock changed
                            if ($currentStock != $oldProduct['current_stock']) {
                                if ($userId > 0) {
                                    $stmt = $db->prepare("
                                        INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                                            quantity_change, previous_stock, new_stock, notes)
                                        VALUES (?, ?, 'adjustment', ?, ?, ?, 'Manual adjustment')
                                    ");
                                    $quantityChange = $currentStock - $oldProduct['current_stock'];
                                    $stmt->execute([
                                        $productId, 
                                        $userId, 
                                        $quantityChange,
                                        $oldProduct['current_stock'], 
                                        $currentStock
                                    ]);
                                } else {
                                    $stmt = $db->prepare("
                                        INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                                            quantity_change, previous_stock, new_stock, notes)
                                        VALUES (?, 0, 'adjustment', ?, ?, ?, 'Manual adjustment (system)')
                                    ");
                                    $quantityChange = $currentStock - $oldProduct['current_stock'];
                                    $stmt->execute([
                                        $productId, 
                                        $quantityChange,
                                        $oldProduct['current_stock'], 
                                        $currentStock
                                    ]);
                                }
                                
                                // Check low stock
                                if ($currentStock <= $minStockLevel) {
                                    $stmt = $db->prepare("
                                        INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                                        VALUES (?, 'low_stock', ?, ?)
                                        ON DUPLICATE KEY UPDATE current_stock = ?, is_resolved = 0
                                    ");
                                    $stmt->execute([$productId, $currentStock, $minStockLevel, $currentStock]);
                                } else {
                                    $stmt = $db->prepare("
                                        UPDATE low_stock_alerts 
                                        SET is_resolved = 1, resolved_at = NOW() 
                                        WHERE product_id = ? AND is_resolved = 0
                                    ");
                                    $stmt->execute([$productId]);
                                }
                            }
                            
                            if ($userId > 0) {
                                $auditLogger->log($userId, 'update_product', 'products', $productId);
                            }
                            $message = 'Product updated successfully!';
                            
                            header('Location: products.php?success=updated');
                            exit;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $message = 'Product created successfully!';
    } elseif ($_GET['success'] === 'updated') {
        $message = 'Product updated successfully!';
    }
}

// Get all products with category names
$products = [];
try {
    $stmt = $db->query("
        SELECT p.*, 
               c.category_name as category_display,
               c.icon_class,
               c.color_code,
               (SELECT COUNT(*) FROM sale_items WHERE product_id = p.product_id) as sales_count,
               CASE 
                   WHEN p.current_stock <= 0 THEN 'out_of_stock'
                   WHEN p.current_stock <= p.min_stock_level THEN 'low_stock'
                   ELSE 'in_stock'
               END as stock_status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        ORDER BY p.product_id DESC
    ");
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load products: ' . $e->getMessage();
}

// Get categories from the categories table (ONLY from categories table)
$categories = [];
try {
    $stmt = $db->query("SELECT category_id, category_name, icon_class, color_code FROM categories WHERE is_active = 1 ORDER BY category_name");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load categories: ' . $e->getMessage();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();

// Get low stock count for badge
$lowStockCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM low_stock_alerts WHERE is_resolved = 0");
    $lowStockCount = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    // Ignore
}

// Get all categories for dropdown (ONLY from categories table - NO legacy categories)
$allCategories = [];
foreach ($categories as $cat) {
    $allCategories[] = [
        'id' => $cat['category_id'],
        'name' => $cat['category_name'],
        'icon' => $cat['icon_class'] ?? 'fa-tag',
        'color' => $cat['color_code'] ?? '#6c757d'
    ];
}
// Sort alphabetically
usort($allCategories, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Products</title>
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
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: white;
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
            max-width: 750px;
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
        
        .form-group .barcode-input-group {
            display: flex;
            gap: 10px;
        }
        
        .form-group .barcode-input-group input {
            flex: 1;
        }
        
        .form-group .barcode-input-group button {
            white-space: nowrap;
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
        
        .product-image {
            width: 50px;
            height: 50px;
            background: #e3f2fd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #1a237e;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-box input {
            flex: 1;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .search-box input:focus {
            border-color: #1a237e;
            outline: none;
        }
        
        .category-select-wrapper {
            position: relative;
        }
        
        .category-select-wrapper select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
            cursor: pointer;
        }
        
        .category-select-wrapper select optgroup {
            font-weight: 600;
            color: #1a237e;
        }
        
        .category-select-wrapper select option {
            font-weight: 400;
            padding: 4px 8px;
        }
        
        .category-select-wrapper select option[disabled] {
            color: #95a5a6;
            font-style: italic;
        }
        
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
            
            .form-group .barcode-input-group {
                flex-direction: column;
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
                    <?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? $_SESSION['full_name'] ?? 'Administrator'); ?>
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
                        <a href="products.php" class="dropdown-item active">
                            <i class="fas fa-list"></i>
                            All Products
                            <span class="badge-nav"><?php echo count($products); ?></span>
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
                        <a href="low-stock.php" class="dropdown-item">
                            <i class="fas fa-exclamation-triangle"></i>
                            Low Stock Alerts
                            <?php if ($lowStockCount > 0): ?>
                                <span class="badge-nav danger"><?php echo $lowStockCount; ?></span>
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
                <h2><i class="fas fa-boxes"></i> Product Management</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / Products
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="products.php?action=add" class="btn btn-success">
                    <i class="fas fa-plus-circle"></i> Add Product
                </a>
                <a href="barcode.php" class="btn btn-barcode">
                    <i class="fas fa-barcode"></i> Generate Barcode
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

        <!-- Search Box -->
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="🔍 Search products by name, barcode, or category..." onkeyup="searchProducts()">
        </div>

        <!-- Mini Stats -->
        <div class="stats-mini">
            <div class="stat-mini">
                <div class="number"><?php echo count($products); ?></div>
                <div class="label">Total Products</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#27ae60;">
                    <?php
                        $inStock = array_filter($products, function($p) { return $p['current_stock'] > $p['min_stock_level']; });
                        echo count($inStock);
                    ?>
                </div>
                <div class="label">In Stock</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#f39c12;">
                    <?php
                        $lowStock = array_filter($products, function($p) { 
                            return $p['current_stock'] <= $p['min_stock_level'] && $p['current_stock'] > 0; 
                        });
                        echo count($lowStock);
                    ?>
                </div>
                <div class="label">Low Stock</div>
            </div>
            <div class="stat-mini">
                <div class="number" style="color:#e74c3c;">
                    <?php
                        $outOfStock = array_filter($products, function($p) { return $p['current_stock'] <= 0; });
                        echo count($outOfStock);
                    ?>
                </div>
                <div class="label">Out of Stock</div>
            </div>
        </div>

        <!-- Products Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Barcode</th>
                            <th>Category</th>
                            <th>Price (UGX)</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Sales</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productTableBody">
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-box-open" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No products found. Click "Add Product" to create one.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                            <tr class="product-row" data-name="<?php echo strtolower($product['product_name']); ?>" 
                                data-barcode="<?php echo $product['barcode']; ?>"
                                data-category="<?php echo strtolower($product['category'] ?? ''); ?>">
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div class="product-image">
                                            <?php if (isset($product['icon_class'])): ?>
                                                <i class="fas <?php echo $product['icon_class']; ?>" style="color:<?php echo $product['color_code'] ?? '#1a237e'; ?>;"></i>
                                            <?php else: ?>
                                                <i class="fas fa-box"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                            <div style="font-size:12px;color:#95a5a6;"><?php echo htmlspecialchars($product['description'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;cursor:pointer;" 
                                          onclick="copyBarcode('<?php echo $product['barcode']; ?>')" 
                                          title="Click to copy barcode">
                                        <i class="fas fa-copy" style="color:#1a237e;font-size:10px;"></i>
                                        <?php echo htmlspecialchars($product['barcode']); ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if (isset($product['category_display']) && $product['category_display']): ?>
                                        <span class="category-badge" style="background:<?php echo $product['color_code'] ?? '#6c757d'; ?>;">
                                            <i class="fas <?php echo $product['icon_class'] ?? 'fa-tag'; ?>"></i>
                                            <?php echo htmlspecialchars($product['category_display']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>UGX <?php echo number_format($product['unit_price'], 0); ?></strong>
                                    <?php if ($product['cost_price']): ?>
                                        <div style="font-size:11px;color:#95a5a6;">Cost: UGX <?php echo number_format($product['cost_price'], 0); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <strong style="color: <?php echo $product['current_stock'] <= 0 ? '#e74c3c' : ($product['current_stock'] <= $product['min_stock_level'] ? '#f39c12' : '#27ae60'); ?>;">
                                            <?php echo $product['current_stock']; ?>
                                        </strong>
                                        <div style="font-size:11px;color:#95a5a6;">Min: <?php echo $product['min_stock_level']; ?></div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $statusClass = $product['stock_status'] == 'in_stock' ? 'badge-success' : 
                                                      ($product['stock_status'] == 'low_stock' ? 'badge-warning' : 'badge-danger');
                                        $statusText = $product['stock_status'] == 'in_stock' ? '✅ In Stock' : 
                                                     ($product['stock_status'] == 'low_stock' ? '⚠️ Low Stock' : '🚫 Out of Stock');
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td><?php echo $product['sales_count'] ?? 0; ?></td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <button class="btn btn-warning btn-sm" onclick="editProduct(<?php echo $product['product_id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm <?php echo $product['is_active'] ? 'btn-danger' : 'btn-success'; ?>" 
                                                onclick="toggleProduct(<?php echo $product['product_id']; ?>, <?php echo $product['is_active'] ? 0 : 1; ?>)">
                                            <i class="fas <?php echo $product['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </button>
                                        <?php if (($product['sales_count'] ?? 0) == 0): ?>
                                            <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?php echo $product['product_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-barcode btn-sm" onclick="printBarcode('<?php echo $product['barcode']; ?>', '<?php echo htmlspecialchars($product['product_name']); ?>', <?php echo $product['unit_price']; ?>)">
                                            <i class="fas fa-print"></i>
                                        </button>
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
    ADD PRODUCT MODAL
    ========================================== -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Product</h3>
                <button class="modal-close" onclick="closeModal('addProductModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateProductForm()">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_product">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name <span class="required">*</span></label>
                        <input type="text" name="product_name" id="add_product_name" required placeholder="Enter product name">
                    </div>
                    <div class="form-group">
                        <label>Barcode</label>
                        <div class="barcode-input-group">
                            <input type="text" name="barcode" id="add_barcode" placeholder="Scan or enter barcode" 
                                   onchange="checkBarcode(this.value, 0)">
                            <button type="button" class="btn btn-barcode" onclick="generateBarcode()">
                                <i class="fas fa-sync"></i> Generate
                            </button>
                        </div>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i> 
                            Leave blank to auto-generate, or scan existing product barcode
                        </div>
                        <div id="barcodeStatus" style="margin-top:5px;font-size:12px;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Enter product description"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group category-select-wrapper">
                        <label>Category <span class="required">*</span></label>
                        <select name="category_id" id="add_category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?php echo $cat['id'] ?? ''; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">
                            <i class="fas fa-info-circle"></i> 
                            Select a category from the list or <a href="categories.php" target="_blank">manage categories</a>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Supplier</label>
                        <input type="text" name="supplier" placeholder="Enter supplier name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit Price (UGX) <span class="required">*</span></label>
                        <input type="number" name="unit_price" id="add_unit_price" required step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Cost Price (UGX)</label>
                        <input type="number" name="cost_price" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Current Stock <span class="required">*</span></label>
                        <input type="number" name="current_stock" id="add_current_stock" required min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Min Stock Level</label>
                        <input type="number" name="min_stock_level" value="10" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Stock Level</label>
                        <input type="number" name="max_stock_level" value="100" min="0">
                    </div>
                    <div class="form-group">
                        <label>Store Location</label>
                        <input type="text" name="store_location" value="Main Store" placeholder="Store location">
                    </div>
                </div>
                
                <!-- Hidden field for category name (for backward compatibility) -->
                <input type="hidden" name="category" id="add_category_hidden" value="">
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Add Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
    EDIT PRODUCT MODAL
    ========================================== -->
    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Product</h3>
                <button class="modal-close" onclick="closeModal('editProductModal')">&times;</button>
            </div>
            <form method="POST" id="editProductForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name <span class="required">*</span></label>
                        <input type="text" name="product_name" id="edit_product_name" required>
                    </div>
                    <div class="form-group">
                        <label>Barcode</label>
                        <div class="barcode-input-group">
                            <input type="text" name="barcode" id="edit_barcode" 
                                   onchange="checkBarcode(this.value, document.getElementById('edit_product_id').value)">
                            <button type="button" class="btn btn-barcode" onclick="generateEditBarcode()">
                                <i class="fas fa-sync"></i> Generate
                            </button>
                        </div>
                        <div id="editBarcodeStatus" style="margin-top:5px;font-size:12px;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group category-select-wrapper">
                        <label>Category <span class="required">*</span></label>
                        <select name="category_id" id="edit_category_id" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?php echo $cat['id'] ?? ''; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Supplier</label>
                        <input type="text" name="supplier" id="edit_supplier" placeholder="Enter supplier name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit Price (UGX) <span class="required">*</span></label>
                        <input type="number" name="unit_price" id="edit_unit_price" required step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label>Cost Price (UGX)</label>
                        <input type="number" name="cost_price" id="edit_cost_price" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Current Stock <span class="required">*</span></label>
                        <input type="number" name="current_stock" id="edit_current_stock" required min="0">
                    </div>
                    <div class="form-group">
                        <label>Min Stock Level</label>
                        <input type="number" name="min_stock_level" id="edit_min_stock" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Stock Level</label>
                        <input type="number" name="max_stock_level" id="edit_max_stock" min="0">
                    </div>
                    <div class="form-group">
                        <label>Store Location</label>
                        <input type="text" name="store_location" id="edit_store_location" placeholder="Store location">
                    </div>
                </div>
                
                <!-- Hidden field for category name (for backward compatibility) -->
                <input type="hidden" name="category" id="edit_category_hidden" value="">
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('editProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editSubmitBtn">
                        <i class="fas fa-save"></i> Update Product
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
        // Barcode Functions
        // ========================================
        function generateBarcode() {
            const timestamp = Date.now().toString();
            const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
            const barcode = 'PSK' + timestamp.slice(-8) + random;
            document.getElementById('add_barcode').value = barcode;
            document.getElementById('barcodeStatus').innerHTML = '<span style="color:#27ae60;">✅ Barcode generated: ' + barcode + '</span>';
        }
        
        function generateEditBarcode() {
            const timestamp = Date.now().toString();
            const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
            const barcode = 'PSK' + timestamp.slice(-8) + random;
            document.getElementById('edit_barcode').value = barcode;
            document.getElementById('editBarcodeStatus').innerHTML = '<span style="color:#27ae60;">✅ Barcode generated: ' + barcode + '</span>';
        }
        
        function checkBarcode(barcode, excludeId) {
            if (!barcode || barcode.length < 4) {
                const statusEl = document.getElementById('barcodeStatus') || document.getElementById('editBarcodeStatus');
                if (statusEl) statusEl.innerHTML = '';
                return;
            }
            
            const statusEl = document.getElementById('barcodeStatus') || document.getElementById('editBarcodeStatus');
            if (statusEl) {
                statusEl.innerHTML = '<span style="color:#95a5a6;">⏳ Checking barcode...</span>';
            }
            
            fetch('products.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_action=check_barcode&barcode=' + encodeURIComponent(barcode) + '&exclude_id=' + excludeId
            })
            .then(response => response.json())
            .then(data => {
                if (statusEl) {
                    if (data.exists) {
                        statusEl.innerHTML = '<span style="color:#e74c3c;">⚠️ This barcode already exists in the system</span>';
                    } else {
                        statusEl.innerHTML = '<span style="color:#27ae60;">✅ Barcode is available</span>';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#e74c3c;">❌ Error checking barcode</span>';
                }
            });
        }
        
        // ========================================
        // Edit Product Function
        // ========================================
        function editProduct(productId) {
            // Show loading state
            const btn = document.querySelector('#editProductModal .btn-primary');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            btn.disabled = true;
            
            // Fetch product data via AJAX
            fetch('products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=get_product&product_id=' + productId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    
                    // Populate form fields
                    document.getElementById('edit_product_id').value = p.product_id;
                    document.getElementById('edit_product_name').value = p.product_name;
                    document.getElementById('edit_barcode').value = p.barcode;
                    document.getElementById('edit_description').value = p.description || '';
                    document.getElementById('edit_supplier').value = p.supplier || '';
                    document.getElementById('edit_unit_price').value = p.unit_price;
                    document.getElementById('edit_cost_price').value = p.cost_price || '';
                    document.getElementById('edit_current_stock').value = p.current_stock;
                    document.getElementById('edit_min_stock').value = p.min_stock_level;
                    document.getElementById('edit_max_stock').value = p.max_stock_level;
                    document.getElementById('edit_store_location').value = p.store_location || 'Main Store';
                    
                    // Set category
                    const categorySelect = document.getElementById('edit_category_id');
                    if (p.category_id) {
                        categorySelect.value = p.category_id;
                    } else {
                        // Try to match by name
                        const categoryName = p.category || '';
                        for (let option of categorySelect.options) {
                            if (option.text.toLowerCase() === categoryName.toLowerCase()) {
                                option.selected = true;
                                break;
                            }
                        }
                    }
                    
                    document.getElementById('editBarcodeStatus').innerHTML = '';
                    
                    // Open the modal
                    openModal('editProductModal');
                    
                    // Reset button
                    btn.innerHTML = '<i class="fas fa-save"></i> Update Product';
                    btn.disabled = false;
                } else {
                    alert('Failed to load product data: ' + (data.message || 'Unknown error'));
                    btn.innerHTML = '<i class="fas fa-save"></i> Update Product';
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading product data');
                btn.innerHTML = '<i class="fas fa-save"></i> Update Product';
                btn.disabled = false;
            });
        }
        
        // ========================================
        // Toggle Product Status
        // ========================================
        function toggleProduct(productId, status) {
            const action = status ? 'activate' : 'deactivate';
            if (!confirm(`Are you sure you want to ${action} this product?`)) return;
            
            fetch('products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=toggle_status&product_id=' + productId + '&status=' + status
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update product status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating product status');
            });
        }
        
        // ========================================
        // Delete Product
        // ========================================
        function deleteProduct(productId) {
            if (!confirm('⚠️ Are you sure you want to permanently delete this product?\nThis action cannot be undone!')) return;
            if (!confirm('Are you absolutely sure?')) return;
            
            fetch('products.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=delete_product&product_id=' + productId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to delete product: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting product');
            });
        }
        
        // ========================================
        // Search Products
        // ========================================
        function searchProducts() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('.product-row');
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const barcode = row.getAttribute('data-barcode') || '';
                const category = row.getAttribute('data-category') || '';
                
                if (name.includes(filter) || barcode.includes(filter) || category.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // ========================================
        // Form Validation
        // ========================================
        function validateProductForm() {
            const name = document.getElementById('add_product_name').value.trim();
            const price = parseFloat(document.getElementById('add_unit_price').value);
            const stock = parseInt(document.getElementById('add_current_stock').value);
            const barcode = document.getElementById('add_barcode').value.trim();
            const categoryId = document.getElementById('add_category_id').value;
            
            if (!name) {
                alert('Product name is required');
                document.getElementById('add_product_name').focus();
                return false;
            }
            
            if (isNaN(price) || price <= 0) {
                alert('Unit price must be greater than 0');
                document.getElementById('add_unit_price').focus();
                return false;
            }
            
            if (isNaN(stock) || stock < 0) {
                alert('Stock cannot be negative');
                document.getElementById('add_current_stock').focus();
                return false;
            }
            
            if (!categoryId) {
                alert('Please select a category');
                document.getElementById('add_category_id').focus();
                return false;
            }
            
            if (barcode && barcode.length < 4) {
                alert('Barcode must be at least 4 characters or leave blank to auto-generate');
                document.getElementById('add_barcode').focus();
                return false;
            }
            
            // Set category name from select
            const categorySelect = document.getElementById('add_category_id');
            const categoryName = categorySelect.options[categorySelect.selectedIndex]?.text || '';
            document.getElementById('add_category_hidden').value = categoryName;
            
            return true;
        }
        
        // ========================================
        // Copy Barcode
        // ========================================
        function copyBarcode(barcode) {
            navigator.clipboard.writeText(barcode).then(function() {
                showNotification('Barcode copied: ' + barcode);
            }).catch(function() {
                // Fallback for older browsers
                const input = document.createElement('input');
                input.value = barcode;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showNotification('Barcode copied: ' + barcode);
            });
        }
        
        function showNotification(message) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed; bottom: 20px; right: 20px; 
                background: #1a237e; color: white; padding: 12px 24px; 
                border-radius: 8px; font-weight: 600; z-index: 9999;
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
                animation: fadeIn 0.3s ease;
            `;
            notification.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.5s';
                setTimeout(() => notification.remove(), 500);
            }, 2000);
        }
        
        // ========================================
        // Print Barcode
        // ========================================
        function printBarcode(barcode, productName, price) {
            const printWindow = window.open('', '_blank', 'width=400,height=300');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Barcode - ${productName}</title>
                    <style>
                        body { 
                            font-family: Arial, sans-serif; 
                            display: flex; 
                            justify-content: center; 
                            align-items: center; 
                            height: 100vh; 
                            margin: 0;
                            background: white;
                        }
                        .barcode-container {
                            text-align: center;
                            padding: 20px;
                            border: 2px dashed #1a237e;
                            border-radius: 8px;
                        }
                        .barcode-text {
                            font-size: 28px;
                            font-weight: bold;
                            letter-spacing: 2px;
                            font-family: 'Courier New', monospace;
                            margin: 10px 0;
                        }
                        .product-name {
                            font-size: 14px;
                            color: #333;
                            margin: 5px 0;
                        }
                        .price {
                            font-size: 18px;
                            color: #1a237e;
                            font-weight: bold;
                        }
                        .store-name {
                            font-size: 10px;
                            color: #95a5a6;
                            margin-top: 10px;
                        }
                        .barcode-image {
                            font-size: 48px;
                            color: #1a237e;
                            margin: 10px 0;
                        }
                        @media print {
                            body { margin: 0; padding: 0; }
                            .barcode-container { border: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="barcode-container">
                        <div class="store-name">Palami Shoppers Kagoma</div>
                        <div class="barcode-image">
                            <i class="fas fa-barcode"></i>
                        </div>
                        <div class="product-name">${productName}</div>
                        <div class="barcode-text">${barcode}</div>
                        <div class="price">UGX ${parseFloat(price).toLocaleString()}</div>
                        <div class="store-name">www.palamishoppers.com</div>
                        <p style="font-size:10px;color:#95a5a6;margin-top:10px;">Scan me!</p>
                    </div>
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() { window.close(); }, 1000);
                        }
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        // ========================================
        // Enter key for barcode scanning
        // ========================================
        document.addEventListener('DOMContentLoaded', function() {
            // Open add product modal if action=add
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'add') {
                document.getElementById('add_product_name').value = '';
                document.getElementById('add_barcode').value = '';
                document.getElementById('add_category_id').value = '';
                document.getElementById('add_unit_price').value = '';
                document.getElementById('add_current_stock').value = '0';
                document.getElementById('barcodeStatus').innerHTML = '';
                openModal('addProductModal');
                setTimeout(() => {
                    document.getElementById('add_product_name').focus();
                }, 300);
            }
            
            // Handle Enter key on barcode input
            const barcodeInput = document.getElementById('add_barcode');
            if (barcodeInput) {
                barcodeInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const barcode = this.value.trim();
                        if (barcode) {
                            checkBarcode(barcode, 0);
                        }
                    }
                });
            }
            
            // Update category hidden fields when select changes
            const addCategorySelect = document.getElementById('add_category_id');
            if (addCategorySelect) {
                addCategorySelect.addEventListener('change', function() {
                    const name = this.options[this.selectedIndex]?.text || '';
                    document.getElementById('add_category_hidden').value = name;
                });
            }
            
            const editCategorySelect = document.getElementById('edit_category_id');
            if (editCategorySelect) {
                editCategorySelect.addEventListener('change', function() {
                    const name = this.options[this.selectedIndex]?.text || '';
                    document.getElementById('edit_category_hidden').value = name;
                });
            }
        });
        
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