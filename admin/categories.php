<?php
/**
 * Category Management
 * Palami Shoppers Kagoma
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'add_category':
                $name = trim($_POST['category_name']);
                $description = trim($_POST['category_description'] ?? '');
                $icon = trim($_POST['icon_class'] ?? 'fa-tag');
                $color = trim($_POST['color_code'] ?? '#6c757d');
                
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Category name is required']);
                    break;
                }
                
                // Check if category exists
                $stmt = $db->prepare("SELECT category_id FROM categories WHERE category_name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Category already exists']);
                    break;
                }
                
                $stmt = $db->prepare("INSERT INTO categories (category_name, category_description, icon_class, color_code) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $description, $icon, $color]);
                
                echo json_encode(['success' => true, 'message' => 'Category added successfully']);
                break;
                
            case 'edit_category':
                $id = intval($_POST['category_id']);
                $name = trim($_POST['category_name']);
                $description = trim($_POST['category_description'] ?? '');
                $icon = trim($_POST['icon_class'] ?? 'fa-tag');
                $color = trim($_POST['color_code'] ?? '#6c757d');
                $is_active = intval($_POST['is_active'] ?? 1);
                
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Category name is required']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE categories SET category_name = ?, category_description = ?, icon_class = ?, color_code = ?, is_active = ? WHERE category_id = ?");
                $stmt->execute([$name, $description, $icon, $color, $is_active, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Category updated successfully']);
                break;
                
            case 'delete_category':
                $id = intval($_POST['category_id']);
                
                // Check if category has products
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete category with products. Assign products to another category first.']);
                    break;
                }
                
                $stmt = $db->prepare("DELETE FROM categories WHERE category_id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
                break;
                
            case 'bulk_insert_categories':
                // Bulk insert all predefined categories
                $categories = [
                    ['Cereals', 'Breakfast cereals, rice, maize, wheat and other grain products', 'fa-wheat', '#F39C12'],
                    ['Bakery and Confectioneries', 'Bread, cakes, pastries, cookies and sweets', 'fa-bread-slice', '#E67E22'],
                    ['Cosmetics and Body Care', 'Skincare, hair care, makeup and personal care products', 'fa-spa', '#E91E63'],
                    ['Drinks', 'Beverages, juices, soft drinks, water and energy drinks', 'fa-glass-whiskey', '#3498DB'],
                    ['Flours', 'Wheat flour, maize flour, rice flour and other baking flours', 'fa-seedling', '#F1C40F'],
                    ['Kitchen Spices', 'Salt, pepper, herbs, spices and seasoning', 'fa-pepper-hot', '#E74C3C'],
                    ['Kitchen Ware', 'Cooking utensils, pots, pans, dishes and kitchen tools', 'fa-utensils', '#2C3E50'],
                    ['Milk Products', 'Fresh milk, yogurt, cheese, butter and dairy products', 'fa-cheese', '#5DADE2'],
                    ['Soap and Detergents', 'Bar soap, liquid soap, laundry detergent and cleaning products', 'fa-soap', '#1ABC9C'],
                    ['Spirits and Wine', 'Alcoholic beverages, wine, beer, spirits and liquors', 'fa-wine-bottle', '#8E44AD'],
                    ['Stationery', 'Office supplies, writing materials, paper and school supplies', 'fa-pen-fancy', '#2ECC71']
                ];
                
                $inserted = 0;
                $skipped = 0;
                
                foreach ($categories as $cat) {
                    // Check if category exists
                    $stmt = $db->prepare("SELECT category_id FROM categories WHERE category_name = ?");
                    $stmt->execute([$cat[0]]);
                    if (!$stmt->fetch()) {
                        $stmt = $db->prepare("INSERT INTO categories (category_name, category_description, icon_class, color_code) VALUES (?, ?, ?, ?)");
                        $stmt->execute($cat);
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Categories inserted: $inserted, Skipped (already exist): $skipped"
                ]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Get all categories
$stmt = $db->query("SELECT * FROM categories ORDER BY category_name");
$categories = $stmt->fetchAll();

// Get category counts
$stmt = $db->query("
    SELECT c.category_id, c.category_name, c.icon_class, c.color_code, c.is_active,
           COUNT(p.product_id) as product_count,
           SUM(p.current_stock) as total_stock
    FROM categories c
    LEFT JOIN products p ON c.category_id = p.category_id
    GROUP BY c.category_id
    ORDER BY c.category_name
");
$categoryStats = $stmt->fetchAll();

$userName = $_SESSION['palami_full_name'] ?? $_SESSION['full_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Palami Shoppers Kagoma</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f0f2f5; 
            min-height: 100vh; 
            color: #333; 
        }
        
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
        .header-logo h1 { font-size: 22px; font-weight: 700; color: #ffd700; }
        .header-logo .subtitle { font-size: 11px; opacity: 0.8; color: #bbdefb; }
        .header-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .header-right .user-name { font-weight: 600; color: #ffd700; }
        .header-right .user-role { font-size: 11px; opacity: 0.8; color: #bbdefb; text-transform: uppercase; }
        .logout-btn { color: white; padding: 8px 20px; background: rgba(255,215,0,0.15); border-radius: 6px; transition: all 0.3s; border: 1px solid rgba(255,215,0,0.25); display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: rgba(255,215,0,0.25); border-color: #ffd700; transform: translateY(-2px); }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 25px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h2 {
            color: #1a237e;
            font-size: 24px;
        }
        .page-header h2 i {
            color: #ffd700;
            margin-right: 10px;
        }
        
        .btn-add {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(39, 174, 96, 0.3);
        }
        
        .btn-bulk {
            background: linear-gradient(135deg, #8e44ad, #9b59b6);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-bulk:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(142, 68, 173, 0.3);
        }
        
        .btn-back {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-back:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-card .icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }
        .stat-card .info h4 {
            font-size: 20px;
            font-weight: 700;
            color: #1a237e;
        }
        .stat-card .info span {
            font-size: 12px;
            color: #95a5a6;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e8ecf1;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e8ecf1;
            font-size: 14px;
            vertical-align: middle;
        }
        tr:hover td {
            background: #f8f9fa;
        }
        
        .category-badge {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .category-badge .color-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,0.1);
            flex-shrink: 0;
        }
        .category-badge i {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }
        
        .status-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
            margin: 0 3px;
        }
        .action-btn:hover {
            transform: scale(1.1);
        }
        .btn-edit {
            background: #3498db;
            color: white;
        }
        .btn-edit:hover {
            background: #2980b9;
        }
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        .btn-delete:hover {
            background: #c0392b;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 99999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
            animation: modalFadeIn 0.3s ease;
        }
        .modal.show {
            display: flex;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideUp 0.3s ease;
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e8ecf1;
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
            background: #e74c3c;
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .modal-close:hover {
            transform: rotate(90deg);
            background: #c0392b;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-weight: 600;
            font-size: 13px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #1a237e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
        .form-group .color-picker {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding: 5px 0;
        }
        .form-group .color-picker input[type="color"] {
            width: 50px;
            height: 50px;
            padding: 2px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            cursor: pointer;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(39, 174, 96, 0.3);
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #1a237e;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.3);
            display: none;
            animation: slideUp 0.3s ease;
            max-width: 400px;
            z-index: 99999;
        }
        .toast.show { display: block; }
        .toast.error { background: #e74c3c; }
        .toast.success { background: #27ae60; }
        .toast .toast-icon { margin-right: 10px; }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
            color: #d5dbe3;
        }
        
        @media (max-width: 768px) {
            .header { padding: 10px 15px; flex-direction: column; align-items: stretch; }
            .header-left { justify-content: center; }
            .header-right { justify-content: center; }
            .container { padding: 15px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-header .actions { display: flex; flex-wrap: wrap; gap: 10px; }
            .page-header .actions .btn-add, .page-header .actions .btn-back, .page-header .actions .btn-bulk { flex: 1; justify-content: center; min-width: 120px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
            .modal-content { padding: 20px; margin: 15px; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .table-container { padding: 10px; }
            th, td { padding: 8px 10px; font-size: 12px; }
            .page-header .actions { flex-direction: column; }
            .page-header .actions .btn-add, .page-header .actions .btn-back, .page-header .actions .btn-bulk { width: 100%; }
        }
    </style>
</head>
<body>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <i class="fas fa-info-circle toast-icon"></i>
        <span id="toastMessage">Message</span>
    </div>

    <!-- ==========================================
    ADD/EDIT CATEGORY MODAL
    ========================================== -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-tag"></i> <span id="modalTitle">Add Category</span></h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="categoryForm" method="POST">
                <input type="hidden" id="categoryId" name="category_id" value="">
                <input type="hidden" id="formAction" name="ajax_action" value="add_category">
                
                <div class="form-group">
                    <label>Category Name *</label>
                    <input type="text" id="categoryName" name="category_name" placeholder="Enter category name" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="categoryDescription" name="category_description" placeholder="Enter category description"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Icon Class</label>
                        <input type="text" id="iconClass" name="icon_class" placeholder="fa-tag" value="fa-tag">
                        <small style="color:#95a5a6;font-size:11px;">Font Awesome icon class (e.g., fa-wheat, fa-bread-slice)</small>
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <div class="color-picker">
                            <input type="color" id="colorCode" name="color_code" value="#6c757d">
                            <input type="text" id="colorCodeText" placeholder="#6c757d" style="flex:1;" oninput="document.getElementById('colorCode').value=this.value">
                        </div>
                    </div>
                </div>
                
                <div class="form-group" id="statusGroup" style="display:none;">
                    <label>Status</label>
                    <select id="isActive" name="is_active">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> <span id="submitText">Add Category</span>
                </button>
            </form>
        </div>
    </div>

    <!-- ==========================================
    HEADER
    ========================================== -->
    <header class="header">
        <div class="header-left">
            <div class="header-logo">
                <h1>Palami Shoppers Kagoma</h1>
                <div class="subtitle">Category Management</div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <div class="user-name">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($userName); ?>
                </div>
                <div class="user-role">
                    <i class="fas fa-shield-alt"></i>
                    Admin
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </header>

    <!-- ==========================================
    MAIN CONTENT
    ========================================== -->
    <div class="container">
        <div class="page-header">
            <h2><i class="fas fa-tags"></i> Categories</h2>
            <div class="actions" style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="dashboard.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <button class="btn-bulk" onclick="bulkInsertCategories()">
                    <i class="fas fa-database"></i> Insert All Categories
                </button>
                <button class="btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus-circle"></i> Add Category
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon" style="background: #1a237e;">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="info">
                    <h4><?php echo count($categories); ?></h4>
                    <span>Total Categories</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon" style="background: #27ae60;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="info">
                    <h4><?php echo count(array_filter($categories, function($c) { return $c['is_active'] == 1; })); ?></h4>
                    <span>Active Categories</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon" style="background: #f39c12;">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="info">
                    <h4><?php 
                        $totalProducts = 0;
                        foreach ($categoryStats as $stat) {
                            $totalProducts += $stat['product_count'] ?? 0;
                        }
                        echo $totalProducts;
                    ?></h4>
                    <span>Total Products</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon" style="background: #e74c3c;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="info">
                    <h4><?php echo count(array_filter($categories, function($c) { return $c['is_active'] == 0; })); ?></h4>
                    <span>Inactive Categories</span>
                </div>
            </div>
        </div>

        <!-- Quick Category Reference -->
        <div style="background: white; border-radius: 12px; padding: 15px 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.06);">
            <h4 style="color: #1a237e; margin-bottom: 10px; font-size: 14px;">
                <i class="fas fa-list"></i> Quick Category Reference
            </h4>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <span style="background: #F39C12; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-wheat"></i> Cereals</span>
                <span style="background: #E67E22; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-bread-slice"></i> Bakery</span>
                <span style="background: #E91E63; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-spa"></i> Cosmetics</span>
                <span style="background: #3498DB; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-glass-whiskey"></i> Drinks</span>
                <span style="background: #F1C40F; color: #333; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-seedling"></i> Flours</span>
                <span style="background: #E74C3C; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-pepper-hot"></i> Spices</span>
                <span style="background: #2C3E50; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-utensils"></i> Kitchen Ware</span>
                <span style="background: #5DADE2; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-cheese"></i> Milk Products</span>
                <span style="background: #1ABC9C; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-soap"></i> Soap & Detergents</span>
                <span style="background: #8E44AD; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-wine-bottle"></i> Spirits & Wine</span>
                <span style="background: #2ECC71; color: white; padding: 4px 12px; border-radius: 20px; font-size: 11px;"><i class="fas fa-pen-fancy"></i> Stationery</span>
            </div>
        </div>

        <!-- Categories Table -->
        <div class="table-container">
            <?php if (empty($categoryStats)): ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <p>No categories found</p>
                    <p style="font-size:13px;">Click "Insert All Categories" to add all predefined categories, or "Add Category" to create one manually</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryStats as $cat): ?>
                        <tr>
                            <td>
                                <div class="category-badge">
                                    <span class="color-dot" style="background:<?php echo htmlspecialchars($cat['color_code'] ?? '#6c757d'); ?>;"></span>
                                    <i class="fas <?php echo htmlspecialchars($cat['icon_class'] ?? 'fa-tag'); ?>" style="color:<?php echo htmlspecialchars($cat['color_code'] ?? '#6c757d'); ?>;"></i>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($cat['category_description'] ?? '-'); ?></td>
                            <td><?php echo $cat['product_count'] ?? 0; ?></td>
                            <td><?php echo $cat['total_stock'] ?? 0; ?></td>
                            <td>
                                <span class="status-badge <?php echo $cat['is_active'] == 1 ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $cat['is_active'] == 1 ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-edit" onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (($cat['product_count'] ?? 0) == 0): ?>
                                <button class="action-btn btn-delete" onclick="deleteCategory(<?php echo $cat['category_id']; ?>, '<?php echo addslashes($cat['category_name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let toastTimeout;

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toast.className = 'toast';
            if (type === 'error') toast.classList.add('error');
            if (type === 'success') toast.classList.add('success');
            
            const icons = {
                'info': 'fa-info-circle',
                'error': 'fa-exclamation-circle',
                'success': 'fa-check-circle'
            };
            
            toast.querySelector('.toast-icon').className = 'toast-icon fas ' + (icons[type] || icons.info);
            toastMessage.textContent = message;
            toast.classList.add('show');
            
            clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Category';
            document.getElementById('submitText').textContent = 'Add Category';
            document.getElementById('formAction').value = 'add_category';
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('iconClass').value = 'fa-tag';
            document.getElementById('colorCode').value = '#6c757d';
            document.getElementById('colorCodeText').value = '#6c757d';
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('categoryModal').classList.add('show');
        }

        function editCategory(cat) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('submitText').textContent = 'Update Category';
            document.getElementById('formAction').value = 'edit_category';
            document.getElementById('categoryId').value = cat.category_id;
            document.getElementById('categoryName').value = cat.category_name;
            document.getElementById('categoryDescription').value = cat.category_description || '';
            document.getElementById('iconClass').value = cat.icon_class || 'fa-tag';
            document.getElementById('colorCode').value = cat.color_code || '#6c757d';
            document.getElementById('colorCodeText').value = cat.color_code || '#6c757d';
            document.getElementById('isActive').value = cat.is_active;
            document.getElementById('statusGroup').style.display = 'block';
            document.getElementById('categoryModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('categoryModal').classList.remove('show');
        }

        function deleteCategory(id, name) {
            if (!confirm(`Are you sure you want to delete category "${name}"? This cannot be undone.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax_action', 'delete_category');
            formData.append('category_id', id);
            
            fetch('categories.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('❌ Error: ' + error.message, 'error');
            });
        }

        function bulkInsertCategories() {
            if (!confirm('This will insert all predefined categories (Cereals, Bakery, Cosmetics, Drinks, Flours, Spices, Kitchen Ware, Milk Products, Soap, Spirits, Stationery). Categories that already exist will be skipped. Continue?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax_action', 'bulk_insert_categories');
            
            fetch('categories.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('❌ Error: ' + error.message, 'error');
            });
        }

        // Close modal on outside click
        document.getElementById('categoryModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Form submission
        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('categories.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('✅ ' + data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('❌ Error: ' + error.message, 'error');
            });
        });

        // Color picker sync
        document.getElementById('colorCode').addEventListener('input', function() {
            document.getElementById('colorCodeText').value = this.value;
        });
        document.getElementById('colorCodeText').addEventListener('input', function() {
            document.getElementById('colorCode').value = this.value;
        });
    </script>

</body>
</html>