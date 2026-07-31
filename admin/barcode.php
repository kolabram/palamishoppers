<?php
/**
 * Palami Shoppers Kagoma - Barcode Generator
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();

// Get all products for barcode generation
$products = [];
try {
    $stmt = $db->query("SELECT * FROM products WHERE is_active = 1 ORDER BY product_name");
    $products = $stmt->fetchAll();
} catch (Exception $e) {
    // Ignore
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Barcode Generator</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Same header and navigation styles as products.php */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; color: #333; }
        a { text-decoration: none; }
        
        .header { background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%); color: white; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 15px rgba(0,0,0,0.3); position: sticky; top: 0; z-index: 1000; flex-wrap: wrap; gap: 10px; }
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
        .badge-nav.danger { background: #e74c3c; color: white; animation: pulse-badge 2s infinite; }
        @keyframes pulse-badge { 0%,100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        
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
        .btn-barcode { background: #2196F3; color: white; }
        .btn-barcode:hover { background: #1976D2; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(33,150,243,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .barcode-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        .barcode-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); text-align: center; transition: all 0.3s; border: 2px solid transparent; }
        .barcode-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.12); border-color: #ffd700; }
        .barcode-card .barcode-image { font-size: 48px; color: #1a237e; margin: 10px 0; }
        .barcode-card .barcode-text { font-size: 20px; font-weight: bold; font-family: 'Courier New', monospace; letter-spacing: 2px; background: #f5f5f5; padding: 8px; border-radius: 4px; word-break: break-all; }
        .barcode-card .product-name { font-weight: 600; color: #1a237e; margin: 10px 0 5px; }
        .barcode-card .price { color: #27ae60; font-weight: bold; }
        .barcode-card .actions { margin-top: 15px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        
        .generator-box { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 30px; }
        .generator-box .form-group { margin-bottom: 15px; }
        .generator-box .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #555; }
        .generator-box .form-group input, .generator-box .form-group select { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; }
        .generator-box .form-group input:focus, .generator-box .form-group select:focus { border-color: #1a237e; outline: none; }
        
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
            .barcode-grid { grid-template-columns: 1fr 1fr; }
        }
        
        @media (max-width: 480px) {
            .barcode-grid { grid-template-columns: 1fr; }
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
                <button class="nav-link active">
                    <i class="fas fa-boxes"></i><span>Products</span><span class="arrow"><i class="fas fa-chevron-down"></i></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="products.php" class="dropdown-item"><i class="fas fa-list"></i> All Products</a></li>
                    <li><a href="products.php?action=add" class="dropdown-item"><i class="fas fa-plus-circle"></i> Add Product</a></li>
                    <li><a href="categories.php" class="dropdown-item"><i class="fas fa-tags"></i> Categories</a></li>
                    <li><a href="barcode.php" class="dropdown-item active"><i class="fas fa-barcode"></i> Generate Barcode</a></li>
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
            <li class="nav-item"><a href="inventory.php" class="nav-link"><i class="fas fa-warehouse"></i><span>Inventory</span></a></li>
        </ul>
    </nav>

    <!-- Content -->
    <div class="container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-barcode"></i> Barcode Generator</h2>
                <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / <a href="products.php">Products</a> / Barcode Generator</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-barcode" onclick="window.print()">
                    <i class="fas fa-print"></i> Print All
                </button>
                <a href="products.php?action=add" class="btn btn-success">
                    <i class="fas fa-plus-circle"></i> Add Product
                </a>
            </div>
        </div>

        <!-- Generator Box -->
        <div class="generator-box" id="generatorBox">
            <h3 style="margin-bottom:15px;color:#1a237e;">
                <i class="fas fa-magic"></i> Generate Custom Barcode
            </h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="customProductName" placeholder="Enter product name">
                </div>
                <div class="form-group">
                    <label>Price (UGX)</label>
                    <input type="number" id="customPrice" placeholder="0.00" step="0.01">
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                <button class="btn btn-barcode" onclick="generateCustomBarcode()">
                    <i class="fas fa-sync"></i> Generate Barcode
                </button>
                <button class="btn btn-outline" onclick="clearCustomBarcode()">
                    <i class="fas fa-undo"></i> Clear
                </button>
            </div>
            <div id="customBarcodeResult" style="margin-top:15px;display:none;"></div>
        </div>

        <!-- Product Barcodes -->
        <h3 style="color:#1a237e;margin-bottom:15px;">
            <i class="fas fa-boxes"></i> Product Barcodes
        </h3>
        <p style="color:#7f8c8d;margin-bottom:20px;">
            Click the print button on any card to print individual barcode labels.
        </p>

        <div class="barcode-grid" id="barcodeGrid">
            <?php if (empty($products)): ?>
                <div style="grid-column:1/-1;text-align:center;padding:40px;color:#95a5a6;">
                    <i class="fas fa-box-open" style="font-size:48px;display:block;margin-bottom:10px;"></i>
                    No products found. Add products first to generate barcodes.
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                <div class="barcode-card" id="barcode-<?php echo $product['product_id']; ?>">
                    <div class="barcode-image">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <div class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                    <div class="barcode-text"><?php echo htmlspecialchars($product['barcode']); ?></div>
                    <div class="price">UGX <?php echo number_format($product['unit_price'], 0); ?></div>
                    <div style="font-size:11px;color:#95a5a6;margin-top:5px;">
                        Stock: <?php echo $product['current_stock']; ?> units
                    </div>
                    <div class="actions">
                        <button class="btn btn-barcode btn-sm" onclick="printBarcodeCard('<?php echo $product['product_id']; ?>')">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="copyBarcode('<?php echo $product['barcode']; ?>')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ========================================
        // Print Barcode Card
        // ========================================
        function printBarcodeCard(productId) {
            const card = document.getElementById('barcode-' + productId);
            if (!card) return;
            
            const printWindow = window.open('', '_blank', 'width=400,height=350');
            const productName = card.querySelector('.product-name').textContent;
            const barcode = card.querySelector('.barcode-text').textContent;
            const price = card.querySelector('.price').textContent;
            
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
                            padding: 30px;
                            border: 2px dashed #1a237e;
                            border-radius: 8px;
                            background: white;
                        }
                        .barcode-image {
                            font-size: 64px;
                            color: #1a237e;
                            margin: 10px 0;
                        }
                        .barcode-text {
                            font-size: 28px;
                            font-weight: bold;
                            font-family: 'Courier New', monospace;
                            letter-spacing: 3px;
                            margin: 10px 0;
                            background: #f5f5f5;
                            padding: 10px;
                            border-radius: 4px;
                        }
                        .product-name {
                            font-size: 16px;
                            color: #1a237e;
                            font-weight: 600;
                            margin: 5px 0;
                        }
                        .price {
                            font-size: 20px;
                            color: #27ae60;
                            font-weight: bold;
                        }
                        .store-name {
                            font-size: 11px;
                            color: #95a5a6;
                            margin-top: 10px;
                        }
                        .store-location {
                            font-size: 10px;
                            color: #95a5a6;
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
                        <div class="store-location">Kagoma, Uganda</div>
                        <div class="barcode-image">
                            <i class="fas fa-barcode"></i>
                        </div>
                        <div class="product-name">${productName}</div>
                        <div class="barcode-text">${barcode}</div>
                        <div class="price">${price}</div>
                        <div style="font-size:10px;color:#95a5a6;margin-top:5px;">
                            <i class="fas fa-qrcode"></i> Scan me!
                        </div>
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
        // Copy Barcode
        // ========================================
        function copyBarcode(barcode) {
            navigator.clipboard.writeText(barcode).then(function() {
                showNotification('Barcode copied: ' + barcode, 'success');
            }).catch(function() {
                // Fallback
                const input = document.createElement('input');
                input.value = barcode;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showNotification('Barcode copied: ' + barcode, 'success');
            });
        }
        
        // ========================================
        // Generate Custom Barcode
        // ========================================
        function generateCustomBarcode() {
            const name = document.getElementById('customProductName').value.trim();
            const price = document.getElementById('customPrice').value.trim();
            
            if (!name) {
                alert('Please enter a product name');
                document.getElementById('customProductName').focus();
                return;
            }
            
            const timestamp = Date.now().toString();
            const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
            const barcode = 'PSK' + timestamp.slice(-8) + random;
            
            const resultDiv = document.getElementById('customBarcodeResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = `
                <div style="background:#f8f9fa;padding:20px;border-radius:8px;text-align:center;border:2px solid #ffd700;">
                    <div style="font-size:48px;color:#1a237e;margin:10px 0;">
                        <i class="fas fa-barcode"></i>
                    </div>
                    <div style="font-size:13px;color:#7f8c8d;">${name}</div>
                    <div style="font-size:24px;font-weight:bold;font-family:'Courier New',monospace;letter-spacing:2px;background:white;padding:10px;border-radius:4px;margin:10px 0;">
                        ${barcode}
                    </div>
                    ${price ? `<div style="font-size:18px;color:#27ae60;font-weight:bold;">UGX ${parseFloat(price).toLocaleString()}</div>` : ''}
                    <div style="margin-top:15px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-barcode btn-sm" onclick="copyBarcode('${barcode}')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="printCustomBarcode('${name}', '${barcode}', '${price}')">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-success btn-sm" onclick="useBarcode('${barcode}')">
                            <i class="fas fa-check"></i> Use for Product
                        </button>
                    </div>
                </div>
            `;
        }
        
        function clearCustomBarcode() {
            document.getElementById('customProductName').value = '';
            document.getElementById('customPrice').value = '';
            document.getElementById('customBarcodeResult').style.display = 'none';
            document.getElementById('customProductName').focus();
        }
        
        function printCustomBarcode(name, barcode, price) {
            const printWindow = window.open('', '_blank', 'width=400,height=350');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Barcode - ${name}</title>
                    <style>
                        body { font-family: Arial; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: white; }
                        .barcode-container { text-align: center; padding: 30px; border: 2px dashed #1a237e; border-radius: 8px; }
                        .barcode-image { font-size: 64px; color: #1a237e; margin: 10px 0; }
                        .barcode-text { font-size: 28px; font-weight: bold; font-family: 'Courier New', monospace; letter-spacing: 3px; margin: 10px 0; background: #f5f5f5; padding: 10px; border-radius: 4px; }
                        .product-name { font-size: 16px; color: #1a237e; font-weight: 600; }
                        .price { font-size: 20px; color: #27ae60; font-weight: bold; }
                        .store-name { font-size: 11px; color: #95a5a6; margin-top: 10px; }
                        @media print { body { margin: 0; } .barcode-container { border: none; } }
                    </style>
                </head>
                <body>
                    <div class="barcode-container">
                        <div class="store-name">Palami Shoppers Kagoma</div>
                        <div class="barcode-image"><i class="fas fa-barcode"></i></div>
                        <div class="product-name">${name}</div>
                        <div class="barcode-text">${barcode}</div>
                        ${price ? `<div class="price">UGX ${parseFloat(price).toLocaleString()}</div>` : ''}
                    </div>
                    <script>
                        window.onload = function() { window.print(); setTimeout(function() { window.close(); }, 1000); }
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        function useBarcode(barcode) {
            // Redirect to add product page with barcode pre-filled
            window.location.href = 'products.php?action=add&barcode=' + encodeURIComponent(barcode);
        }
        
        // ========================================
        // Notification
        // ========================================
        function showNotification(message, type) {
            const notification = document.createElement('div');
            const color = type === 'success' ? '#1a237e' : '#e74c3c';
            notification.style.cssText = `
                position: fixed; bottom: 20px; right: 20px; 
                background: ${color}; color: white; padding: 12px 24px; 
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
            }, 3000);
        }
        
        // ========================================
        // Mobile Navigation
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
            
            // Check for barcode parameter in URL
            const urlParams = new URLSearchParams(window.location.search);
            const barcode = urlParams.get('barcode');
            if (barcode) {
                document.getElementById('add_barcode').value = barcode;
                document.getElementById('barcodeStatus').innerHTML = '<span style="color:#27ae60;">✅ Barcode loaded: ' + barcode + '</span>';
            }
        });
    </script>

</body>
</html>