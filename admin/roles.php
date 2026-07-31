<?php
/**
 * Palami Shoppers Kagoma - Roles & Permissions Management
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();

// Define roles and permissions
$roles = [
    'admin' => [
        'label' => 'Administrator',
        'icon' => 'fa-shield-alt',
        'color' => '#e74c3c',
        'badge' => 'danger',
        'description' => 'Full system access. Can manage users, products, inventory, view reports, and configure settings.',
        'permissions' => [
            'manage_users' => true,
            'manage_products' => true,
            'manage_inventory' => true,
            'view_sales' => true,
            'view_reports' => true,
            'view_audit' => true,
            'manage_settings' => true,
            'manage_roles' => true,
            'access_pos' => true,
        ]
    ],
    'cashier' => [
        'label' => 'Cashier',
        'icon' => 'fa-cash-register',
        'color' => '#3498db',
        'badge' => 'primary',
        'description' => 'Access to POS system. Can process sales and view sales history.',
        'permissions' => [
            'manage_users' => false,
            'manage_products' => false,
            'manage_inventory' => false,
            'view_sales' => true,
            'view_reports' => false,
            'view_audit' => false,
            'manage_settings' => false,
            'manage_roles' => false,
            'access_pos' => true,
        ]
    ],
    'inventory_manager' => [
        'label' => 'Inventory Manager',
        'icon' => 'fa-warehouse',
        'color' => '#f39c12',
        'badge' => 'warning',
        'description' => 'Manages products and inventory. Can update stock, add products, and generate inventory reports.',
        'permissions' => [
            'manage_users' => false,
            'manage_products' => true,
            'manage_inventory' => true,
            'view_sales' => true,
            'view_reports' => true,
            'view_audit' => false,
            'manage_settings' => false,
            'manage_roles' => false,
            'access_pos' => false,
        ]
    ]
];

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();

// Get user counts by role
$roleCounts = [];
foreach ($roles as $role => $data) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role = ? AND is_active = 1");
        $stmt->execute([$role]);
        $result = $stmt->fetch();
        $roleCounts[$role] = $result ? $result['count'] : 0;
    } catch (Exception $e) {
        $roleCounts[$role] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Roles & Permissions</title>
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
        
        /* Main Content */
        .container { padding: 30px; max-width: 1400px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: #1a237e; font-size: 24px; font-weight: 700; }
        .page-header h2 i { color: #ffd700; margin-right: 10px; }
        .page-header .breadcrumb { color: #7f8c8d; font-size: 14px; }
        .page-header .breadcrumb a { color: #1a237e; }
        
        .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-admin { background: #e74c3c; color: white; }
        .badge-cashier { background: #3498db; color: white; }
        .badge-inventory_manager { background: #f39c12; color: white; }
        
        .role-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border-left: 6px solid #1a237e;
            transition: all 0.3s ease;
        }
        .role-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
        }
        .role-card .role-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .role-card .role-name {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .role-card .role-name .icon-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }
        .role-card .role-name h3 {
            font-size: 20px;
            color: #1a237e;
        }
        .role-card .role-name .user-count {
            font-size: 14px;
            color: #7f8c8d;
        }
        .role-card .role-description {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 15px;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #ffd700;
        }
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        .permission-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 13px;
            transition: all 0.3s;
        }
        .permission-item:hover {
            background: #e3f2fd;
        }
        .permission-item .check {
            color: #27ae60;
            font-size: 16px;
        }
        .permission-item .cross {
            color: #e74c3c;
            font-size: 16px;
        }
        .permission-item .label {
            color: #555;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 25px;
            border-radius: 12px;
            margin-top: 30px;
            border-left: 4px solid #1a237e;
        }
        .info-box h4 {
            color: #1a237e;
            margin-bottom: 15px;
        }
        .info-box ul {
            list-style: none;
            color: #555;
            line-height: 2;
        }
        .info-box ul li strong {
            color: #1a237e;
        }
        .info-box ul li .role-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
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
            .role-card .role-header { flex-direction: column; align-items: flex-start; }
            .permissions-grid { grid-template-columns: 1fr; }
            .role-card .role-name { flex-wrap: wrap; }
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
                <button class="nav-link active">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="users.php" class="dropdown-item"><i class="fas fa-users"></i> Manage Users</a></li>
                    <li><a href="users.php?action=add" class="dropdown-item"><i class="fas fa-user-plus"></i> Add New User</a></li>
                    <li><a href="roles.php" class="dropdown-item active"><i class="fas fa-user-tag"></i> Roles &amp; Permissions</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="activity.php" class="dropdown-item"><i class="fas fa-history"></i> User Activity</a></li>
                </ul>
            </li>
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
                    <li><a href="reports.php" class="dropdown-item"><i class="fas fa-home"></i> Reports Dashboard</a></li>
                    <li><a href="sales_report.php" class="dropdown-item"><i class="fas fa-chart-line"></i> Sales Report</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="audit.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Audit Logs</span></a></li>
            <li class="nav-item"><a href="inventory.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Inventory</span></a></li>
        </ul>
    </nav>

    <!-- ==========================================
    CONTENT
    ========================================== -->
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h2><i class="fas fa-user-tag"></i> Roles &amp; Permissions</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / <a href="users.php">Users</a> / Roles &amp; Permissions
                </div>
            </div>
            <a href="users.php" class="btn btn-primary">
                <i class="fas fa-users"></i> Manage Users
            </a>
        </div>

        <!-- Role Cards -->
        <?php foreach ($roles as $role => $data): ?>
        <div class="role-card" style="border-left-color: <?php echo $data['color']; ?>;">
            <div class="role-header">
                <div class="role-name">
                    <div class="icon-circle" style="background: <?php echo $data['color']; ?>;">
                        <i class="fas <?php echo $data['icon']; ?>"></i>
                    </div>
                    <div>
                        <h3><?php echo $data['label']; ?></h3>
                        <div class="user-count">
                            <i class="fas fa-users"></i> 
                            <?php echo $roleCounts[$role] ?? 0; ?> active users
                        </div>
                    </div>
                </div>
                <span class="badge badge-<?php echo $role; ?>" style="font-size:14px;padding:8px 20px;">
                    <i class="fas <?php echo $data['icon']; ?>"></i> <?php echo $role; ?>
                </span>
            </div>
            
            <div class="role-description">
                <i class="fas fa-info-circle" style="color:#ffd700;"></i>
                <?php echo $data['description']; ?>
            </div>
            
            <div style="font-weight:600;color:#1a237e;margin-bottom:10px;font-size:14px;">
                <i class="fas fa-key"></i> Permissions:
            </div>
            <div class="permissions-grid">
                <?php foreach ($data['permissions'] as $permission => $allowed): ?>
                <div class="permission-item">
                    <?php if ($allowed): ?>
                        <i class="fas fa-check-circle check"></i>
                    <?php else: ?>
                        <i class="fas fa-times-circle cross"></i>
                    <?php endif; ?>
                    <span class="label">
                        <?php echo ucwords(str_replace('_', ' ', $permission)); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Information Box -->
        <div class="info-box">
            <h4><i class="fas fa-info-circle" style="color:#ffd700;"></i> About Roles</h4>
            <ul>
                <li>
                    <span class="role-dot" style="background:#e74c3c;"></span>
                    <strong style="color:#e74c3c;">Administrator:</strong> 
                    Full system access. Can manage users, products, inventory, view reports, and configure settings.
                </li>
                <li>
                    <span class="role-dot" style="background:#3498db;"></span>
                    <strong style="color:#3498db;">Cashier:</strong> 
                    Access to POS system. Can process sales and view sales history.
                </li>
                <li>
                    <span class="role-dot" style="background:#f39c12;"></span>
                    <strong style="color:#f39c12;">Inventory Manager:</strong> 
                    Manages products and inventory. Can update stock, add products, and generate inventory reports.
                </li>
            </ul>
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
        });
    </script>

</body>
</html>