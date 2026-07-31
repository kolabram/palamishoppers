<?php
/**
 * Palami Shoppers Kagoma - Reports Dashboard
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$salesManager = new SalesManager();

$message = '';
$error = '';

// Get report type
$reportType = $_GET['type'] ?? 'dashboard';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get dashboard statistics
$stats = $salesManager->getDashboardStats();

// Get top products
$topProducts = [];
try {
    $stmt = $db->prepare("
        SELECT 
            p.product_id,
            p.product_name,
            p.category,
            p.unit_price,
            COUNT(si.sale_item_id) as times_sold,
            SUM(si.quantity) as total_quantity,
            SUM(si.total_price) as total_revenue
        FROM products p
        LEFT JOIN sale_items si ON p.product_id = si.product_id
        WHERE p.is_active = 1
        GROUP BY p.product_id
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $stmt->execute();
    $topProducts = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

// Get monthly sales trend (last 6 months)
$monthlyTrend = [];
try {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(sale_date, '%Y-%m') as month,
            COUNT(*) as total_sales,
            SUM(grand_total) as total_revenue,
            AVG(grand_total) as avg_sale
        FROM sales
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    $monthlyTrend = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

// Get payment method distribution
$paymentDistribution = [];
try {
    $stmt = $db->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            SUM(grand_total) as total
        FROM sales
        WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY payment_method
    ");
    $stmt->execute();
    $paymentDistribution = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

// Get low stock count for badge
$lowStockCount = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM low_stock_alerts WHERE is_resolved = 0");
    $lowStockCount = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    // Ignore
}

// Get recent sales for quick view
$recentSales = [];
try {
    $stmt = $db->prepare("
        SELECT 
            s.invoice_number,
            s.customer_name,
            s.grand_total,
            s.payment_method,
            s.sale_date,
            u.full_name as cashier_name
        FROM sales s
        JOIN users u ON s.user_id = u.user_id
        ORDER BY s.sale_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentSales = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();

// Get user ID for session
$userId = 0;
if (isset($_SESSION['palami_user_id'])) {
    $userId = $_SESSION['palami_user_id'];
} elseif (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Reports Dashboard</title>
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
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #1a237e, #0d47a1); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26,35,126,0.3); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-info { background: linear-gradient(135deg, #17a2b8, #138496); color: white; }
        .btn-info:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(23,162,184,0.3); }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(243,156,18,0.3); }
        .btn-danger { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231,76,60,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
            transition: all 0.3s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-card .number.gold { color: #ffd700; }
        .stat-card .number.green { color: #27ae60; }
        .stat-card .number.red { color: #e74c3c; }
        .stat-card .label { color: #7f8c8d; font-size: 13px; margin-top: 5px; }
        
        /* Report Cards Grid */
        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .report-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            display: block;
        }
        
        .report-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            border-color: #ffd700;
        }
        
        .report-card .icon {
            font-size: 36px;
            width: 65px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            margin-bottom: 20px;
            color: white;
        }
        
        .report-card .icon.blue { background: linear-gradient(135deg, #1a237e, #0d47a1); }
        .report-card .icon.green { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .report-card .icon.orange { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .report-card .icon.purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .report-card .icon.red { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .report-card .icon.teal { background: linear-gradient(135deg, #1abc9c, #16a085); }
        .report-card .icon.pink { background: linear-gradient(135deg, #e91e63, #c2185b); }
        
        .report-card h3 {
            color: #1a237e;
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .report-card p {
            color: #7f8c8d;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .report-card .badge-count {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #ffd700;
            color: #1a237e;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .report-card .badge-count.danger {
            background: #e74c3c;
            color: white;
        }
        
        .report-card .arrow-link {
            color: #1a237e;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }
        
        .report-card .arrow-link:hover {
            color: #ffd700;
            gap: 10px;
        }
        
        /* Chart Containers */
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .chart-container h3 {
            color: #1a237e;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-container h3 i {
            color: #ffd700;
        }
        
        .chart-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        /* Bar Chart */
        .bar-chart {
            display: flex;
            align-items: flex-end;
            height: 250px;
            gap: 15px;
            padding: 10px 0;
            overflow-x: auto;
        }
        
        .bar-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 50px;
        }
        
        .bar-item .bar {
            width: 35px;
            background: linear-gradient(180deg, #1a237e, #64b5f6);
            border-radius: 4px 4px 0 0;
            transition: height 0.5s;
            min-height: 5px;
            position: relative;
        }
        
        .bar-item .bar:hover {
            opacity: 0.8;
            cursor: pointer;
        }
        
        .bar-item .bar .tooltip {
            display: none;
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #1a237e;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
        }
        
        .bar-item .bar:hover .tooltip {
            display: block;
        }
        
        .bar-item .label {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 8px;
            text-align: center;
        }
        
        .bar-item .value {
            font-size: 11px;
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 5px;
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
        }
        
        table td {
            padding: 14px 18px;
            border-bottom: 1px solid #e3f2fd;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        table tr:hover td {
            background: #f5f9ff;
        }
        
        .section-title {
            color: #1a237e;
            font-size: 20px;
            margin: 30px 0 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: #ffd700;
        }
        
        .alert-box {
            background: #fff3cd;
            border-left: 4px solid #ffd700;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-box i {
            font-size: 24px;
            color: #ffd700;
        }
        
        .alert-box strong {
            color: #1a237e;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .chart-row {
                grid-template-columns: 1fr;
            }
        }
        
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
            .report-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .chart-row { grid-template-columns: 1fr; }
            .bar-chart { height: 180px; }
            .bar-item { min-width: 35px; }
            .bar-item .bar { width: 25px; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
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
    NAVIGATION
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
                <button class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Sales</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="sales.php" class="dropdown-item"><i class="fas fa-receipt"></i> All Sales</a></li>
                    <li><a href="../cashier/pos.php" class="dropdown-item" target="_blank"><i class="fas fa-cash-register"></i> New Sale (POS)</a></li>
                    <li><a href="sales_report.php" class="dropdown-item"><i class="fas fa-chart-bar"></i> Sales Report</a></li>
                </ul>
            </li>
            <li class="nav-item">
                <button class="nav-link active">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="reports.php" class="dropdown-item active"><i class="fas fa-home"></i> Reports Dashboard</a></li>
                    <li><a href="sales_report.php" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                    <li><a href="inventory_report.php" class="dropdown-item"><i class="fas fa-warehouse"></i> Inventory Report</a></li>
                    <li><a href="product_report.php" class="dropdown-item"><i class="fas fa-box"></i> Product Report</a></li>
                    <li><a href="audit.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Audit Logs</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="reports.php?export=all" class="dropdown-item"><i class="fas fa-file-export"></i> Export All</a></li>
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
                <h2><i class="fas fa-chart-bar"></i> Reports Dashboard</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / Reports
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Dashboard
                </button>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number gold">UGX <?php echo number_format($stats['today_sales']['total'] ?? 0, 2); ?></div>
                <div class="label">Today's Sales</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['today_sales']['count'] ?? 0; ?></div>
                <div class="label">Today's Transactions</div>
            </div>
            <div class="stat-card">
                <div class="number gold">UGX <?php echo number_format($stats['month_sales']['total'] ?? 0, 2); ?></div>
                <div class="label">This Month</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_products'] ?? 0; ?></div>
                <div class="label">Total Products</div>
            </div>
            <div class="stat-card">
                <div class="number <?php echo ($stats['low_stock_count'] ?? 0) > 0 ? 'red' : 'green'; ?>">
                    <?php echo $stats['low_stock_count'] ?? 0; ?>
                </div>
                <div class="label">Low Stock Alerts</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_users'] ?? 0; ?></div>
                <div class="label">Active Users</div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <?php if (($stats['low_stock_count'] ?? 0) > 0): ?>
        <div class="alert-box">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>⚠️ Low Stock Alert!</strong>
                There are <strong><?php echo $stats['low_stock_count']; ?></strong> products that need restocking.
                <a href="low-stock.php" style="color:#1a237e;font-weight:600;margin-left:10px;">
                    View Alerts <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Cards -->
        <h3 class="section-title"><i class="fas fa-file-alt"></i> Available Reports</h3>
        <div class="report-grid">
            
            <!-- Sales Report -->
            <a href="sales_report.php" class="report-card">
                <div class="badge-count">New</div>
                <div class="icon blue">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>Sales Report</h3>
                <p>View detailed sales analytics including revenue trends, payment methods, and daily performance.</p>
                <span class="arrow-link">
                    View Report <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <!-- Inventory Report -->
            <a href="inventory_report.php" class="report-card">
                <div class="icon green">
                    <i class="fas fa-warehouse"></i>
                </div>
                <h3>Inventory Report</h3>
                <p>Monitor stock levels, inventory value, category distribution, and identify slow-moving items.</p>
                <span class="arrow-link">
                    View Report <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <!-- Product Performance Report -->
            <a href="product_report.php" class="report-card">
                <div class="icon orange">
                    <i class="fas fa-box"></i>
                </div>
                <h3>Product Performance</h3>
                <p>Analyze product sales performance, top sellers, and revenue contribution per product.</p>
                <span class="arrow-link">
                    View Report <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <!-- User Activity Report -->
            <a href="activity.php" class="report-card">
                <div class="icon purple">
                    <i class="fas fa-users"></i>
                </div>
                <h3>User Activity Report</h3>
                <p>Track user activity, sales performance, and login history of your staff members.</p>
                <span class="arrow-link">
                    View Report <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <!-- Audit Logs -->
            <a href="audit.php" class="report-card">
                <div class="icon teal">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <h3>Audit Logs</h3>
                <p>Complete system audit trail with user actions, timestamps, and IP tracking.</p>
                <span class="arrow-link">
                    View Logs <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <!-- Low Stock Alerts -->
            <a href="low-stock.php" class="report-card">
                <div class="icon red">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>Low Stock Alerts</h3>
                <p>View all products that need restocking with current stock levels and thresholds.</p>
                <span class="arrow-link">
                    View Alerts <i class="fas fa-arrow-right"></i>
                </span>
                <?php if (($stats['low_stock_count'] ?? 0) > 0): ?>
                    <div class="badge-count danger">
                        <?php echo $stats['low_stock_count']; ?>
                    </div>
                <?php endif; ?>
            </a>

            <!-- Financial Report -->
            <a href="sales_report.php?type=financial" class="report-card">
                <div class="icon pink">
                    <i class="fas fa-coins"></i>
                </div>
                <h3>Financial Report</h3>
                <p>Comprehensive financial overview including revenue, expenses, and profitability analysis.</p>
                <span class="arrow-link">
                    View Report <i class="fas fa-arrow-right"></i>
                </span>
            </a>
        </div>

        <!-- Charts Section -->
        <div class="chart-row">
            <!-- Monthly Trend -->
            <div class="chart-container">
                <h3><i class="fas fa-chart-bar"></i> Monthly Sales Trend</h3>
                <?php if (!empty($monthlyTrend)): ?>
                    <div class="bar-chart">
                        <?php 
                        $maxRevenue = max(array_column($monthlyTrend, 'total_revenue'));
                        $maxRevenue = $maxRevenue > 0 ? $maxRevenue : 1;
                        foreach ($monthlyTrend as $month): 
                            $height = ($month['total_revenue'] / $maxRevenue) * 200;
                        ?>
                        <div class="bar-item">
                            <div class="value">UGX <?php echo number_format($month['total_revenue'], 0); ?></div>
                            <div class="bar" style="height: <?php echo max($height, 5); ?>px;">
                                <div class="tooltip">
                                    <?php echo date('M Y', strtotime($month['month'] . '-01')); ?>: 
                                    UGX <?php echo number_format($month['total_revenue'], 2); ?>
                                    (<?php echo $month['total_sales']; ?> sales)
                                </div>
                            </div>
                            <div class="label"><?php echo date('M', strtotime($month['month'] . '-01')); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align:center;color:#95a5a6;padding:30px 0;">No sales data available for the selected period.</p>
                <?php endif; ?>
            </div>

            <!-- Payment Method Distribution -->
            <div class="chart-container">
                <h3><i class="fas fa-credit-card"></i> Payment Methods</h3>
                <?php if (!empty($paymentDistribution)): ?>
                    <div style="padding:20px 0;">
                        <?php 
                        $totalPayments = array_sum(array_column($paymentDistribution, 'total'));
                        $totalPayments = $totalPayments > 0 ? $totalPayments : 1;
                        $colors = ['#1a237e', '#ffd700', '#27ae60', '#e74c3c', '#3498db'];
                        $icons = [
                            'cash' => 'fa-money-bill-wave',
                            'credit_card' => 'fa-credit-card',
                            'debit_card' => 'fa-credit-card',
                            'mobile_payment' => 'fa-mobile-alt'
                        ];
                        $i = 0;
                        foreach ($paymentDistribution as $payment): 
                            $percentage = ($payment['total'] / $totalPayments) * 100;
                        ?>
                        <div style="display:flex;align-items:center;gap:15px;margin-bottom:12px;">
                            <div style="width:30px;text-align:center;">
                                <i class="fas <?php echo $icons[$payment['payment_method']] ?? 'fa-wallet'; ?>" 
                                   style="color:<?php echo $colors[$i % count($colors)]; ?>;font-size:20px;"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">
                                    <span style="font-weight:600;">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    </span>
                                    <span style="color:#1a237e;font-weight:600;">
                                        UGX <?php echo number_format($payment['total'], 2); ?>
                                        <span style="color:#95a5a6;font-weight:400;">
                                            (<?php echo round($percentage, 1); ?>%)
                                        </span>
                                    </span>
                                </div>
                                <div style="height:8px;background:#ecf0f1;border-radius:4px;overflow:hidden;">
                                    <div style="height:100%;width:<?php echo $percentage; ?>%;background:<?php echo $colors[$i % count($colors)]; ?>;border-radius:4px;transition:width 1s;"></div>
                                </div>
                                <div style="font-size:11px;color:#95a5a6;margin-top:2px;">
                                    <?php echo $payment['count']; ?> transactions
                                </div>
                            </div>
                        </div>
                        <?php $i++; endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align:center;color:#95a5a6;padding:30px 0;">No payment data available.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Products -->
        <h3 class="section-title"><i class="fas fa-trophy"></i> Top Performing Products</h3>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price (UGX)</th>
                            <th>Times Sold</th>
                            <th>Quantity Sold</th>
                            <th>Revenue (UGX)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProducts)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-box-open" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No product sales data available.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; foreach ($topProducts as $product): ?>
                            <tr>
                                <td>
                                    <?php if ($rank <= 3): ?>
                                        <span style="font-size:20px;">
                                            <?php echo $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : '🥉'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#95a5a6;">#<?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?>
                                    </span>
                                </td>
                                <td>UGX <?php echo number_format($product['unit_price'], 2); ?></td>
                                <td><?php echo $product['times_sold'] ?? 0; ?></td>
                                <td><?php echo $product['total_quantity'] ?? 0; ?></td>
                                <td>
                                    <strong style="color:#1a237e;">
                                        UGX <?php echo number_format($product['total_revenue'] ?? 0, 2); ?>
                                    </strong>
                                </td>
                            </tr>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Sales -->
        <h3 class="section-title"><i class="fas fa-clock"></i> Recent Transactions</h3>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Amount (UGX)</th>
                            <th>Payment</th>
                            <th>Cashier</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSales)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-receipt" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No recent sales.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td>
                                    <strong style="color:#1a237e;">
                                        <?php echo htmlspecialchars($sale['invoice_number']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?>
                                </td>
                                <td>
                                    <strong>UGX <?php echo number_format($sale['grand_total'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $sale['payment_method']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($sale['cashier_name']); ?></td>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo date('d M Y H:i', strtotime($sale['sale_date'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top:30px;text-align:center;color:#95a5a6;font-size:12px;padding:20px;border-top:1px solid #e0e0e0;">
            <p>Report generated on <?php echo date('d F Y H:i:s'); ?></p>
            <p>Palami Shoppers Kagoma - Supermarket Management System v1.0</p>
        </div>

    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        // Mobile navigation toggle
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
            
            // Animate bars on load
            setTimeout(function() {
                document.querySelectorAll('.bar').forEach(bar => {
                    const height = bar.style.height;
                    bar.style.height = '0px';
                    setTimeout(() => {
                        bar.style.height = height;
                    }, 100);
                });
            }, 500);
        });
    </script>

</body>
</html>