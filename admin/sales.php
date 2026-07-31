<?php
/**
 * Palami Shoppers Kagoma - Sales Management
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();
$salesManager = new SalesManager();

$message = '';
$error = '';
$action = $_GET['action'] ?? 'list';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'get_sale':
                $saleId = (int)$_POST['sale_id'];
                $sale = $salesManager->getSale($saleId);
                
                if ($sale) {
                    echo json_encode(['success' => true, 'sale' => $sale]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Sale not found']);
                }
                break;
                
            case 'delete_sale':
                $saleId = (int)$_POST['sale_id'];
                
                // Check if sale can be deleted
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM sale_items WHERE sale_id = ?");
                $stmt->execute([$saleId]);
                $items = $stmt->fetch();
                
                if ($items && $items['count'] > 0) {
                    // Delete sale items first
                    $stmt = $db->prepare("DELETE FROM sale_items WHERE sale_id = ?");
                    $stmt->execute([$saleId]);
                }
                
                // Delete sale
                $stmt = $db->prepare("DELETE FROM sales WHERE sale_id = ?");
                $stmt->execute([$saleId]);
                
                $auditLogger->log($_SESSION['palami_user_id'], 'delete_sale', 'sales', $saleId);
                echo json_encode(['success' => true]);
                break;
                
            case 'process_return':
                $saleId = (int)$_POST['sale_id'];
                $returnReason = Security::sanitizeInput($_POST['return_reason'] ?? 'Customer return');
                
                // Get sale items
                $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
                $stmt->execute([$saleId]);
                $saleItems = $stmt->fetchAll();
                
                if (empty($saleItems)) {
                    echo json_encode(['success' => false, 'message' => 'No items found in this sale']);
                    break;
                }
                
                // Start transaction
                $db->beginTransaction();
                
                foreach ($saleItems as $item) {
                    // Update product stock
                    $stmt = $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?");
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                    
                    // Log inventory transaction
                    $stmt = $db->prepare("
                        INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                            quantity_change, previous_stock, new_stock, reference_id, notes)
                        SELECT ?, ?, 'return', ?, current_stock - ?, current_stock, ?, ?
                        FROM products WHERE product_id = ?
                    ");
                    $stmt->execute([
                        $item['product_id'],
                        $_SESSION['palami_user_id'],
                        $item['quantity'],
                        $item['quantity'],
                        'RETURN-' . $saleId,
                        $returnReason,
                        $item['product_id']
                    ]);
                }
                
                // Mark sale as returned
                $stmt = $db->prepare("UPDATE sales SET payment_method = 'returned' WHERE sale_id = ?");
                $stmt->execute([$saleId]);
                
                $db->commit();
                
                $auditLogger->log($_SESSION['palami_user_id'], 'process_return', 'sales', $saleId);
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        if (isset($db)) $db->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return') {
    if (!SessionManager::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $saleId = (int)$_POST['sale_id'];
            $returnReason = Security::sanitizeInput($_POST['return_reason']);
            
            // Get sale items
            $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$saleId]);
            $saleItems = $stmt->fetchAll();
            
            // Start transaction
            $db->beginTransaction();
            
            foreach ($saleItems as $item) {
                // Update product stock
                $stmt = $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE product_id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                // Log inventory transaction
                $stmt = $db->prepare("
                    INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                        quantity_change, previous_stock, new_stock, reference_id, notes)
                    SELECT ?, ?, 'return', ?, current_stock - ?, current_stock, ?, ?
                    FROM products WHERE product_id = ?
                ");
                $stmt->execute([
                    $item['product_id'],
                    $_SESSION['palami_user_id'],
                    $item['quantity'],
                    $item['quantity'],
                    'RETURN-' . $saleId,
                    $returnReason,
                    $item['product_id']
                ]);
            }
            
            // Mark sale as returned
            $stmt = $db->prepare("UPDATE sales SET payment_method = 'returned' WHERE sale_id = ?");
            $stmt->execute([$saleId]);
            
            // Create audit log
            $auditLogger->log($_SESSION['palami_user_id'], 'process_return', 'sales', $saleId);
            
            $db->commit();
            $message = 'Return processed successfully!';
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Error processing return: ' . $e->getMessage();
        }
    }
}

// Get date filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$paymentMethod = $_GET['payment_method'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Get sales with filters
$sales = [];
$totalSales = 0;
$totalRevenue = 0;
try {
    $query = "
        SELECT s.*, u.full_name as cashier_name,
               COUNT(si.sale_item_id) as item_count,
               SUM(si.quantity) as total_items
        FROM sales s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        WHERE DATE(s.sale_date) BETWEEN ? AND ?
    ";
    $params = [$dateFrom, $dateTo];
    
    if ($paymentMethod) {
        $query .= " AND s.payment_method = ?";
        $params[] = $paymentMethod;
    }
    
    if ($searchTerm) {
        $query .= " AND (s.invoice_number LIKE ? OR s.customer_name LIKE ? OR s.customer_email LIKE ?)";
        $searchLike = '%' . $searchTerm . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }
    
    $query .= " GROUP BY s.sale_id ORDER BY s.sale_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();
    
    // Calculate totals - FIXED: Check if $sales is an array before processing
    if (is_array($sales) && !empty($sales)) {
        $totalSales = count($sales);
        $totalRevenue = array_sum(array_column($sales, 'grand_total'));
    } else {
        $totalSales = 0;
        $totalRevenue = 0;
    }
} catch (Exception $e) {
    $error = 'Failed to load sales: ' . $e->getMessage();
    $sales = [];
    $totalSales = 0;
    $totalRevenue = 0;
}

// Get payment methods for filter
$paymentMethods = ['cash', 'credit_card', 'debit_card', 'mobile_payment', 'returned'];

// Get today's sales count
$todaySales = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sales WHERE DATE(sale_date) = CURDATE()");
    $stmt->execute();
    $result = $stmt->fetch();
    $todaySales = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $todaySales = 0;
}

// Get returns count
$returnsCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM sales WHERE payment_method = 'returned'");
    $stmt->execute();
    $result = $stmt->fetch();
    $returnsCount = $result ? $result['count'] : 0;
} catch (Exception $e) {
    $returnsCount = 0;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Sales Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ========================================
                   RESET & BASE STYLES
                ======================================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; color: #333; }
        a { text-decoration: none; }
        
        /* Header Styles */
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
        .header-left { display: flex; align-items: center; gap: 20px; }
        .header-logo { display: flex; align-items: center; gap: 15px; }
        .header-logo img { height: 45px; width: auto; filter: brightness(0) invert(1); }
        .header-logo h1 { font-size: 22px; font-weight: 700; color: #ffd700; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .header-logo .subtitle { font-size: 11px; opacity: 0.8; color: #bbdefb; }
        .header-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .header-right .user-name { font-weight: 600; color: #ffd700; }
        .header-right .user-role { font-size: 11px; opacity: 0.8; color: #bbdefb; text-transform: uppercase; }
        .logout-btn { color: white; padding: 8px 20px; background: rgba(255,215,0,0.15); border-radius: 6px; transition: all 0.3s; border: 1px solid rgba(255,215,0,0.25); display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: rgba(255,215,0,0.25); border-color: #ffd700; transform: translateY(-2px); }
        
        /* Navigation Styles */
        .nav-container {
            background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%);
            padding: 0 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            position: sticky;
            top: 69px;
            z-index: 999;
            border-bottom: 4px solid #ffd700;
        }
        .nav { display: flex; gap: 2px; list-style: none; margin: 0; padding: 0; flex-wrap: wrap; }
        .nav-item { position: relative; }
        .nav-link { display: flex; align-items: center; gap: 8px; color: #bbdefb; text-decoration: none; padding: 15px 22px; font-weight: 500; font-size: 14px; border-bottom: 3px solid transparent; transition: all 0.3s; cursor: pointer; background: transparent; border: none; font-family: inherit; }
        .nav-link i { font-size: 16px; transition: all 0.3s; color: #64b5f6; }
        .nav-link .arrow { font-size: 10px; margin-left: 6px; transition: transform 0.3s; }
        .nav-link:hover { color: #ffffff; background: rgba(100,181,246,0.15); border-bottom-color: #64b5f6; }
        .nav-link:hover i { color: #ffd700; transform: translateY(-2px) scale(1.1); }
        .nav-link.active { color: #ffffff; border-bottom-color: #ffd700; background: rgba(255,215,0,0.1); }
        .nav-link.active i { color: #ffd700; }
        
        .dropdown-menu { position: absolute; top: 100%; left: 0; min-width: 250px; background: #ffffff; border-radius: 0 0 10px 10px; box-shadow: 0 15px 40px rgba(0,0,0,0.2); opacity: 0; visibility: hidden; transform: translateY(-10px) scaleY(0.9); transition: all 0.3s; list-style: none; padding: 8px 0; z-index: 1000; border-top: 4px solid #ffd700; }
        .nav-item:hover .dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0) scaleY(1); }
        .dropdown-item { display: flex; align-items: center; gap: 12px; padding: 10px 20px; color: #333; text-decoration: none; font-size: 13.5px; transition: all 0.3s; border-left: 3px solid transparent; }
        .dropdown-item i { width: 22px; color: #1a237e; font-size: 14px; }
        .dropdown-item:hover { background: linear-gradient(90deg, #e3f2fd, #f5f9ff); border-left-color: #ffd700; padding-left: 28px; color: #0d47a1; }
        .dropdown-item:hover i { color: #ffd700; transform: scale(1.15); }
        .dropdown-item.active { background: #e3f2fd; border-left-color: #ffd700; color: #0d47a1; font-weight: 600; }
        .dropdown-divider { height: 1px; background: linear-gradient(90deg, transparent, #64b5f6, #ffd700, #64b5f6, transparent); margin: 6px 15px; }
        .badge-nav { background: #ffd700; color: #1a237e; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; margin-left: auto; }
        .badge-nav.danger { background: #e74c3c; color: white; animation: pulse-badge 2s infinite; }
        @keyframes pulse-badge { 0%,100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        
        /* Main Content */
        .container { padding: 30px; max-width: 1400px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: #1a237e; font-size: 24px; font-weight: 700; }
        .page-header h2 i { color: #ffd700; margin-right: 10px; }
        .page-header .breadcrumb { color: #7f8c8d; font-size: 14px; }
        .page-header .breadcrumb a { color: #1a237e; }
        
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert i { font-size: 20px; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #1a237e, #0d47a1); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26,35,126,0.3); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-danger { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231,76,60,0.3); }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(243,156,18,0.3); }
        .btn-info { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        .btn-info:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(23,162,184,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .table-container { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #e3f2fd; padding: 14px 18px; text-align: left; font-weight: 600; color: #1a237e; border-bottom: 2px solid #bbdefb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 14px 18px; border-bottom: 1px solid #e3f2fd; font-size: 14px; transition: background 0.3s; }
        table tr:hover td { background: #f5f9ff; }
        
        .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        .badge-cash { background: #d4edda; color: #155724; }
        .badge-credit_card { background: #cce5ff; color: #004085; }
        .badge-mobile_payment { background: #fff3cd; color: #856404; }
        .badge-debit_card { background: #e8d5b7; color: #6d4c2a; }
        .badge-returned { background: #f8d7da; color: #721c24; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center; border-left: 4px solid #1a237e; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-card .number.gold { color: #ffd700; }
        .stat-card .number.green { color: #27ae60; }
        .stat-card .number.red { color: #e74c3c; }
        .stat-card .label { color: #7f8c8d; font-size: 13px; margin-top: 5px; }
        
        .filter-bar { display: flex; gap: 15px; flex-wrap: wrap; align-items: end; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .filter-bar .form-group { margin-bottom: 0; }
        .filter-bar .form-group label { font-size: 12px; text-transform: uppercase; color: #7f8c8d; letter-spacing: 0.5px; display: block; margin-bottom: 5px; }
        .filter-bar .form-group input, .filter-bar .form-group select { padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-bar .form-group input:focus, .filter-bar .form-group select:focus { border-color: #1a237e; outline: none; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center; animation: fadeIn 0.3s ease; }
        .modal.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .modal-content { background: white; padding: 30px; border-radius: 12px; max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e3f2fd; }
        .modal-header h3 { color: #1a237e; font-size: 20px; }
        .modal-header h3 i { color: #ffd700; margin-right: 10px; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #95a5a6; transition: color 0.3s; }
        .modal-close:hover { color: #e74c3c; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #555; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #1a237e; outline: none; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; }
        
        .receipt-view { background: #f8f9fa; padding: 20px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 13px; white-space: pre-wrap; line-height: 1.6; max-height: 500px; overflow-y: auto; }
        
        @media (max-width: 768px) {
            .header { padding: 10px 15px; flex-direction: column; align-items: stretch; gap: 10px; }
            .header-left { justify-content: center; }
            .header-logo { flex-direction: column; text-align: center; }
            .header-logo h1 { font-size: 16px; }
            .header-right { justify-content: center; }
            .nav-container { padding: 0 10px; overflow-x: auto; top: auto; }
            .nav { flex-wrap: nowrap; gap: 0; min-width: max-content; }
            .nav-link { padding: 12px 14px; font-size: 12px; white-space: nowrap; }
            .nav-link span:not(.arrow) { display: none; }
            .nav-link i { font-size: 18px; }
            .dropdown-menu { position: static; box-shadow: none; border-radius: 0; border-top: none; background: #e3f2fd; padding: 5px 0; }
            .nav-item:hover .dropdown-menu { transform: none; }
            .dropdown-item { padding: 8px 20px 8px 40px; }
            .container { padding: 15px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar .form-group input, .filter-bar .form-group select { min-width: 100%; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .modal-content { padding: 20px; width: 95%; }
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
                <button class="nav-link active">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Sales</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="sales.php" class="dropdown-item active">
                            <i class="fas fa-receipt"></i>
                            All Sales
                            <span class="badge-nav"><?php echo $todaySales; ?></span>
                        </a>
                    </li>
                    <li>
                        <a href="../cashier/pos.php" class="dropdown-item" target="_blank">
                            <i class="fas fa-cash-register"></i>
                            New Sale (POS)
                            <span class="badge-nav success">Open</span>
                        </a>
                    </li>
                    <li>
                        <a href="sales.php?action=returns" class="dropdown-item <?php echo $action == 'returns' ? 'active' : ''; ?>">
                            <i class="fas fa-undo"></i>
                            Returns
                            <?php if ($returnsCount > 0): ?>
                                <span class="badge-nav danger"><?php echo $returnsCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a href="sales_report.php" class="dropdown-item">
                            <i class="fas fa-chart-bar"></i>
                            Sales Report
                        </a>
                    </li>
                </ul>
            </li>

            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="reports.php" class="dropdown-item"><i class="fas fa-home"></i> Reports Dashboard</a></li>
                    <li><a href="sales_report.php" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
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
                <h2><i class="fas fa-shopping-cart"></i> Sales Management</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / Sales
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="../cashier/pos.php" class="btn btn-success" target="_blank">
                    <i class="fas fa-cash-register"></i> New Sale
                </a>
                <a href="sales_report.php" class="btn btn-primary">
                    <i class="fas fa-chart-bar"></i> Sales Report
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
            <div class="stat-card">
                <div class="number gold">UGX <?php echo number_format($totalRevenue, 2); ?></div>
                <div class="label">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $totalSales; ?></div>
                <div class="label">Total Transactions</div>
            </div>
            <div class="stat-card">
                <div class="number green"><?php echo $todaySales; ?></div>
                <div class="label">Today's Sales</div>
            </div>
            <div class="stat-card">
                <div class="number <?php echo $returnsCount > 0 ? 'red' : 'green'; ?>">
                    <?php echo $returnsCount; ?>
                </div>
                <div class="label">Returns</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <?php if ($action == 'returns'): ?>
                <input type="hidden" name="action" value="returns">
            <?php endif; ?>
            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="form-group">
                <label>Payment Method</label>
                <select name="payment_method">
                    <option value="">All Methods</option>
                    <?php foreach ($paymentMethods as $method): ?>
                    <option value="<?php echo $method; ?>" <?php echo $paymentMethod == $method ? 'selected' : ''; ?>>
                        <?php echo ucfirst(str_replace('_', ' ', $method)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Invoice or Customer...">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="sales.php<?php echo $action == 'returns' ? '?action=returns' : ''; ?>" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</a>
        </form>

        <!-- Sales Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total (UGX)</th>
                            <th>Discount</th>
                            <th>Grand Total</th>
                            <th>Payment</th>
                            <th>Cashier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="10" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-receipt" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No sales found for the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td>
                                    <strong style="color:#1a237e;">
                                        <?php echo htmlspecialchars($sale['invoice_number']); ?>
                                    </strong>
                                </td>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo date('d M Y H:i', strtotime($sale['sale_date'])); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?>
                                    <?php if (isset($sale['customer_email']) && $sale['customer_email']): ?>
                                        <div style="font-size:11px;color:#95a5a6;">
                                            <?php echo htmlspecialchars($sale['customer_email']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $sale['total_items'] ?? 0; ?></td>
                                <td>UGX <?php echo number_format($sale['total_amount'] ?? 0, 2); ?></td>
                                <td style="color:#e74c3c;">
                                    UGX <?php echo number_format($sale['discount'] ?? 0, 2); ?>
                                </td>
                                <td>
                                    <strong style="color:#1a237e;">
                                        UGX <?php echo number_format($sale['grand_total'] ?? 0, 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php
                                        $methodClass = isset($sale['payment_method']) && $sale['payment_method'] == 'returned' ? 'badge-returned' : 'badge-' . ($sale['payment_method'] ?? 'cash');
                                    ?>
                                    <span class="badge <?php echo $methodClass; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'] ?? 'Cash')); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($sale['cashier_name'] ?? 'Unknown'); ?></td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <button class="btn btn-info btn-sm" onclick="viewSale(<?php echo $sale['sale_id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (isset($sale['payment_method']) && $sale['payment_method'] != 'returned'): ?>
                                            <button class="btn btn-warning btn-sm" onclick="processReturn(<?php echo $sale['sale_id']; ?>)">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger btn-sm" onclick="deleteSale(<?php echo $sale['sale_id']; ?>)">
                                            <i class="fas fa-trash"></i>
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

        <!-- Pagination Info -->
        <div style="margin-top:15px;text-align:right;color:#95a5a6;font-size:13px;">
            Showing <?php echo count($sales); ?> transactions
        </div>

    </div>

    <!-- ==========================================
    VIEW SALE MODAL
    ========================================== -->
    <div id="viewSaleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Sale Details</h3>
                <button class="modal-close" onclick="closeModal('viewSaleModal')">&times;</button>
            </div>
            <div id="saleDetails">
                <div style="text-align:center;padding:20px;color:#95a5a6;">
                    <i class="fas fa-spinner fa-spin" style="font-size:30px;"></i>
                    <p>Loading sale details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
    RETURN MODAL
    ========================================== -->
    <div id="returnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo"></i> Process Return</h3>
                <button class="modal-close" onclick="closeModal('returnModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return submitReturn()">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="process_return">
                <input type="hidden" name="sale_id" id="returnSaleId">
                
                <div class="form-group">
                    <label>Return Reason</label>
                    <textarea name="return_reason" id="returnReason" rows="3" placeholder="Enter reason for return..." required></textarea>
                </div>
                
                <div class="form-group" style="background:#fff3cd;padding:15px;border-radius:8px;border-left:4px solid #ffc107;">
                    <p style="font-size:14px;color:#856404;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Note:</strong> This will:
                    </p>
                    <ul style="margin-left:20px;color:#856404;font-size:13px;">
                        <li>Restock all items from this sale</li>
                        <li>Mark this sale as returned</li>
                        <li>Log the return in inventory transactions</li>
                    </ul>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('returnModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo"></i> Process Return
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
        // View Sale
        // ========================================
        function viewSale(saleId) {
            openModal('viewSaleModal');
            
            fetch('sales.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=get_sale&sale_id=' + saleId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const s = data.sale;
                    let html = `
                        <div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div><strong>Invoice:</strong> ${s.invoice_number}</div>
                                <div><strong>Date:</strong> ${new Date(s.sale_date).toLocaleString()}</div>
                                <div><strong>Customer:</strong> ${s.customer_name || 'Walk-in Customer'}</div>
                                <div><strong>Cashier:</strong> ${s.cashier_name}</div>
                                <div><strong>Payment:</strong> ${s.payment_method.toUpperCase()}</div>
                                <div><strong>Status:</strong> ${s.payment_method == 'returned' ? '🔄 Returned' : '✅ Completed'}</div>
                            </div>
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    
                    if (s.items && s.items.length > 0) {
                        s.items.forEach(item => {
                            html += `
                                <tr>
                                    <td>${item.product_name}</td>
                                    <td>${item.quantity}</td>
                                    <td>UGX ${Number(item.unit_price).toFixed(2)}</td>
                                    <td><strong>UGX ${Number(item.total_price).toFixed(2)}</strong></td>
                                </tr>
                            `;
                        });
                    } else {
                        html += `
                            <tr>
                                <td colspan="4" style="text-align:center;color:#95a5a6;">No items found</td>
                            </tr>
                        `;
                    }
                    
                    html += `
                            </tbody>
                            <tfoot>
                                <tr><td colspan="3" style="text-align:right;"><strong>Subtotal:</strong></td><td>UGX ${Number(s.total_amount).toFixed(2)}</td></tr>
                                <tr><td colspan="3" style="text-align:right;"><strong>Discount:</strong></td><td style="color:#e74c3c;">UGX ${Number(s.discount).toFixed(2)}</td></tr>
                                <tr><td colspan="3" style="text-align:right;"><strong>Tax:</strong></td><td>UGX ${Number(s.tax).toFixed(2)}</td></tr>
                                <tr style="font-weight:bold;font-size:18px;color:#1a237e;">
                                    <td colspan="3" style="text-align:right;">GRAND TOTAL:</td>
                                    <td>UGX ${Number(s.grand_total).toFixed(2)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    `;
                    
                    document.getElementById('saleDetails').innerHTML = html;
                } else {
                    document.getElementById('saleDetails').innerHTML = `
                        <div style="text-align:center;padding:40px;color:#e74c3c;">
                            <i class="fas fa-exclamation-circle" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                            ${data.message || 'Failed to load sale details'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('saleDetails').innerHTML = `
                    <div style="text-align:center;padding:40px;color:#e74c3c;">
                        <i class="fas fa-exclamation-circle" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                        Error loading sale details
                    </div>
                `;
            });
        }
        
        // ========================================
        // Process Return
        // ========================================
        function processReturn(saleId) {
            document.getElementById('returnSaleId').value = saleId;
            document.getElementById('returnReason').value = '';
            openModal('returnModal');
            setTimeout(() => {
                document.getElementById('returnReason').focus();
            }, 300);
        }
        
        function submitReturn() {
            const reason = document.getElementById('returnReason').value.trim();
            if (!reason) {
                alert('Please enter a return reason');
                return false;
            }
            
            if (!confirm('⚠️ Are you sure you want to process this return?\nThis will restock all items and cannot be undone.')) {
                return false;
            }
            
            return true;
        }
        
        // ========================================
        // Delete Sale
        // ========================================
        function deleteSale(saleId) {
            if (!confirm('⚠️ Are you sure you want to delete this sale?\nThis action cannot be undone!')) return;
            if (!confirm('Are you absolutely sure?')) return;
            
            fetch('sales.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=delete_sale&sale_id=' + saleId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to delete sale: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting sale');
            });
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