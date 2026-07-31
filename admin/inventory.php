<?php
/**
 * Palami Shoppers Kagoma - Inventory Management
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

// Get inventory data
$products = [];
$transactions = [];
$stats = [];

try {
    // Get product stock levels
    $stmt = $db->query("
        SELECT 
            p.*,
            CASE 
                WHEN p.current_stock <= 0 THEN 'out_of_stock'
                WHEN p.current_stock <= p.min_stock_level THEN 'low_stock'
                ELSE 'in_stock'
            END as stock_status,
            (SELECT COUNT(*) FROM sale_items WHERE product_id = p.product_id) as sales_count
        FROM products p
        WHERE p.is_active = 1
        ORDER BY 
            CASE 
                WHEN p.current_stock <= 0 THEN 1
                WHEN p.current_stock <= p.min_stock_level THEN 2
                ELSE 3
            END,
            p.product_name
    ");
    $products = $stmt->fetchAll();
    
    // Get recent inventory transactions
    $stmt = $db->query("
        SELECT t.*, p.product_name, u.full_name as user_name
        FROM inventory_transactions t
        JOIN products p ON t.product_id = p.product_id
        JOIN users u ON t.user_id = u.user_id
        ORDER BY t.transaction_date DESC
        LIMIT 50
    ");
    $transactions = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_products,
            SUM(current_stock) as total_stock,
            SUM(current_stock * unit_price) as total_value,
            SUM(CASE WHEN current_stock <= min_stock_level THEN 1 ELSE 0 END) as low_stock_count,
            SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
            AVG(current_stock) as avg_stock
        FROM products
        WHERE is_active = 1
    ");
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    $error = 'Failed to load inventory data: ' . $e->getMessage();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Inventory</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center; border-left: 4px solid #1a237e; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-card .number.gold { color: #ffd700; }
        .stat-card .number.green { color: #27ae60; }
        .stat-card .number.red { color: #e74c3c; }
        .stat-card .label { color: #7f8c8d; font-size: 13px; margin-top: 5px; }
        
        .inventory-tabs { display: flex; gap: 5px; margin-bottom: 20px; flex-wrap: wrap; background: white; padding: 10px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .inventory-tab { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; transition: all 0.3s; background: transparent; color: #7f8c8d; text-decoration: none; display: inline-block; }
        .inventory-tab:hover { background: #e3f2fd; color: #1a237e; }
        .inventory-tab.active { background: #1a237e; color: white; }
        .inventory-tab i { margin-right: 8px; }
        
        .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-box input { flex: 1; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; }
        .search-box input:focus { border-color: #1a237e; outline: none; }
        .search-box button { padding: 10px 20px; background: #1a237e; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .search-box button:hover { background: #0d47a1; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; }
        .form-group input:focus, .form-group select:focus { border-color: #1a237e; outline: none; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        
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
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .inventory-tabs { flex-direction: column; }
            .inventory-tab { text-align: center; }
            .form-row { grid-template-columns: 1fr; }
            .search-box { flex-direction: column; }
            .search-box button { width: 100%; justify-content: center; }
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
                <img src="../assets/images/logo.png" alt="Palami Shoppers" onerror="this.style.display='none'">
                <div>
                    <h1>Palami Shoppers Kagoma</h1>
                    <div class="subtitle">Supermarket Management System</div>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? $_SESSION['full_name'] ?? 'Admin'); ?></div>
                <div class="user-role">Administrator</div>
            </div>
            <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <!-- ==========================================
    NAVIGATION
    ========================================== -->
    <nav class="nav-container">
        <ul class="nav">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users-cog"></i><span>Users</span></a></li>
            <li class="nav-item">
                <button class="nav-link"><i class="fas fa-boxes"></i><span>Products</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
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
                <button class="nav-link"><i class="fas fa-shopping-cart"></i><span>Sales</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
                <ul class="dropdown-menu">
                    <li><a href="sales.php" class="dropdown-item"><i class="fas fa-receipt"></i> All Sales</a></li>
                    <li><a href="../cashier/pos.php" class="dropdown-item" target="_blank"><i class="fas fa-cash-register"></i> New Sale (POS)</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="audit.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Audit Logs</span></a></li>
            <li class="nav-item">
                <button class="nav-link active"><i class="fas fa-warehouse"></i><span>Inventory</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
                <ul class="dropdown-menu">
                    <li><a href="inventory.php" class="dropdown-item active"><i class="fas fa-list"></i> Stock Overview</a></li>
                    <li><a href="inventory.php?action=adjust" class="dropdown-item"><i class="fas fa-edit"></i> Adjust Stock</a></li>
                    <li><a href="inventory.php?action=history" class="dropdown-item"><i class="fas fa-history"></i> Transaction History</a></li>
                </ul>
            </li>
        </ul>
    </nav>

    <!-- ==========================================
    CONTENT
    ========================================== -->
    <div class="container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-warehouse"></i> Inventory Management</h2>
                <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / Inventory</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="products.php?action=add" class="btn btn-success">
                    <i class="fas fa-plus-circle"></i> Add Product
                </a>
                <a href="reports.php?type=inventory" class="btn btn-primary">
                    <i class="fas fa-chart-bar"></i> Inventory Report
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_products'] ?? 0; ?></div>
                <div class="label">Total Products</div>
            </div>
            <div class="stat-card">
                <div class="number gold">UGX <?php echo number_format($stats['total_value'] ?? 0, 2); ?></div>
                <div class="label">Total Inventory Value</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_stock'] ?? 0; ?></div>
                <div class="label">Total Stock Units</div>
            </div>
            <div class="stat-card">
                <div class="number <?php echo ($stats['low_stock_count'] ?? 0) > 0 ? 'red' : 'green'; ?>">
                    <?php echo $stats['low_stock_count'] ?? 0; ?>
                </div>
                <div class="label">Low Stock Items</div>
            </div>
            <div class="stat-card">
                <div class="number <?php echo ($stats['out_of_stock_count'] ?? 0) > 0 ? 'red' : 'green'; ?>">
                    <?php echo $stats['out_of_stock_count'] ?? 0; ?>
                </div>
                <div class="label">Out of Stock</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['avg_stock'] ?? 0, 1); ?></div>
                <div class="label">Average Stock/Product</div>
            </div>
        </div>

        <!-- Inventory Tabs -->
        <div class="inventory-tabs">
            <a href="inventory.php" class="inventory-tab <?php echo $action == 'list' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> Stock Overview
            </a>
            <a href="inventory.php?action=adjust" class="inventory-tab <?php echo $action == 'adjust' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i> Adjust Stock
            </a>
            <a href="inventory.php?action=history" class="inventory-tab <?php echo $action == 'history' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Transaction History
            </a>
        </div>

        <!-- Stock Overview -->
        <?php if ($action == 'list' || $action == ''): ?>
        
        <!-- Search Box - FIXED with proper function -->
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="🔍 Search products by name, barcode, or category..." onkeyup="searchInventory()">
            <button onclick="searchInventory()"><i class="fas fa-search"></i> Search</button>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Barcode</th>
                            <th>Category</th>
                            <th>Price (UGX)</th>
                            <th>Current Stock</th>
                            <th>Min Stock</th>
                            <th>Status</th>
                            <th>Sales</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="inventoryTableBody">
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-warehouse" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No products found in inventory.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                            <tr class="inventory-row" data-name="<?php echo strtolower($product['product_name']); ?>" 
                                data-barcode="<?php echo $product['barcode']; ?>"
                                data-category="<?php echo strtolower($product['category'] ?? ''); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                    <div style="font-size:12px;color:#95a5a6;"><?php echo htmlspecialchars($product['description'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;">
                                        <?php echo htmlspecialchars($product['barcode']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?></span>
                                </td>
                                <td>
                                    <strong>UGX <?php echo number_format($product['unit_price'], 2); ?></strong>
                                    <div style="font-size:11px;color:#95a5a6;">Cost: UGX <?php echo number_format($product['cost_price'] ?? 0, 2); ?></div>
                                </td>
                                <td>
                                    <strong style="color: <?php echo $product['current_stock'] <= 0 ? '#e74c3c' : ($product['current_stock'] <= $product['min_stock_level'] ? '#f39c12' : '#27ae60'); ?>;">
                                        <?php echo $product['current_stock']; ?>
                                    </strong>
                                </td>
                                <td><?php echo $product['min_stock_level']; ?></td>
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
                                        <a href="products.php?action=edit&id=<?php echo $product['product_id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="low-stock.php" class="btn btn-warning btn-sm">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Adjust Stock -->
        <?php if ($action == 'adjust'): ?>
        <div style="background:white;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.08);">
            <h3 style="color:#1a237e;margin-bottom:20px;">
                <i class="fas fa-edit"></i> Adjust Stock
            </h3>
            <form method="POST" action="products.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label>Select Product</label>
                    <select name="product_id" required>
                        <option value="">Select a product...</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['product_id']; ?>">
                            <?php echo htmlspecialchars($product['product_name']); ?> (Stock: <?php echo $product['current_stock']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity Change</label>
                        <input type="number" name="quantity" required placeholder="Enter quantity (positive or negative)">
                        <div style="font-size:12px;color:#95a5a6;margin-top:4px;">Use positive for adding stock, negative for removing</div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" name="notes" placeholder="Reason for adjustment">
                    </div>
                </div>
                <button type="submit" class="btn btn-success" style="margin-top:15px;">
                    <i class="fas fa-save"></i> Update Stock
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Transaction History -->
        <?php if ($action == 'history'): ?>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Previous</th>
                            <th>New</th>
                            <th>User</th>
                            <th>Reference</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-history" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No transactions found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $trans): ?>
                            <tr>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo date('d M Y H:i', strtotime($trans['transaction_date'])); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($trans['product_name']); ?></strong>
                                </td>
                                <td>
                                    <?php
                                        $typeClass = $trans['transaction_type'] == 'sale' ? 'badge-danger' : 
                                                    ($trans['transaction_type'] == 'purchase' ? 'badge-success' : 
                                                    ($trans['transaction_type'] == 'return' ? 'badge-warning' : 'badge-info'));
                                    ?>
                                    <span class="badge <?php echo $typeClass; ?>">
                                        <?php echo ucfirst($trans['transaction_type']); ?>
                                    </span>
                                </td>
                                <td style="color:<?php echo $trans['quantity_change'] < 0 ? '#e74c3c' : '#27ae60'; ?>;">
                                    <strong><?php echo $trans['quantity_change'] > 0 ? '+' : ''; ?><?php echo $trans['quantity_change']; ?></strong>
                                </td>
                                <td><?php echo $trans['previous_stock']; ?></td>
                                <td><strong><?php echo $trans['new_stock']; ?></strong></td>
                                <td><?php echo htmlspecialchars($trans['user_name']); ?></td>
                                <td><?php echo htmlspecialchars($trans['reference_id'] ?? '-'); ?></td>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo htmlspecialchars($trans['notes'] ?? '-'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==========================================
    JAVASCRIPT - FIXED SEARCH FUNCTION
    ========================================== -->
    <script>
        /**
         * Search Inventory - Filters products in the table
         */
        function searchInventory() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.inventory-row');
            let foundCount = 0;
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name') || '';
                const barcode = row.getAttribute('data-barcode') || '';
                const category = row.getAttribute('data-category') || '';
                
                // Check if the search term matches any of the fields
                if (name.includes(filter) || barcode.includes(filter) || category.includes(filter)) {
                    row.style.display = '';
                    foundCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update search results count (optional)
            const resultCount = document.getElementById('searchResultCount');
            if (resultCount) {
                resultCount.textContent = foundCount + ' products found';
            }
        }

        /**
         * Reset search and show all products
         */
        function resetSearch() {
            document.getElementById('searchInput').value = '';
            searchInventory();
        }

        // Add Enter key support for search
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchInventory();
                    }
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