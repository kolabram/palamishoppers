<?php
/**
 * Palami Shoppers Kagoma - Admin Dashboard
 * Supermarket Management System
 */

// Include required files
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../classes/Security.php';
require_once '../classes/SalesManager.php';
require_once '../classes/ProductManager.php';
require_once '../classes/AuditLogger.php';

// Start session and check authorization
SessionManager::startSession();
SessionManager::requireRole('admin');

// Initialize managers
$salesManager = new SalesManager();
$productManager = new ProductManager();
$auditLogger = new AuditLogger();
$db = Database::getInstance()->getConnection();

// Get dashboard statistics
$stats = $salesManager->getDashboardStats();
$lowStockProducts = $productManager->getLowStockProducts();
$recentLogs = $auditLogger->getLogs(10);

// Get current page for active navigation
$currentPage = basename($_SERVER['PHP_SELF']);

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
    <title>Palami Shoppers Kagoma - Admin Dashboard</title>
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
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
        
        .logout-btn i {
            font-size: 14px;
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
        
        /* Navigation Hover Effects */
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
        
        /* Navigation Active State */
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
        
        /* Navigation Indicator Line */
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
        
        /* Dropdown Divider */
        .dropdown-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #64b5f6, #ffd700, #64b5f6, transparent);
            margin: 6px 15px;
        }
        
        /* Dropdown Badges */
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
        
        /* Notification Dot */
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
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid #ffd700;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .welcome-banner h2 {
            color: #1a237e;
            font-size: 22px;
            font-weight: 700;
        }
        
        .welcome-banner h2 span {
            color: #ffd700;
        }
        
        .welcome-banner p {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .welcome-banner .date-time {
            color: #1a237e;
            font-weight: 600;
            font-size: 14px;
            background: #e3f2fd;
            padding: 10px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome-banner .date-time i {
            color: #ffd700;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid #1a237e;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(100, 181, 246, 0.05) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            border-left-color: #ffd700;
        }
        
        .stat-card .stat-icon {
            font-size: 30px;
            margin-bottom: 10px;
            display: inline-block;
        }
        
        .stat-card h3 {
            color: #7f8c8d;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #1a237e;
            margin: 5px 0;
        }
        
        .stat-card .value.gold {
            color: #ffd700;
        }
        
        .stat-card .value.green {
            color: #27ae60;
        }
        
        .stat-card .value.red {
            color: #e74c3c;
        }
        
        .stat-card .label {
            color: #95a5a6;
            font-size: 13px;
        }
        
        .stat-card .trend {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .stat-card .trend.up {
            background: #d4edda;
            color: #155724;
        }
        
        .stat-card .trend.down {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Sections */
        .section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .section-header h2 {
            color: #1a237e;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-header h2 i {
            color: #ffd700;
        }
        
        .section-header .view-all {
            color: #1a237e;
            font-weight: 600;
            font-size: 14px;
            padding: 6px 15px;
            border-radius: 6px;
            transition: all 0.3s ease;
            background: #e3f2fd;
        }
        
        .section-header .view-all:hover {
            background: #ffd700;
            color: #1a237e;
            transform: translateX(5px);
        }
        
        /* Alert Box */
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
        
        /* ========================================
                   TABLE STYLES
                ======================================== */
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            background: #e3f2fd;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            border-bottom: 2px solid #bbdefb;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        table td {
            padding: 12px 15px;
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
            padding: 4px 12px;
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
        
        .badge-gold {
            background: #ffd700;
            color: #1a237e;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
            color: #bbdefb;
        }
        
        /* ========================================
                   RESPONSIVE STYLES
                ======================================== */
        @media (max-width: 1024px) {
            .nav-link {
                padding: 12px 16px;
                font-size: 13px;
            }
            
            .header-logo h1 {
                font-size: 18px;
            }
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
            
            .header-logo .subtitle {
                font-size: 10px;
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
            
            .dropdown-item i {
                width: 18px;
            }
            
            .container {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .stat-card .value {
                font-size: 24px;
            }
            
            .welcome-banner {
                flex-direction: column;
                text-align: center;
            }
            
            .welcome-banner .date-time {
                font-size: 12px;
                padding: 8px 15px;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card .value {
                font-size: 20px;
            }
        }
        
        /* ========================================
                   SCROLLBAR STYLING
                ======================================== */
        .nav-container::-webkit-scrollbar {
            height: 4px;
        }
        
        .nav-container::-webkit-scrollbar-track {
            background: #1a237e;
        }
        
        .nav-container::-webkit-scrollbar-thumb {
            background: #ffd700;
            border-radius: 4px;
        }
        
        /* ========================================
                   ANIMATIONS
                ======================================== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
        
        .section {
            animation: fadeInUp 0.5s ease forwards;
            animation-delay: 0.5s;
            opacity: 0;
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
            <!-- Dashboard -->
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Users -->
            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-users-cog"></i>
                    <span>Users</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="users.php" class="dropdown-item">
                            <i class="fas fa-users"></i>
                            Manage Users
                            <span class="badge-nav">12</span>
                        </a>
                    </li>
                    <li>
                        <a href="users.php?action=add" class="dropdown-item">
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
                            <span class="badge-nav danger">12</span>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Products -->
            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-boxes"></i>
                    <span>Products</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="products.php" class="dropdown-item">
                            <i class="fas fa-list"></i>
                            All Products
                            <span class="badge-nav"><?php echo $stats['total_products'] ?? 0; ?></span>
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
                            <?php if (($stats['low_stock_count'] ?? 0) > 0): ?>
                                <span class="badge-nav danger"><?php echo $stats['low_stock_count']; ?></span>
                                <span class="notification-dot"></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Sales -->
            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Sales</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="sales.php" class="dropdown-item">
                            <i class="fas fa-receipt"></i>
                            All Sales
                            <span class="badge-nav"><?php echo $stats['today_sales']['count'] ?? 0; ?></span>
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
                        <a href="sales.php?action=returns" class="dropdown-item">
                            <i class="fas fa-undo"></i>
                            Returns
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

            <!-- Reports -->
            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="reports.php" class="dropdown-item">
                            <i class="fas fa-home"></i>
                            Reports Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="sales_report.php" class="dropdown-item">
                            <i class="fas fa-chart-line"></i>
                            Sales Report
                        </a>
                    </li>
                    <li>
                        <a href="inventory_report.php" class="dropdown-item">
                            <i class="fas fa-warehouse"></i>
                            Inventory Report
                        </a>
                    </li>
                    <li>
                        <a href="product_report.php" class="dropdown-item">
                            <i class="fas fa-box"></i>
                            Product Report
                        </a>
                    </li>
                    <li>
                        <a href="activity.php" class="dropdown-item">
                            <i class="fas fa-users"></i>
                            User Activity
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Audit Logs -->
            <li class="nav-item">
                <a href="audit.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Logs</span>
                </a>
            </li>

            <!-- Inventory -->
            <li class="nav-item">
                <button class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    <span>Inventory</span>
                    <span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="inventory.php" class="dropdown-item">
                            <i class="fas fa-list"></i>
                            Stock Overview
                        </a>
                    </li>
                    <li>
                        <a href="inventory.php?action=adjust" class="dropdown-item">
                            <i class="fas fa-edit"></i>
                            Adjust Stock
                        </a>
                    </li>
                    <li>
                        <a href="inventory.php?action=history" class="dropdown-item">
                            <i class="fas fa-history"></i>
                            Transaction History
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>

    <!-- ==========================================
    MAIN CONTENT
    ========================================== -->
    <div class="container">

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h2>Welcome back, <span><?php echo htmlspecialchars($_SESSION['palami_full_name'] ?? $_SESSION['full_name'] ?? 'Admin'); ?></span> 👋</h2>
                <p>Here's what's happening with your store today.</p>
            </div>
            <div class="date-time">
                <i class="fas fa-calendar-alt"></i>
                <?php echo date('l, d F Y'); ?>
                <i class="fas fa-clock" style="margin-left:10px;"></i>
                <?php echo date('h:i A'); ?>
            </div>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <h3>Today's Sales</h3>
                <div class="value">UGX <?php echo number_format($stats['today_sales']['total'] ?? 0, 2); ?></div>
                <div class="label">
                    <?php echo $stats['today_sales']['count'] ?? 0; ?> transactions today
                    <span class="trend up">
                        <i class="fas fa-arrow-up"></i> 0%
                    </span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <h3>This Month</h3>
                <div class="value gold">UGX <?php echo number_format($stats['month_sales']['total'] ?? 0, 2); ?></div>
                <div class="label">
                    <?php echo $stats['month_sales']['count'] ?? 0; ?> transactions this month
                    <span class="trend up">
                        <i class="fas fa-arrow-up"></i> 0%
                    </span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <h3>Low Stock Alerts</h3>
                <div class="value <?php echo ($stats['low_stock_count'] ?? 0) > 0 ? 'red' : 'green'; ?>">
                    <?php echo $stats['low_stock_count'] ?? 0; ?>
                </div>
                <div class="label">
                    <?php echo ($stats['low_stock_count'] ?? 0) > 0 ? 'Products need restocking' : 'All products in stock'; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <h3>Total Products</h3>
                <div class="value"><?php echo $stats['total_products'] ?? 0; ?></div>
                <div class="label">Active products in inventory</div>
            </div>
        </div>

        <!-- Low Stock Alerts Section -->
        <?php if (!empty($lowStockProducts)): ?>
        <div class="section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-exclamation-triangle" style="color:#ffd700;"></i>
                    Low Stock Alerts
                </h2>
                <a href="low-stock.php" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Current Stock</th>
                            <th>Min Level</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockProducts as $product): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?></td>
                            <td>
                                <strong style="color: <?php echo $product['current_stock'] <= 0 ? '#e74c3c' : '#f39c12'; ?>;">
                                    <?php echo $product['current_stock']; ?>
                                </strong>
                            </td>
                            <td><?php echo $product['min_stock_level']; ?></td>
                            <td>
                                <span class="badge <?php echo $product['current_stock'] <= 0 ? 'badge-danger' : 'badge-warning'; ?>">
                                    <?php echo $product['current_stock'] <= 0 ? '🚫 Out of Stock' : '⚠️ Low Stock'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="products.php?action=edit&id=<?php echo $product['product_id']; ?>" 
                                   style="color:#1a237e;font-weight:600;font-size:13px;">
                                    Restock <i class="fas fa-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert-box">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>✅ All products are well stocked!</strong>
                No low stock alerts at this time.
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activity Section -->
        <div class="section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-history"></i>
                    Recent Activity
                </h2>
                <a href="audit.php" class="view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            
            <?php if (!empty($recentLogs)): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Date &amp; Time</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td>
                                <i class="fas fa-user-circle" style="color:#1a237e;"></i>
                                <?php echo htmlspecialchars($log['user_id'] ?? 'System'); ?>
                            </td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($log['table_name'] ?? '-'); ?>
                            </td>
                            <td>
                                <i class="far fa-clock" style="color:#95a5a6;"></i>
                                <?php echo date('d M Y H:i:s', strtotime($log['log_date'])); ?>
                            </td>
                            <td>
                                <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:12px;">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? '127.0.0.1'); ?>
                                </code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No recent activity found</p>
                <p style="font-size:13px;color:#bbb;">Activities will appear here as users interact with the system</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        /**
         * Mobile menu toggle for dropdowns
         */
        document.addEventListener('DOMContentLoaded', function() {
            const navItems = document.querySelectorAll('.nav-item');
            
            navItems.forEach(item => {
                const link = item.querySelector('.nav-link');
                const dropdown = item.querySelector('.dropdown-menu');
                
                if (link && dropdown) {
                    // Check if it's a button (has dropdown)
                    if (link.tagName === 'BUTTON') {
                        link.addEventListener('click', function(e) {
                            if (window.innerWidth <= 768) {
                                e.preventDefault();
                                const isOpen = dropdown.style.display === 'block';
                                
                                // Close all dropdowns
                                document.querySelectorAll('.dropdown-menu').forEach(m => {
                                    m.style.display = 'none';
                                });
                                
                                // Toggle this one
                                dropdown.style.display = isOpen ? 'none' : 'block';
                            }
                        });
                    }
                }
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    if (!e.target.closest('.nav-item')) {
                        document.querySelectorAll('.dropdown-menu').forEach(m => {
                            m.style.display = 'none';
                        });
                    }
                }
            });
            
            // Refresh stats every 60 seconds
            setInterval(function() {
                location.reload();
            }, 60000);
        });
    </script>

</body>
</html>