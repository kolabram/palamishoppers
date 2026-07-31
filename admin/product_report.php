<?php
/**
 * Palami Shoppers Kagoma - Product Performance Report
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();

$error = '';
$sortBy = $_GET['sort'] ?? 'revenue';
$category = $_GET['category'] ?? '';

// Get product performance data
$products = [];
$categories = [];

try {
    // Get categories for filter
    $stmt = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get product performance
    $query = "
        SELECT 
            p.product_id,
            p.product_name,
            p.category,
            p.unit_price,
            p.current_stock,
            p.min_stock_level,
            COUNT(si.sale_item_id) as times_sold,
            SUM(si.quantity) as total_quantity_sold,
            SUM(si.total_price) as total_revenue,
            CASE 
                WHEN p.current_stock <= 0 THEN 'Out of Stock'
                WHEN p.current_stock <= p.min_stock_level THEN 'Low Stock'
                ELSE 'In Stock'
            END as stock_status
        FROM products p
        LEFT JOIN sale_items si ON p.product_id = si.product_id
        WHERE p.is_active = 1
    ";
    if ($category) {
        $query .= " AND p.category = ?";
        $params = [$category];
    } else {
        $params = [];
    }
    $query .= " GROUP BY p.product_id ORDER BY ";
    
    switch ($sortBy) {
        case 'revenue':
            $query .= "total_revenue DESC";
            break;
        case 'sales':
            $query .= "times_sold DESC";
            break;
        case 'quantity':
            $query .= "total_quantity_sold DESC";
            break;
        case 'stock':
            $query .= "current_stock ASC";
            break;
        default:
            $query .= "total_revenue DESC";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = 'Failed to load product data: ' . $e->getMessage();
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Product Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Same styles as other report pages */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; color: #333; }
        a { text-decoration: none; }
        
        .header { background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%); color: white; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 15px rgba(0,0,0,0.3); position: sticky; top: 0; z-index: 1000; flex-wrap: wrap; gap: 10px; }
        .header-left { display: flex; align-items: center; gap: 20px; }
        .header-logo { display: flex; align-items: center; gap: 15px; }
        .header-logo img { height: 45px; width: auto; filter: brightness(0) invert(1); }
        .header-logo h1 { font-size: 22px; font-weight: 700; color: #ffd700; }
        .header-logo .subtitle { font-size: 11px; opacity: 0.8; color: #bbdefb; }
        .header-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .header-right .user-name { font-weight: 600; color: #ffd700; }
        .header-right .user-role { font-size: 11px; opacity: 0.8; color: #bbdefb; text-transform: uppercase; }
        .logout-btn { color: white; padding: 8px 20px; background: rgba(255,215,0,0.15); border-radius: 6px; transition: all 0.3s; border: 1px solid rgba(255,215,0,0.25); display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: rgba(255,215,0,0.25); border-color: #ffd700; transform: translateY(-2px); }
        
        .nav-container { background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%); padding: 0 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); position: sticky; top: 69px; z-index: 999; border-bottom: 4px solid #ffd700; }
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
        
        .container { padding: 30px; max-width: 1400px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: #1a237e; font-size: 24px; font-weight: 700; }
        .page-header h2 i { color: #ffd700; margin-right: 10px; }
        .page-header .breadcrumb { color: #7f8c8d; font-size: 14px; }
        .page-header .breadcrumb a { color: #1a237e; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #1a237e, #0d47a1); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26,35,126,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        
        .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .filter-bar { display: flex; gap: 15px; flex-wrap: wrap; align-items: end; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .filter-bar .form-group { margin-bottom: 0; }
        .filter-bar .form-group label { font-size: 12px; text-transform: uppercase; color: #7f8c8d; letter-spacing: 0.5px; display: block; margin-bottom: 5px; }
        .filter-bar .form-group select { padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-bar .form-group select:focus { border-color: #1a237e; outline: none; }
        
        .table-container { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #e3f2fd; padding: 14px 18px; text-align: left; font-weight: 600; color: #1a237e; border-bottom: 2px solid #bbdefb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 14px 18px; border-bottom: 1px solid #e3f2fd; font-size: 14px; transition: background 0.3s; }
        table tr:hover td { background: #f5f9ff; }
        
        .summary-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .summary-item { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e0e0e0; }
        .summary-item .value { font-size: 18px; font-weight: 700; color: #1a237e; }
        .summary-item .label { font-size: 12px; color: #95a5a6; text-transform: uppercase; }
        
        .sort-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; }
        .sort-btn { padding: 6px 15px; border: 2px solid #e0e0e0; border-radius: 6px; background: white; cursor: pointer; transition: all 0.3s; font-size: 13px; }
        .sort-btn:hover { border-color: #1a237e; }
        .sort-btn.active { border-color: #1a237e; background: #1a237e; color: white; }
        
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert i { font-size: 20px; }
        
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
            .summary-row { grid-template-columns: 1fr 1fr; }
            .sort-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>

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
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? 'Admin'); ?></div>
                <div class="user-role">Administrator</div>
            </div>
            <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <nav class="nav-container">
        <ul class="nav">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users-cog"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-boxes"></i><span>Products</span></a></li>
            <li class="nav-item"><a href="sales.php" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Sales</span></a></li>
            <li class="nav-item">
                <button class="nav-link active"><i class="fas fa-chart-bar"></i><span>Reports</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
                <ul class="dropdown-menu">
                    <li><a href="sales_report.php" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                    <li><a href="inventory_report.php" class="dropdown-item"><i class="fas fa-warehouse"></i> Inventory Report</a></li>
                    <li><a href="product_report.php" class="dropdown-item active"><i class="fas fa-box"></i> Product Report</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="audit.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Audit Logs</span></a></li>
            <li class="nav-item"><a href="inventory.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Inventory</span></a></li>
        </ul>
    </nav>

    <div class="container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-box"></i> Product Performance Report</h2>
                <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / <a href="reports.php">Reports</a> / Product Report</div>
            </div>
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="GET" class="filter-bar">
            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category == $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="product_report.php" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</a>
        </form>

        <div class="sort-buttons">
            <span style="font-weight:600;color:#555;margin-right:10px;">Sort by:</span>
            <a href="?sort=revenue<?php echo $category ? '&category=' . urlencode($category) : ''; ?>" class="sort-btn <?php echo $sortBy == 'revenue' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i> Revenue
            </a>
            <a href="?sort=sales<?php echo $category ? '&category=' . urlencode($category) : ''; ?>" class="sort-btn <?php echo $sortBy == 'sales' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Times Sold
            </a>
            <a href="?sort=quantity<?php echo $category ? '&category=' . urlencode($category) : ''; ?>" class="sort-btn <?php echo $sortBy == 'quantity' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Quantity Sold
            </a>
            <a href="?sort=stock<?php echo $category ? '&category=' . urlencode($category) : ''; ?>" class="sort-btn <?php echo $sortBy == 'stock' ? 'active' : ''; ?>">
                <i class="fas fa-warehouse"></i> Stock Level
            </a>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price (UGX)</th>
                            <th>Stock</th>
                            <th>Times Sold</th>
                            <th>Qty Sold</th>
                            <th>Revenue (UGX)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-box-open" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No products found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $rank = 1; foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <?php if ($rank <= 3 && $sortBy == 'revenue'): ?>
                                        <span style="font-size:20px;">
                                            <?php echo $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : '🥉'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#95a5a6;">#<?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?></td>
                                <td>UGX <?php echo number_format($product['unit_price'], 2); ?></td>
                                <td style="color: <?php echo $product['current_stock'] <= 0 ? '#e74c3c' : ($product['current_stock'] <= $product['min_stock_level'] ? '#f39c12' : '#27ae60'); ?>;">
                                    <strong><?php echo $product['current_stock']; ?></strong>
                                </td>
                                <td><?php echo $product['times_sold'] ?? 0; ?></td>
                                <td><?php echo $product['total_quantity_sold'] ?? 0; ?></td>
                                <td><strong style="color:#1a237e;">UGX <?php echo number_format($product['total_revenue'] ?? 0, 2); ?></strong></td>
                                <td>
                                    <span class="badge <?php echo $product['stock_status'] == 'In Stock' ? 'badge-success' : ($product['stock_status'] == 'Low Stock' ? 'badge-warning' : 'badge-danger'); ?>">
                                        <?php echo $product['stock_status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
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
                            document.querySelectorAll('.dropdown-menu').forEach(m => { m.style.display = 'none'; });
                            dropdown.style.display = isOpen ? 'none' : 'block';
                        }
                    });
                }
            });
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    if (!e.target.closest('.nav-item')) {
                        document.querySelectorAll('.dropdown-menu').forEach(m => { m.style.display = 'none'; });
                    }
                }
            });
        });
    </script>

</body>
</html>