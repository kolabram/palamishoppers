<?php
/**
 * Palami Shoppers Kagoma - Payment Methods Management
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$auditLogger = new AuditLogger();

$message = '';
$error = '';
$action = $_GET['action'] ?? 'list';

// Define available payment methods
$availableMethods = [
    'cash' => [
        'name' => 'Cash',
        'icon' => 'fa-money-bill-wave',
        'color' => '#27ae60',
        'description' => 'Cash payments accepted at the counter',
        'enabled' => true
    ],
    'credit_card' => [
        'name' => 'Credit Card',
        'icon' => 'fa-credit-card',
        'color' => '#3498db',
        'description' => 'Credit card payments via card reader or manual entry',
        'enabled' => true
    ],
    'debit_card' => [
        'name' => 'Debit Card',
        'icon' => 'fa-credit-card',
        'color' => '#2980b9',
        'description' => 'Debit card payments via card reader or manual entry',
        'enabled' => true
    ],
    'mobile_money' => [
        'name' => 'Mobile Money',
        'icon' => 'fa-mobile-alt',
        'color' => '#f39c12',
        'description' => 'Mobile money payments (MTN, Airtel, etc.)',
        'enabled' => true,
        'providers' => [
            'mtn' => [
                'name' => 'MTN Mobile Money',
                'code' => 'MTN',
                'enabled' => true
            ],
            'airtel' => [
                'name' => 'Airtel Money',
                'code' => 'AIRTEL',
                'enabled' => true
            ]
        ]
    ],
    'bank_transfer' => [
        'name' => 'Bank Transfer',
        'icon' => 'fa-university',
        'color' => '#8e44ad',
        'description' => 'Direct bank transfers from customer accounts',
        'enabled' => true
    ]
];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'toggle_method':
                $method = Security::sanitizeInput($_POST['method']);
                $status = $_POST['status'] == 'true' ? 1 : 0;
                
                // Update in database (you can store in a settings table)
                $stmt = $db->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute(['payment_' . $method . '_enabled', $status, $status]);
                
                $auditLogger->log($_SESSION['palami_user_id'], 'toggle_payment_method', 'settings', null);
                echo json_encode(['success' => true]);
                break;
                
            case 'toggle_provider':
                $method = Security::sanitizeInput($_POST['method']);
                $provider = Security::sanitizeInput($_POST['provider']);
                $status = $_POST['status'] == 'true' ? 1 : 0;
                
                $stmt = $db->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute(['payment_' . $method . '_' . $provider . '_enabled', $status, $status]);
                
                $auditLogger->log($_SESSION['palami_user_id'], 'toggle_payment_provider', 'settings', null);
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get saved settings
$settings = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'payment_%'");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Settings table might not exist yet
}

$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = SessionManager::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Payment Methods</title>
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
        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }
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
        .btn-danger { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(231,76,60,0.3); }
        .btn-warning { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(243,156,18,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .badge { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-warning { background: #fff3cd; color: #856404; }
        
        /* Payment Method Cards */
        .payment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .payment-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            border-left: 4px solid #1a237e;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .payment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
        }
        
        .payment-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .payment-card .card-header .icon-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .payment-card .card-header .method-name {
            font-size: 18px;
            font-weight: 700;
            color: #1a237e;
        }
        
        .payment-card .card-header .toggle-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .payment-card .description {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #ccc;
            transition: .4s;
            border-radius: 26px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background: #27ae60;
        }
        
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        
        /* Provider List */
        .provider-list {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        
        .provider-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .provider-item:last-child {
            border-bottom: none;
        }
        
        .provider-item .provider-name {
            font-size: 14px;
            color: #555;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .provider-item .provider-name .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .provider-item .provider-name .dot.active {
            background: #27ae60;
        }
        
        .provider-item .provider-name .dot.inactive {
            background: #e74c3c;
        }
        
        .small-switch .switch {
            width: 36px;
            height: 20px;
        }
        
        .small-switch .slider:before {
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
        }
        
        .small-switch input:checked + .slider:before {
            transform: translateX(16px);
        }
        
        /* Stats */
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
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card .number {
            font-size: 28px;
            font-weight: 700;
            color: #1a237e;
        }
        
        .stat-card .label {
            color: #7f8c8d;
            font-size: 13px;
            margin-top: 5px;
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
            .payment-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
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
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? 'Admin'); ?></div>
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
                <button class="nav-link active"><i class="fas fa-shopping-cart"></i><span>Sales</span><span class="arrow"><i class="fas fa-chevron-down"></i></span></button>
                <ul class="dropdown-menu">
                    <li><a href="sales.php" class="dropdown-item"><i class="fas fa-receipt"></i> All Sales</a></li>
                    <li><a href="../cashier/pos.php" class="dropdown-item" target="_blank"><i class="fas fa-cash-register"></i> New Sale (POS)</a></li>
                    <li><a href="sales.php?action=returns" class="dropdown-item"><i class="fas fa-undo"></i> Returns</a></li>
                    <li class="dropdown-divider"></li>
                    <li><a href="payments.php" class="dropdown-item active"><i class="fas fa-credit-card"></i> Payment Methods</a></li>
                </ul>
            </li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i><span>Reports</span></a></li>
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
                <h2><i class="fas fa-credit-card"></i> Payment Methods</h2>
                <div class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / <a href="sales.php">Sales</a> / Payment Methods
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="../cashier/pos.php" class="btn btn-success" target="_blank">
                    <i class="fas fa-cash-register"></i> Open POS
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <?php
                $totalMethods = count($availableMethods);
                $enabledMethods = 0;
                $totalProviders = 0;
                $enabledProviders = 0;
                
                foreach ($availableMethods as $key => $method) {
                    $settingKey = 'payment_' . $key . '_enabled';
                    $isEnabled = isset($settings[$settingKey]) ? $settings[$settingKey] : 1;
                    if ($isEnabled) $enabledMethods++;
                    
                    if (isset($method['providers'])) {
                        foreach ($method['providers'] as $pKey => $provider) {
                            $totalProviders++;
                            $pSettingKey = 'payment_' . $key . '_' . $pKey . '_enabled';
                            if (isset($settings[$pSettingKey]) ? $settings[$pSettingKey] : 1) {
                                $enabledProviders++;
                            }
                        }
                    }
                }
            ?>
            <div class="stat-card">
                <div class="number"><?php echo $totalMethods; ?></div>
                <div class="label">Total Methods</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color:#27ae60;"><?php echo $enabledMethods; ?></div>
                <div class="label">Active Methods</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $totalProviders; ?></div>
                <div class="label">Total Providers</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color:#27ae60;"><?php echo $enabledProviders; ?></div>
                <div class="label">Active Providers</div>
            </div>
        </div>

        <!-- Payment Methods Grid -->
        <div class="payment-grid">
            <?php foreach ($availableMethods as $key => $method): 
                $settingKey = 'payment_' . $key . '_enabled';
                $isEnabled = isset($settings[$settingKey]) ? $settings[$settingKey] : 1;
                $hasProviders = isset($method['providers']);
            ?>
            <div class="payment-card" style="border-left-color: <?php echo $method['color']; ?>;">
                <div class="card-header">
                    <div style="display:flex;align-items:center;gap:15px;">
                        <div class="icon-wrapper" style="background: <?php echo $method['color']; ?>;">
                            <i class="fas <?php echo $method['icon']; ?>"></i>
                        </div>
                        <div>
                            <div class="method-name"><?php echo $method['name']; ?></div>
                            <span class="badge <?php echo $isEnabled ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $isEnabled ? '✅ Active' : '❌ Inactive'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="toggle-wrapper">
                        <label class="switch">
                            <input type="checkbox" <?php echo $isEnabled ? 'checked' : ''; ?> 
                                   onchange="toggleMethod('<?php echo $key; ?>', this.checked)">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="description"><?php echo $method['description']; ?></div>
                
                <?php if ($hasProviders): ?>
                <div class="provider-list">
                    <div style="font-weight:600;color:#555;font-size:13px;margin-bottom:5px;">
                        <i class="fas fa-list"></i> Providers
                    </div>
                    <?php foreach ($method['providers'] as $pKey => $provider): 
                        $pSettingKey = 'payment_' . $key . '_' . $pKey . '_enabled';
                        $pEnabled = isset($settings[$pSettingKey]) ? $settings[$pSettingKey] : 1;
                    ?>
                    <div class="provider-item">
                        <span class="provider-name">
                            <span class="dot <?php echo $pEnabled ? 'active' : 'inactive'; ?>"></span>
                            <?php echo $provider['name']; ?>
                            <span style="font-size:11px;color:#95a5a6;">(<?php echo $provider['code']; ?>)</span>
                        </span>
                        <div class="small-switch">
                            <label class="switch">
                                <input type="checkbox" <?php echo $pEnabled ? 'checked' : ''; ?> 
                                       onchange="toggleProvider('<?php echo $key; ?>', '<?php echo $pKey; ?>', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Info Box -->
        <div style="background:#e3f2fd;padding:20px;border-radius:12px;border-left:4px solid #1a237e;">
            <h4 style="color:#1a237e;margin-bottom:10px;">
                <i class="fas fa-info-circle" style="color:#ffd700;"></i> About Payment Methods
            </h4>
            <ul style="list-style:none;color:#555;line-height:2;">
                <li><strong>💵 Cash:</strong> Physical cash payments at the counter</li>
                <li><strong>💳 Credit Card:</strong> Credit card payments via card reader</li>
                <li><strong>💳 Debit Card:</strong> Debit card payments via card reader</li>
                <li><strong>📱 Mobile Money:</strong> Mobile money payments (MTN, Airtel, etc.)</li>
                <li><strong>🏦 Bank Transfer:</strong> Direct bank transfers from customer accounts</li>
            </ul>
            <div style="margin-top:10px;font-size:13px;color:#7f8c8d;">
                <i class="fas fa-lightbulb" style="color:#ffd700;"></i>
                Toggle payment methods on/off to control which options appear in the POS system.
            </div>
        </div>

    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        // ========================================
        // Toggle Payment Method
        // ========================================
        function toggleMethod(method, status) {
            if (!confirm(`Are you sure you want to ${status ? 'enable' : 'disable'} ${method.replace('_', ' ')} payments?`)) {
                // Revert the checkbox
                event.target.checked = !status;
                return;
            }
            
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=toggle_method&method=' + method + '&status=' + status
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload the page to update badges
                    location.reload();
                } else {
                    alert('Failed to update payment method: ' + data.message);
                    // Revert the checkbox
                    event.target.checked = !status;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating payment method');
                event.target.checked = !status;
            });
        }
        
        // ========================================
        // Toggle Provider
        // ========================================
        function toggleProvider(method, provider, status) {
            if (!confirm(`Are you sure you want to ${status ? 'enable' : 'disable'} ${provider.toUpperCase()}?`)) {
                event.target.checked = !status;
                return;
            }
            
            fetch('payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=toggle_provider&method=' + method + '&provider=' + provider + '&status=' + status
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update provider: ' + data.message);
                    event.target.checked = !status;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating provider');
                event.target.checked = !status;
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