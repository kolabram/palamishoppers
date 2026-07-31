<?php
/**
 * Palami Shoppers Kagoma - Audit Logs
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();

$filter = $_GET['filter'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$userId = $_GET['user_id'] ?? null;
$search = $_GET['search'] ?? '';

// Get logs
$logs = [];
$stats = [];

try {
    // Get statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_logs,
            COUNT(DISTINCT user_id) as unique_users,
            COUNT(DISTINCT action) as unique_actions,
            COUNT(DISTINCT DATE(log_date)) as active_days
        FROM audit_logs
    ");
    $stats = $stmt->fetch();
    
    // Get logs with filters
    $query = "SELECT * FROM audit_logs WHERE 1=1";
    $params = [];
    
    if ($filter !== 'all') {
        $query .= " AND action LIKE ?";
        $params[] = '%' . $filter . '%';
    }
    
    if ($dateFrom) {
        $query .= " AND DATE(log_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $query .= " AND DATE(log_date) <= ?";
        $params[] = $dateTo;
    }
    
    if ($userId) {
        $query .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    if ($search) {
        $query .= " AND (action LIKE ? OR table_name LIKE ? OR ip_address LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " ORDER BY log_date DESC LIMIT 200";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // Get users for filter
    $users = $db->query("SELECT user_id, username, full_name FROM users ORDER BY full_name")->fetchAll();
    
} catch (Exception $e) {
    $error = 'Failed to load audit logs: ' . $e->getMessage();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Audit Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Same base styles as other pages */
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
        table td { padding: 14px 18px; border-bottom: 1px solid #e3f2fd; font-size: 13px; transition: background 0.3s; }
        table tr:hover td { background: #f5f9ff; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-primary { background: #cce5ff; color: #004085; }
        
        .filter-bar { display: flex; gap: 15px; flex-wrap: wrap; align-items: end; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .filter-bar .form-group { margin-bottom: 0; }
        .filter-bar .form-group label { font-size: 12px; text-transform: uppercase; color: #7f8c8d; letter-spacing: 0.5px; display: block; margin-bottom: 5px; }
        .filter-bar .form-group input, .filter-bar .form-group select { padding: 8px 12px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 13px; min-width: 150px; }
        .filter-bar .form-group input:focus, .filter-bar .form-group select:focus { border-color: #1a237e; outline: none; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center; border-left: 4px solid #1a237e; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-card .label { color: #7f8c8d; font-size: 13px; margin-top: 5px; }
        
        .log-entry { padding: 10px 15px; border-left: 3px solid #1a237e; margin-bottom: 5px; background: #f8f9fa; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .log-entry:hover { background: #e3f2fd; }
        .log-entry .log-action { font-weight: 600; color: #1a237e; }
        .log-entry .log-badge { padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .log-entry .log-badge.login { background: #d4edda; color: #155724; }
        .log-entry .log-badge.logout { background: #f8d7da; color: #721c24; }
        .log-entry .log-badge.create { background: #cce5ff; color: #004085; }
        .log-entry .log-badge.update { background: #fff3cd; color: #856404; }
        .log-entry .log-badge.delete { background: #f8d7da; color: #721c24; }
        .log-entry .log-time { color: #95a5a6; font-size: 12px; }
        
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
            .log-entry { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

    <!-- Header -->
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

    <!-- Navigation -->
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
            <li class="nav-item">
                <button class="nav-link"><i class="fas fa-chart-bar"></i><span>Reports</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
                <ul class="dropdown-menu">
                    <li><a href="reports.php?type=sales" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                    <li><a href="reports.php?type=inventory" class="dropdown-item"><i class="fas fa-warehouse"></i> Inventory Report</a></li>
                    <li><a href="reports.php?type=products" class="dropdown-item"><i class="fas fa-box"></i> Product Report</a></li>
                    <li><a href="reports.php?type=users" class="dropdown-item"><i class="fas fa-users"></i> User Activity Report</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="audit.php" class="nav-link active"><i class="fas fa-clipboard-list"></i><span>Audit Logs</span></a></li>
            <li class="nav-item"><a href="inventory.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Inventory</span></a></li>
        </ul>
    </nav>

    <!-- Content -->
    <div class="container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-clipboard-list"></i> Audit Logs</h2>
                <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / Audit Logs</div>
            </div>
            <button class="btn btn-primary" onclick="window.location.reload()">
                <i class="fas fa-sync"></i> Refresh
            </button>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_logs'] ?? 0; ?></div>
                <div class="label">Total Logs</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['unique_users'] ?? 0; ?></div>
                <div class="label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['unique_actions'] ?? 0; ?></div>
                <div class="label">Action Types</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['active_days'] ?? 0; ?></div>
                <div class="label">Active Days</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <div class="form-group">
                <label>Action Filter</label>
                <select name="filter">
                    <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>All Actions</option>
                    <option value="login" <?php echo $filter == 'login' ? 'selected' : ''; ?>>Logins</option>
                    <option value="logout" <?php echo $filter == 'logout' ? 'selected' : ''; ?>>Logouts</option>
                    <option value="create" <?php echo $filter == 'create' ? 'selected' : ''; ?>>Create</option>
                    <option value="update" <?php echo $filter == 'update' ? 'selected' : ''; ?>>Update</option>
                    <option value="delete" <?php echo $filter == 'delete' ? 'selected' : ''; ?>>Delete</option>
                    <option value="sale" <?php echo $filter == 'sale' ? 'selected' : ''; ?>>Sales</option>
                </select>
            </div>
            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="form-group">
                <label>User</label>
                <select name="user_id">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['user_id']; ?>" <?php echo $userId == $user['user_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs...">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="audit.php" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</a>
        </form>

        <!-- Logs Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Record</th>
                            <th>IP Address</th>
                            <th>Date &amp; Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:40px;color:#95a5a6;">
                                    <i class="fas fa-inbox" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                                    No logs found for the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['user_id'] ?? 'System'); ?></strong>
                                </td>
                                <td>
                                    <?php
                                        $badgeClass = '';
                                        $action = strtolower($log['action']);
                                        if (strpos($action, 'login') !== false) $badgeClass = 'login';
                                        elseif (strpos($action, 'logout') !== false) $badgeClass = 'logout';
                                        elseif (strpos($action, 'create') !== false) $badgeClass = 'create';
                                        elseif (strpos($action, 'update') !== false) $badgeClass = 'update';
                                        elseif (strpos($action, 'delete') !== false) $badgeClass = 'delete';
                                        else $badgeClass = 'info';
                                    ?>
                                    <span class="badge badge-<?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['table_name']): ?>
                                        <span style="font-size:12px;color:#7f8c8d;">
                                            <?php echo htmlspecialchars($log['table_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#95a5a6;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log['record_id']): ?>
                                        <span style="font-size:12px;color:#1a237e;">
                                            #<?php echo $log['record_id']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#95a5a6;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? '127.0.0.1'); ?>
                                    </code>
                                </td>
                                <td style="font-size:13px;color:#7f8c8d;">
                                    <?php echo date('d M Y H:i:s', strtotime($log['log_date'])); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Log Count -->
        <div style="margin-top:15px;text-align:right;color:#95a5a6;font-size:13px;">
            Showing <?php echo count($logs); ?> logs
        </div>
    </div>

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