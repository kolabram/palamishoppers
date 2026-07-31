<?php
/**
 * Palami Shoppers Kagoma - Point of Sale
 * Supermarket Management System
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('cashier');

$db = Database::getInstance()->getConnection();
$productManager = new ProductManager();
$salesManager = new SalesManager();

$message = '';
$error = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['ajax_action']) {
            case 'lookup_product':
                $searchTerm = Security::sanitizeInput($_POST['barcode']);
                
                error_log("Searching for: " . $searchTerm);
                
                // Try exact barcode match first
                $stmt = $db->prepare("SELECT * FROM products WHERE barcode = ? AND is_active = 1");
                $stmt->execute([$searchTerm]);
                $product = $stmt->fetch();
                
                // If not found by barcode, search by product name or description
                if (!$product) {
                    $stmt = $db->prepare("SELECT * FROM products WHERE (product_name LIKE ? OR description LIKE ?) AND is_active = 1 LIMIT 1");
                    $searchPattern = '%' . $searchTerm . '%';
                    $stmt->execute([$searchPattern, $searchPattern]);
                    $product = $stmt->fetch();
                }
                
                // If still not found, try partial barcode match
                if (!$product) {
                    $stmt = $db->prepare("SELECT * FROM products WHERE barcode LIKE ? AND is_active = 1 LIMIT 1");
                    $stmt->execute(['%' . $searchTerm . '%']);
                    $product = $stmt->fetch();
                }
                
                if ($product) {
                    echo json_encode([
                        'success' => true, 
                        'product' => [
                            'product_id' => $product['product_id'],
                            'product_name' => $product['product_name'],
                            'barcode' => $product['barcode'],
                            'unit_price' => $product['unit_price'],
                            'current_stock' => $product['current_stock'],
                            'category' => $product['category'] ?? 'Uncategorized',
                            'description' => $product['description'] ?? ''
                        ]
                    ]);
                } else {
                    // Return similar products suggestion
                    $stmt = $db->prepare("SELECT product_name FROM products WHERE product_name LIKE ? AND is_active = 1 LIMIT 5");
                    $stmt->execute(['%' . $searchTerm . '%']);
                    $suggestions = $stmt->fetchAll();
                    
                    $suggestionText = '';
                    if (!empty($suggestions)) {
                        $names = array_column($suggestions, 'product_name');
                        $suggestionText = ' Did you mean: ' . implode(', ', $names) . '?';
                    }
                    
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Product not found' . $suggestionText
                    ]);
                }
                break;
                
            case 'get_all_products':
                $stmt = $db->query("SELECT product_id, product_name, barcode, is_active FROM products LIMIT 20");
                $products = $stmt->fetchAll();
                echo json_encode(['success' => true, 'products' => $products]);
                break;
                
            case 'complete_sale':
                $cart = json_decode($_POST['cart'], true);
                $customerType = Security::sanitizeInput($_POST['customer_type'] ?? 'walk_in');
                $customerName = Security::sanitizeInput($_POST['customer_name'] ?? 'Walk-in Customer');
                $customerEmail = Security::sanitizeInput($_POST['customer_email'] ?? '');
                $customerPhone = Security::sanitizeInput($_POST['customer_phone'] ?? '');
                $paymentMethod = Security::sanitizeInput($_POST['payment_method'] ?? 'cash');
                $discount = floatval($_POST['discount'] ?? 0);
                $tax = floatval($_POST['tax'] ?? 0);
                $cashDiscount = floatval($_POST['cash_discount'] ?? 0);
                $tradeDiscount = floatval($_POST['trade_discount'] ?? 0);
                $amountTendered = floatval($_POST['amount_tendered'] ?? 0);
                
                // Validate cart
                if (empty($cart)) {
                    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
                    break;
                }
                
                // Get user ID
                $userId = isset($_SESSION['palami_user_id']) ? $_SESSION['palami_user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
                
                if ($userId == 0) {
                    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
                    break;
                }
                
                // Calculate totals
                $subtotal = 0;
                foreach ($cart as $item) {
                    $subtotal += $item['quantity'] * $item['unit_price'];
                }
                
                $totalDiscount = $discount + $cashDiscount + $tradeDiscount;
                $grandTotal = $subtotal - $totalDiscount + $tax;
                $balance = $amountTendered - $grandTotal;
                
                // Generate invoice number
                $invoiceNumber = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
                
                // Start transaction
                $db->beginTransaction();
                
                try {
                    // Check sales table columns
                    $salesColumns = [];
                    try {
                        $colCheck = $db->query("SHOW COLUMNS FROM sales");
                        while ($col = $colCheck->fetch()) {
                            $salesColumns[] = $col['Field'];
                        }
                    } catch (Exception $e) {
                        $salesColumns = ['invoice_number', 'user_id', 'customer_name', 'customer_email', 'customer_phone', 
                                       'payment_method', 'subtotal', 'discount', 'tax', 'grand_total', 'sale_date'];
                    }
                    
                    // Build INSERT query dynamically based on existing columns
                    $insertFields = ['invoice_number', 'user_id', 'customer_name', 'customer_email', 'customer_phone', 
                                    'payment_method', 'subtotal', 'discount', 'tax', 'grand_total', 'sale_date'];
                    $insertValues = [$invoiceNumber, $userId, $customerName, $customerEmail, $customerPhone, 
                                    $paymentMethod, $subtotal, $totalDiscount, $tax, $grandTotal, date('Y-m-d H:i:s')];
                    
                    if (in_array('customer_type', $salesColumns)) {
                        $insertFields[] = 'customer_type';
                        $insertValues[] = $customerType;
                    }
                    
                    if (in_array('cash_discount', $salesColumns)) {
                        $insertFields[] = 'cash_discount';
                        $insertValues[] = $cashDiscount;
                    }
                    
                    if (in_array('trade_discount', $salesColumns)) {
                        $insertFields[] = 'trade_discount';
                        $insertValues[] = $tradeDiscount;
                    }
                    
                    if (in_array('amount_tendered', $salesColumns)) {
                        $insertFields[] = 'amount_tendered';
                        $insertValues[] = $amountTendered;
                    }
                    
                    if (in_array('balance', $salesColumns)) {
                        $insertFields[] = 'balance';
                        $insertValues[] = $balance;
                    }
                    
                    if (in_array('status', $salesColumns)) {
                        $insertFields[] = 'status';
                        $insertValues[] = 'completed';
                    }
                    
                    $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
                    $fieldsStr = implode(', ', $insertFields);
                    $sql = "INSERT INTO sales ($fieldsStr) VALUES ($placeholders)";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($insertValues);
                    
                    $saleId = $db->lastInsertId();
                    
                    // Check sale_items table columns
                    $itemColumns = [];
                    try {
                        $colCheck = $db->query("SHOW COLUMNS FROM sale_items");
                        while ($col = $colCheck->fetch()) {
                            $itemColumns[] = $col['Field'];
                        }
                    } catch (Exception $e) {
                        $itemColumns = ['sale_id', 'product_id', 'quantity', 'unit_price'];
                    }
                    
                    // Build INSERT for sale items dynamically
                    $itemInsertFields = ['sale_id', 'product_id'];
                    $itemInsertPlaceholders = ['?', '?'];
                    $itemValues = [$saleId];
                    
                    if (in_array('product_name', $itemColumns)) {
                        $itemInsertFields[] = 'product_name';
                        $itemInsertPlaceholders[] = '?';
                    }
                    
                    if (in_array('quantity', $itemColumns)) {
                        $itemInsertFields[] = 'quantity';
                        $itemInsertPlaceholders[] = '?';
                    }
                    
                    if (in_array('unit_price', $itemColumns)) {
                        $itemInsertFields[] = 'unit_price';
                        $itemInsertPlaceholders[] = '?';
                    }
                    
                    if (in_array('total_price', $itemColumns)) {
                        $itemInsertFields[] = 'total_price';
                        $itemInsertPlaceholders[] = '?';
                    }
                    
                    $itemFieldsStr = implode(', ', $itemInsertFields);
                    $itemPlaceholdersStr = implode(', ', $itemInsertPlaceholders);
                    $itemSql = "INSERT INTO sale_items ($itemFieldsStr) VALUES ($itemPlaceholdersStr)";
                    
                    $stmt = $db->prepare($itemSql);
                    
                    foreach ($cart as $item) {
                        $itemValues = [$saleId, $item['product_id']];
                        
                        if (in_array('product_name', $itemColumns)) {
                            $itemValues[] = $item['product_name'];
                        }
                        
                        if (in_array('quantity', $itemColumns)) {
                            $itemValues[] = $item['quantity'];
                        }
                        
                        if (in_array('unit_price', $itemColumns)) {
                            $itemValues[] = $item['unit_price'];
                        }
                        
                        if (in_array('total_price', $itemColumns)) {
                            $itemValues[] = $item['quantity'] * $item['unit_price'];
                        }
                        
                        $stmt->execute($itemValues);
                        
                        // Update product stock
                        $updateStock = $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?");
                        $updateStock->execute([$item['quantity'], $item['product_id']]);
                    }
                    
                    $db->commit();
                    
                    // Return success response with full sale data
                    echo json_encode([
                        'success' => true,
                        'sale' => [
                            'sale_id' => $saleId,
                            'invoice_number' => $invoiceNumber,
                            'grand_total' => $grandTotal,
                            'subtotal' => $subtotal,
                            'discount' => $totalDiscount,
                            'tax' => $tax,
                            'payment_method' => $paymentMethod,
                            'customer_type' => $customerType,
                            'customer_name' => $customerName,
                            'customer_email' => $customerEmail,
                            'customer_phone' => $customerPhone,
                            'cashier_name' => $_SESSION['palami_full_name'] ?? 'Cashier',
                            'sale_date' => date('Y-m-d H:i:s'),
                            'items' => $cart
                        ]
                    ]);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Sale error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
                break;
        }
    } catch (Exception $e) {
        error_log("POS AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Get current user info
$userName = $_SESSION['palami_full_name'] ?? $_SESSION['full_name'] ?? 'Cashier';
$csrfToken = SessionManager::generateCSRFToken();

// Payment methods configuration
$paymentMethods = [
    'cash' => 'Cash',
    'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'mobile_money' => 'Mobile Money'
];

$mobileProviders = [
    'mtn' => 'MTN Mobile Money',
    'airtel' => 'Airtel Money'
];

// Customer types
$customerTypes = [
    'walk_in' => 'Walk-in Customer',
    'online' => 'Online Order'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Point of Sale</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- html2pdf for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f0f2f5; 
            min-height: 100vh; 
            color: #333; 
        }
        a { text-decoration: none; }
        
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
        
        .container { 
            display: flex; 
            gap: 25px; 
            padding: 25px; 
            max-width: 1600px; 
            margin: 0 auto; 
            min-height: calc(100vh - 80px);
        }
        
        .left-panel { 
            flex: 2; 
            background: white; 
            border-radius: 16px; 
            padding: 25px; 
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
        }
        
        .search-hint {
            font-size: 12px;
            color: #95a5a6;
            margin-bottom: 15px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .search-hint i { margin-right: 6px; }
        .search-hint kbd {
            background: #e8ecf1;
            padding: 1px 8px;
            border-radius: 3px;
            font-size: 11px;
            border: 1px solid #ddd;
        }
        
        .barcode-section {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
        }
        .barcode-section input {
            flex: 1;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 18px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        .barcode-section input:focus {
            border-color: #1a237e;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        .barcode-section button {
            padding: 14px 30px;
            background: linear-gradient(135deg, #1a237e, #0d47a1);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .barcode-section button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(26, 35, 126, 0.3);
        }
        .barcode-section .clear-btn {
            background: #e74c3c;
        }
        .barcode-section .clear-btn:hover {
            box-shadow: 0 5px 20px rgba(231, 76, 60, 0.3);
        }
        
        .product-info {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: none;
            border-left: 4px solid #1a237e;
            animation: slideDown 0.3s ease;
        }
        .product-info.visible { display: block; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .product-info .product-name { 
            font-size: 20px; 
            font-weight: 700; 
            color: #1a237e; 
            margin-bottom: 5px;
        }
        .product-info .product-details {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin: 10px 0;
        }
        .product-info .product-details span {
            background: white;
            padding: 5px 15px;
            border-radius: 6px;
            font-size: 14px;
        }
        .product-info .product-details .price { 
            color: #1a237e; 
            font-weight: 700;
            background: #ffd700;
        }
        .product-info .product-details .stock {
            color: #27ae60;
        }
        .product-info .quantity-section {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .product-info .quantity-section label {
            font-weight: 600;
            color: #555;
        }
        .product-info .quantity-section input {
            width: 80px;
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            text-align: center;
        }
        .product-info .quantity-section button {
            padding: 8px 25px;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        .product-info .quantity-section button:hover {
            transform: scale(1.05);
        }
        
        .cart-section {
            margin-top: 20px;
        }
        .cart-section .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .cart-section .cart-header h3 {
            color: #1a237e;
            font-size: 18px;
        }
        .cart-section .cart-header .item-count {
            background: #e3f2fd;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            color: #1a237e;
        }
        .cart-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }
        .cart-table th {
            background: #e3f2fd;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cart-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e3f2fd;
            font-size: 14px;
            vertical-align: middle;
        }
        .cart-table tr:hover td {
            background: #f5f9ff;
        }
        .cart-table .remove-btn {
            color: #e74c3c;
            cursor: pointer;
            padding: 5px 10px;
            border: none;
            background: none;
            font-size: 16px;
            transition: all 0.3s;
        }
        .cart-table .remove-btn:hover {
            color: #c0392b;
            transform: scale(1.2);
        }
        
        /* Quantity Control Buttons in Cart */
        .qty-control {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .qty-control button {
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 50%;
            background: #1a237e;
            color: white;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qty-control button:hover {
            transform: scale(1.1);
            background: #0d47a1;
        }
        .qty-control button:active {
            transform: scale(0.9);
        }
        .qty-control button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .qty-control .qty-number {
            min-width: 30px;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
            color: #1a237e;
        }
        .qty-control .qty-minus {
            background: #e74c3c;
        }
        .qty-control .qty-minus:hover {
            background: #c0392b;
        }
        .qty-control .qty-plus {
            background: #27ae60;
        }
        .qty-control .qty-plus:hover {
            background: #229954;
        }
        
        .cart-total {
            text-align: right;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-top: 15px;
        }
        .cart-total h3 {
            font-size: 28px;
            color: #1a237e;
        }
        .cart-total .label {
            color: #7f8c8d;
            font-size: 14px;
        }
        .empty-cart {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        .empty-cart i {
            font-size: 48px;
            display: block;
            margin-bottom: 15px;
            color: #bbdefb;
        }
        
        .right-panel { 
            flex: 1; 
            background: white; 
            padding: 25px; 
            border-radius: 16px; 
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 85px;
            min-width: 350px;
        }
        .right-panel h3 {
            color: #1a237e;
            margin-bottom: 20px;
            font-size: 18px;
            border-bottom: 2px solid #e3f2fd;
            padding-bottom: 15px;
        }
        .right-panel h3 i {
            color: #ffd700;
            margin-right: 10px;
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
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
            background: #f8f9fa;
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: #1a237e;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        .form-group .input-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group .input-group span {
            background: #e3f2fd;
            padding: 10px 15px;
            border-radius: 8px 0 0 8px;
            font-weight: 600;
            color: #1a237e;
        }
        .form-group .input-group input {
            border-radius: 0 8px 8px 0;
            flex: 1;
        }
        
        .customer-type-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .customer-type-row select {
            flex: 1;
        }
        .customer-type-row .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-walk-in {
            background: #e3f2fd;
            color: #1a237e;
        }
        .badge-online {
            background: #fff3e0;
            color: #e65100;
        }
        
        .payment-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
        }
        .payment-summary .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        .payment-summary .row:last-child {
            border-bottom: none;
        }
        .payment-summary .total-row {
            font-weight: 700;
            font-size: 20px;
            color: #1a237e;
            padding: 12px 0;
        }
        .payment-summary .total-row .amount {
            color: #ffd700;
        }
        
        .btn-complete {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn-complete:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 25px rgba(39, 174, 96, 0.4);
        }
        .btn-complete:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
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
            z-index: 9999;
        }
        .toast.show { display: block; }
        .toast.error { background: #e74c3c; }
        .toast.success { background: #27ae60; }
        .toast .toast-icon { margin-right: 10px; }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* ========================================
                   RECEIPT MODAL - After Sale Popup
                ======================================== */
        .receipt-modal {
            display: none;
            position: fixed;
            z-index: 99999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: modalFadeIn 0.3s ease;
        }

        .receipt-modal.show {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .receipt-modal-content {
            background: #ffffff;
            border-radius: 20px;
            width: 95%;
            max-width: 580px;
            max-height: 92vh;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
            animation: modalSlideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Modal Header */
        .receipt-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            background: linear-gradient(135deg, #0d47a1, #1a237e);
            color: white;
            border-bottom: 3px solid #ffd700;
            flex-wrap: wrap;
            gap: 10px;
        }

        .receipt-modal-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 700;
        }

        .receipt-modal-title i {
            color: #ffd700;
            font-size: 24px;
        }

        .receipt-modal-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(46, 204, 113, 0.2);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }

        .receipt-modal-close {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .receipt-modal-close:hover {
            background: #e74c3c;
            transform: rotate(90deg);
        }

        /* Modal Body */
        .receipt-modal-body {
            padding: 20px 24px;
            background: #f8f9fa;
            max-height: 55vh;
            overflow-y: auto;
        }

        .receipt-print-content {
            background: white;
            padding: 20px;
            border-radius: 12px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre-wrap;
            border: 2px solid #e8ecf1;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.04);
            min-height: 150px;
            word-break: break-word;
        }

        /* Scrollbar styling */
        .receipt-modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .receipt-modal-body::-webkit-scrollbar-track {
            background: #f0f0f0;
            border-radius: 3px;
        }

        .receipt-modal-body::-webkit-scrollbar-thumb {
            background: #1a237e;
            border-radius: 3px;
        }

        /* Modal Footer */
        .receipt-modal-footer {
            padding: 16px 24px;
            background: white;
            border-top: 2px solid #e8ecf1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .receipt-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-receipt {
            padding: 10px 22px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            justify-content: center;
            min-width: 100px;
        }

        .btn-receipt:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-receipt:active {
            transform: translateY(0px);
        }

        .btn-print {
            background: linear-gradient(135deg, #1a237e, #0d47a1);
            color: white;
        }

        .btn-print:hover {
            background: linear-gradient(135deg, #283593, #1565c0);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            color: white;
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #e74c3c, #f44336);
        }

        .btn-email {
            background: linear-gradient(135deg, #e67e22, #f39c12);
            color: white;
        }

        .btn-email:hover {
            background: linear-gradient(135deg, #d35400, #e67e22);
        }

        .btn-close {
            background: #95a5a6;
            color: white;
        }

        .btn-close:hover {
            background: #7f8c8d;
        }

        .receipt-shortcuts {
            display: flex;
            gap: 16px;
            justify-content: center;
            font-size: 12px;
            color: #95a5a6;
            padding-top: 4px;
            border-top: 1px solid #f0f0f0;
        }

        .receipt-shortcuts kbd {
            background: #f0f0f0;
            padding: 2px 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            font-size: 11px;
            font-weight: 600;
            color: #555;
        }

        /* Success animation */
        .receipt-success-animation {
            display: none;
            text-align: center;
            padding: 10px 0 5px 0;
        }

        .receipt-success-animation.show {
            display: block;
        }

        .receipt-success-animation .checkmark {
            font-size: 36px;
            color: #2ecc71;
            animation: checkmarkBounce 0.6s ease;
        }

        @keyframes checkmarkBounce {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.3);
                opacity: 1;
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                transform: scale(1);
            }
        }

        @media (max-width: 1024px) {
            .container { flex-direction: column; }
            .right-panel { position: static; min-width: unset; }
            .barcode-section { flex-wrap: wrap; }
        }
        
        @media (max-width: 768px) {
            .header { padding: 10px 15px; flex-direction: column; align-items: stretch; }
            .header-left { justify-content: center; }
            .header-logo { flex-direction: column; text-align: center; }
            .header-logo h1 { font-size: 16px; }
            .header-right { justify-content: center; }
            .container { padding: 10px; }
            .left-panel, .right-panel { padding: 15px; }
            .barcode-section input { font-size: 16px; padding: 12px; }
            .barcode-section button { padding: 12px 20px; font-size: 14px; }
            .cart-table { font-size: 13px; }
            .cart-table th, .cart-table td { padding: 8px 10px; }
            .cart-total h3 { font-size: 22px; }
            .customer-type-row { flex-direction: column; align-items: stretch; }
            
            .receipt-modal-content {
                width: 98%;
                max-height: 96vh;
                border-radius: 12px;
            }
            .receipt-modal-header {
                padding: 14px 16px;
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            .receipt-modal-title {
                font-size: 16px;
                justify-content: center;
            }
            .receipt-modal-badge {
                justify-content: center;
            }
            .receipt-modal-close {
                position: absolute;
                top: 12px;
                right: 12px;
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            .receipt-modal-body {
                padding: 12px 14px;
                max-height: 45vh;
            }
            .receipt-print-content {
                padding: 12px;
                font-size: 10px;
                line-height: 1.4;
            }
            .receipt-modal-footer {
                padding: 12px 14px;
            }
            .receipt-actions {
                flex-direction: column;
            }
            .btn-receipt {
                width: 100%;
                padding: 12px;
                font-size: 13px;
            }
            .receipt-shortcuts {
                flex-wrap: wrap;
                justify-content: center;
                gap: 8px;
            }
            
            .qty-control button {
                width: 24px;
                height: 24px;
                font-size: 12px;
            }
            .qty-control .qty-number {
                min-width: 24px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .header-logo h1 { font-size: 14px; }
            .barcode-section { flex-direction: column; }
            .barcode-section button { width: 100%; justify-content: center; }
            .cart-table { font-size: 12px; }
            .cart-table th, .cart-table td { padding: 6px 8px; }
            .receipt-print-content { font-size: 9px; padding: 8px; }
            
            .qty-control button {
                width: 20px;
                height: 20px;
                font-size: 10px;
            }
            .qty-control .qty-number {
                min-width: 20px;
                font-size: 12px;
            }
        }
        
        /* Print styles for receipt - ONE PAGE */
        @media print {
            html, body {
                margin: 0;
                padding: 0;
                height: auto;
                background: white;
            }
            
            body * {
                visibility: hidden;
            }

            .receipt-modal,
            .receipt-modal.show {
                display: block !important;
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                background: white !important;
                backdrop-filter: none !important;
                z-index: 999999 !important;
                animation: none !important;
                overflow: visible !important;
            }

            .receipt-modal-content {
                max-width: 100% !important;
                max-height: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 10px !important;
                width: 100% !important;
                height: auto !important;
                background: white !important;
                animation: none !important;
                overflow: visible !important;
            }

            .receipt-modal-header,
            .receipt-modal-footer,
            .receipt-modal-close,
            .receipt-modal-badge {
                display: none !important;
            }

            .receipt-modal-body {
                max-height: none !important;
                overflow: visible !important;
                padding: 0 !important;
                background: white !important;
            }

            .receipt-print-content {
                border: none !important;
                background: white !important;
                box-shadow: none !important;
                padding: 5px !important;
                font-size: 10px !important;
                line-height: 1.4 !important;
                min-height: auto !important;
                white-space: pre-wrap !important;
                word-wrap: break-word !important;
                overflow: visible !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }

            .receipt-modal-content *,
            .receipt-print-content * {
                visibility: visible !important;
            }

            .receipt-success-animation {
                display: none !important;
            }
            
            .receipt-print-content pre {
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                margin: 0 !important;
                padding: 0 !important;
            }
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
    RECEIPT MODAL - After Sale Popup
    ========================================== -->
    <div id="receiptModal" class="receipt-modal">
        <div class="receipt-modal-content">
            <!-- Modal Header -->
            <div class="receipt-modal-header">
                <div class="receipt-modal-title">
                    <i class="fas fa-receipt"></i>
                    <span>SALE RECEIPT</span>
                </div>
                <div class="receipt-modal-badge">
                    <i class="fas fa-check-circle"></i> COMPLETED
                </div>
                <button class="receipt-modal-close" onclick="closeReceipt()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Receipt Body -->
            <div class="receipt-modal-body">
                <div class="receipt-success-animation show">
                    <div class="checkmark">✅</div>
                </div>
                <div id="receiptPrintContent" class="receipt-print-content"></div>
            </div>

            <!-- Receipt Footer / Actions -->
            <div class="receipt-modal-footer">
                <div class="receipt-actions">
                    <button class="btn-receipt btn-print" onclick="printReceipt()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button class="btn-receipt btn-pdf" onclick="downloadPDFReceipt()">
                        <i class="fas fa-file-pdf"></i> Download PDF
                    </button>
                    <button class="btn-receipt btn-email" onclick="emailReceipt()">
                        <i class="fas fa-envelope"></i> Email
                    </button>
                    <button class="btn-receipt btn-close" onclick="closeReceipt()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <div class="receipt-shortcuts">
                    <span><kbd>Ctrl+P</kbd> Print</span>
                    <span><kbd>Ctrl+D</kbd> Download PDF</span>
                    <span><kbd>Esc</kbd> Close</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="header-left">
            <div class="header-logo">
                <img src="../assets/images/logo.png" alt="Palami Shoppers" onerror="this.style.display='none'">
                <div>
                    <h1>Palami Shoppers Kagoma</h1>
                    <div class="subtitle">Point of Sale System</div>
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <div class="user-name">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($userName); ?>
                </div>
                <div class="user-role">
                    <i class="fas fa-cash-register"></i>
                    Cashier
                </div>
            </div>
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">

        <!-- Left Panel -->
        <div class="left-panel">
            
            <div class="search-hint">
                <i class="fas fa-info-circle"></i>
                Search by: <kbd>Barcode</kbd>, <kbd>Product Name</kbd>, or <kbd>Description</kbd>
                <span style="float:right;">
                    <kbd>F1</kbd> Focus <kbd>Enter</kbd> Search
                </span>
            </div>

            <div class="barcode-section">
                <input type="text" id="barcodeInput" placeholder="🔍 Scan barcode or type product name..." autofocus>
                <button onclick="lookupProduct()">
                    <i class="fas fa-search"></i> Search
                </button>
                <button class="clear-btn" onclick="clearCart()">
                    <i class="fas fa-trash"></i> Clear Cart
                </button>
            </div>

            <div id="productInfo" class="product-info">
                <div class="product-name" id="productName">Product Name</div>
                <div class="product-details">
                    <span class="price" id="productPrice">UGX 0.00</span>
                    <span class="stock" id="productStock">Stock: 0</span>
                    <span class="category" id="productCategory">Category: -</span>
                </div>
                <div class="quantity-section">
                    <label>Qty:</label>
                    <input type="number" id="quantityInput" value="1" min="1">
                    <button onclick="addToCart()">
                        <i class="fas fa-cart-plus"></i> Add to Cart
                    </button>
                </div>
            </div>

            <div class="cart-section">
                <div class="cart-header">
                    <h3><i class="fas fa-shopping-cart" style="color:#ffd700;"></i> Cart</h3>
                    <span class="item-count" id="itemCount">0 items</span>
                </div>
                <div id="cartContainer">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th style="width:35%;">Product</th>
                                <th style="width:25%;">Qty</th>
                                <th style="width:20%;">Price</th>
                                <th style="width:15%;">Total</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartBody">
                            <tr>
                                <td colspan="5">
                                    <div class="empty-cart">
                                        <i class="fas fa-shopping-basket"></i>
                                        <p>Your cart is empty</p>
                                        <p style="font-size:13px;">Search for products to add them</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="cart-total">
                    <span class="label">Total:</span>
                    <h3>UGX <span id="cartTotal">0.00</span></h3>
                </div>
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel">
            <h3><i class="fas fa-credit-card"></i> Payment Details</h3>

            <div class="form-group">
                <label>Customer Type</label>
                <div class="customer-type-row">
                    <select id="customerType" onchange="updateCustomerType()">
                        <option value="walk_in">🏪 Walk-in Customer</option>
                        <option value="online">📱 Online Order</option>
                    </select>
                    <span class="badge badge-walk-in" id="customerTypeBadge">WALK-IN</span>
                </div>
            </div>

            <div class="form-group">
                <label>Customer Name</label>
                <input type="text" id="customerName" placeholder="Walk-in Customer">
            </div>

            <div class="form-group">
                <label>Customer Email</label>
                <input type="email" id="customerEmail" placeholder="customer@example.com">
            </div>

            <div class="form-group">
                <label>Customer Phone</label>
                <input type="text" id="customerPhone" placeholder="+256 700 000 000">
            </div>

            <div class="form-group">
                <label>Payment Method</label>
                <select id="paymentMethod">
                    <option value="cash">💵 Cash</option>
                    <option value="credit_card">💳 Credit Card</option>
                    <option value="debit_card">💳 Debit Card</option>
                    <option value="mobile_money">📱 Mobile Money</option>
                </select>
            </div>

            <div class="form-group">
                <label>Discount (UGX)</label>
                <div class="input-group">
                    <span>UGX</span>
                    <input type="number" id="discount" value="0" min="0" step="100" oninput="updateSummary()">
                </div>
            </div>

            <div class="form-group">
                <label>Tax (UGX)</label>
                <div class="input-group">
                    <span>UGX</span>
                    <input type="number" id="tax" value="0" min="0" step="100" oninput="updateSummary()">
                </div>
            </div>

            <div class="payment-summary">
                <div class="row">
                    <span>Subtotal:</span>
                    <span id="summarySubtotal">UGX 0.00</span>
                </div>
                <div class="row">
                    <span>Discount:</span>
                    <span id="summaryDiscount" style="color:#e74c3c;">- UGX 0.00</span>
                </div>
                <div class="row">
                    <span>Tax:</span>
                    <span id="summaryTax">UGX 0.00</span>
                </div>
                <div class="row total-row">
                    <span>Grand Total:</span>
                    <span class="amount" id="summaryTotal">UGX 0.00</span>
                </div>
            </div>

            <button class="btn-complete" id="completeBtn" onclick="completeSale()">
                <i class="fas fa-check-circle"></i> Complete Sale
            </button>
        </div>

    </div>

    <script>
        let cart = [];
        let currentProduct = null;
        let toastTimeout;
        let lastSaleData = null;

        // ========================================
        // TOAST FUNCTIONS
        // ========================================
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

        // ========================================
        // CUSTOMER TYPE
        // ========================================
        function updateCustomerType() {
            const type = document.getElementById('customerType').value;
            const badge = document.getElementById('customerTypeBadge');
            
            if (type === 'walk_in') {
                badge.className = 'badge badge-walk-in';
                badge.textContent = 'WALK-IN';
                document.getElementById('customerName').placeholder = 'Walk-in Customer';
                document.getElementById('customerName').value = '';
            } else {
                badge.className = 'badge badge-online';
                badge.textContent = 'ONLINE ORDER';
                document.getElementById('customerName').placeholder = 'Online Customer Name';
                document.getElementById('customerName').value = 'Online Customer';
            }
        }

        // ========================================
        // PRODUCT SEARCH
        // ========================================
        document.getElementById('barcodeInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookupProduct();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('barcodeInput').focus();
            updateCustomerType();
        });

        function lookupProduct() {
            const searchTerm = document.getElementById('barcodeInput').value.trim();
            if (!searchTerm) {
                showToast('Please enter a barcode or product name', 'error');
                return;
            }

            const btn = document.querySelector('.barcode-section button:not(.clear-btn)');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('ajax_action', 'lookup_product');
            formData.append('barcode', searchTerm);

            fetch('pos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    currentProduct = data.product;
                    document.getElementById('productName').textContent = currentProduct.product_name;
                    document.getElementById('productPrice').textContent = 'UGX ' + parseFloat(currentProduct.unit_price).toLocaleString();
                    document.getElementById('productStock').textContent = 'Stock: ' + currentProduct.current_stock;
                    document.getElementById('productCategory').textContent = 'Category: ' + (currentProduct.category || 'Uncategorized');
                    document.getElementById('productInfo').classList.add('visible');
                    document.getElementById('quantityInput').value = 1;
                    document.getElementById('barcodeInput').value = '';
                    document.getElementById('quantityInput').focus();
                    showToast('✅ Found: ' + currentProduct.product_name, 'success');
                } else {
                    showToast('❌ ' + data.message, 'error');
                    document.getElementById('barcodeInput').value = '';
                    document.getElementById('barcodeInput').focus();
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                showToast('Error searching: ' + error.message, 'error');
                document.getElementById('barcodeInput').value = '';
                document.getElementById('barcodeInput').focus();
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }

        // ========================================
        // CART FUNCTIONS WITH QUANTITY CONTROLS
        // ========================================
        function addToCart() {
            if (!currentProduct) {
                showToast('Please search for a product first', 'error');
                return;
            }

            const quantity = parseInt(document.getElementById('quantityInput').value);
            if (quantity < 1) {
                showToast('Quantity must be at least 1', 'error');
                return;
            }

            if (quantity > currentProduct.current_stock) {
                showToast('⚠️ Insufficient stock! Available: ' + currentProduct.current_stock, 'error');
                return;
            }

            const existingItem = cart.find(item => item.product_id === currentProduct.product_id);
            if (existingItem) {
                const newQty = existingItem.quantity + quantity;
                if (newQty > currentProduct.current_stock) {
                    showToast('⚠️ Total quantity exceeds available stock!', 'error');
                    return;
                }
                existingItem.quantity = newQty;
            } else {
                cart.push({
                    product_id: currentProduct.product_id,
                    product_name: currentProduct.product_name,
                    quantity: quantity,
                    unit_price: parseFloat(currentProduct.unit_price),
                    max_stock: currentProduct.current_stock
                });
            }

            updateCart();
            document.getElementById('productInfo').classList.remove('visible');
            document.getElementById('barcodeInput').focus();
            showToast('✅ Added ' + quantity + 'x ' + currentProduct.product_name + ' to cart', 'success');
        }

        function updateQuantity(index, change) {
            const item = cart[index];
            if (!item) return;
            
            const newQty = item.quantity + change;
            
            // Check minimum quantity
            if (newQty < 1) {
                // Remove item if quantity goes below 1
                if (confirm('Remove "' + item.product_name + '" from cart?')) {
                    cart.splice(index, 1);
                    updateCart();
                    showToast('🗑️ Removed ' + item.product_name + ' from cart', 'info');
                }
                return;
            }
            
            // Check maximum stock
            if (newQty > item.max_stock) {
                showToast('⚠️ Insufficient stock! Available: ' + item.max_stock, 'error');
                return;
            }
            
            item.quantity = newQty;
            updateCart();
        }

        function removeFromCart(index) {
            if (!confirm('Remove this item from cart?')) return;
            const removed = cart[index];
            cart.splice(index, 1);
            updateCart();
            showToast('🗑️ Removed ' + removed.product_name + ' from cart', 'info');
        }

        function updateCart() {
            const tbody = document.getElementById('cartBody');
            const totalSpan = document.getElementById('cartTotal');
            const itemCountSpan = document.getElementById('itemCount');
            
            let total = 0;
            let itemCount = 0;

            if (cart.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5">
                            <div class="empty-cart">
                                <i class="fas fa-shopping-basket"></i>
                                <p>Your cart is empty</p>
                                <p style="font-size:13px;">Search for products to add them</p>
                            </div>
                        </td>
                    </tr>
                `;
                totalSpan.textContent = '0.00';
                itemCountSpan.textContent = '0 items';
                updateSummary();
                return;
            }

            let html = '';
            cart.forEach((item, index) => {
                const itemTotal = item.quantity * item.unit_price;
                total += itemTotal;
                itemCount += item.quantity;

                html += `
                    <tr>
                        <td><strong>${item.product_name}</strong></td>
                        <td>
                            <div class="qty-control">
                                <button class="qty-minus" onclick="updateQuantity(${index}, -1)" title="Decrease quantity">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="qty-number">${item.quantity}</span>
                                <button class="qty-plus" onclick="updateQuantity(${index}, 1)" title="Increase quantity">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </td>
                        <td>UGX ${item.unit_price.toLocaleString()}</td>
                        <td><strong>UGX ${itemTotal.toLocaleString()}</strong></td>
                        <td>
                            <button class="remove-btn" onclick="removeFromCart(${index})" title="Remove item">
                                <i class="fas fa-times-circle"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            totalSpan.textContent = total.toLocaleString();
            itemCountSpan.textContent = itemCount + ' item' + (itemCount > 1 ? 's' : '');
            updateSummary();
        }

        function updateSummary() {
            let subtotal = 0;
            cart.forEach(item => {
                subtotal += item.quantity * item.unit_price;
            });

            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const tax = parseFloat(document.getElementById('tax').value) || 0;
            const grandTotal = subtotal - discount + tax;

            document.getElementById('summarySubtotal').textContent = 'UGX ' + subtotal.toLocaleString();
            document.getElementById('summaryDiscount').textContent = '- UGX ' + discount.toLocaleString();
            document.getElementById('summaryTax').textContent = 'UGX ' + tax.toLocaleString();
            document.getElementById('summaryTotal').textContent = 'UGX ' + grandTotal.toLocaleString();
        }

        function clearCart() {
            if (cart.length === 0) {
                showToast('Cart is already empty', 'info');
                return;
            }
            if (!confirm('Are you sure you want to clear the entire cart?')) return;
            cart = [];
            updateCart();
            showToast('🗑️ Cart cleared', 'info');
        }

        // ========================================
        // RECEIPT FUNCTIONS WITH PDF - ONE PAGE
        // ========================================
        function generateReceiptText(sale) {
            const date = new Date(sale.sale_date).toLocaleString('en-UG', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const items = sale.items || [];
            let paymentDisplay = sale.payment_method || 'Cash';
            paymentDisplay = paymentDisplay.toUpperCase().replace('_', ' ');
            const customerTypeLabel = sale.customer_type === 'walk_in' ? 'Walk-in' : 'Online Order';
            
            let receipt = '';
            const line = '='.repeat(48);
            const dash = '-'.repeat(48);
            
            // Store Header
            receipt += 'PALAMI SHOPPERS KAGOMA\n';
            receipt += 'Kagoma Town, Uganda\n';
            receipt += 'Tel: +256 700 000 000\n';
            receipt += line + '\n';
            
            // Invoice Details
            receipt += 'Invoice: ' + (sale.invoice_number || 'N/A') + '\n';
            receipt += 'Date: ' + date + '\n';
            receipt += 'Cashier: ' + (sale.cashier_name || 'Cashier') + '\n';
            receipt += 'Customer: ' + (sale.customer_name || 'Walk-in') + ' (' + customerTypeLabel + ')\n';
            if (sale.customer_phone) {
                receipt += 'Phone: ' + sale.customer_phone + '\n';
            }
            receipt += dash + '\n';
            
            // Items Header
            receipt += 'Item                Qty  Price   Total\n';
            receipt += dash + '\n';
            
            // Items
            if (items.length > 0) {
                items.forEach(item => {
                    const name = (item.product_name || 'Unknown').substring(0, 18).padEnd(18);
                    const qty = (item.quantity || 0).toString().padStart(3);
                    const price = 'UGX ' + (item.unit_price || 0).toFixed(2).padStart(8);
                    const total = 'UGX ' + ((item.quantity || 0) * (item.unit_price || 0)).toFixed(2).padStart(9);
                    receipt += `${name} ${qty} ${price} ${total}\n`;
                });
            } else {
                receipt += 'No items found\n';
            }
            
            receipt += dash + '\n';
            
            // Totals
            const subtotal = (sale.subtotal || 0);
            const discount = (sale.discount || 0);
            const tax = (sale.tax || 0);
            const grandTotal = (sale.grand_total || 0);
            
            receipt += `Subtotal: UGX ${subtotal.toFixed(2).padStart(35)}\n`;
            if (discount > 0) {
                receipt += `Discount: -UGX ${discount.toFixed(2).padStart(34)}\n`;
            }
            if (tax > 0) {
                receipt += `Tax: UGX ${tax.toFixed(2).padStart(39)}\n`;
            }
            receipt += line + '\n';
            receipt += `GRAND TOTAL: UGX ${grandTotal.toFixed(2).padStart(32)}\n`;
            receipt += line + '\n';
            
            // Payment Details
            receipt += `Payment: ${paymentDisplay}\n`;
            receipt += line + '\n';
            
            // Footer
            receipt += 'Thank you for shopping at\n';
            receipt += 'Palami Shoppers Kagoma!\n';
            receipt += 'Quality Products, Best Prices!\n';
            receipt += line + '\n';
            receipt += 'FB: @PalamiShoppers | IG: @PalamiShoppers\n';
            receipt += line;
            
            return receipt;
        }

        function generateReceiptHTML(sale) {
            const date = new Date(sale.sale_date).toLocaleString('en-UG', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const items = sale.items || [];
            let paymentDisplay = sale.payment_method || 'Cash';
            paymentDisplay = paymentDisplay.toUpperCase().replace('_', ' ');
            const customerTypeLabel = sale.customer_type === 'walk_in' ? 'Walk-in' : 'Online Order';
            
            let itemsHTML = '';
            if (items.length > 0) {
                items.forEach(item => {
                    const name = item.product_name || 'Unknown';
                    const qty = item.quantity || 0;
                    const price = item.unit_price || 0;
                    const total = qty * price;
                    itemsHTML += `
                        <tr>
                            <td style="padding: 2px 4px; border-bottom: 1px solid #eee; font-size: 9px;">${name}</td>
                            <td style="padding: 2px 4px; border-bottom: 1px solid #eee; text-align: center; font-size: 9px;">${qty}</td>
                            <td style="padding: 2px 4px; border-bottom: 1px solid #eee; text-align: right; font-size: 9px;">UGX ${price.toFixed(2)}</td>
                            <td style="padding: 2px 4px; border-bottom: 1px solid #eee; text-align: right; font-size: 9px;">UGX ${total.toFixed(2)}</td>
                        </tr>
                    `;
                });
            }
            
            const subtotal = sale.subtotal || 0;
            const discount = sale.discount || 0;
            const tax = sale.tax || 0;
            const grandTotal = sale.grand_total || 0;
            
            return `
                <div id="pdf-receipt" style="font-family: 'Courier New', monospace; max-width: 380px; margin: 0 auto; padding: 10px; background: white; color: #333; font-size: 9px; line-height: 1.3;">
                    <div style="text-align: center; border-bottom: 2px double #1a237e; padding-bottom: 6px; margin-bottom: 6px;">
                        <div style="font-weight: bold; font-size: 12px; color: #1a237e;">PALAMI SHOPPERS KAGOMA</div>
                        <div style="font-size: 8px; color: #666;">Kagoma Town, Uganda</div>
                        <div style="font-size: 8px; color: #666;">Tel: +256 700 000 000</div>
                    </div>
                    
                    <div style="font-size: 8px; margin-bottom: 4px;">
                        <div><strong>Invoice:</strong> ${sale.invoice_number || 'N/A'}</div>
                        <div><strong>Date:</strong> ${date}</div>
                        <div><strong>Cashier:</strong> ${sale.cashier_name || 'Cashier'}</div>
                        <div><strong>Customer:</strong> ${sale.customer_name || 'Walk-in'} (${customerTypeLabel})</div>
                        ${sale.customer_phone ? `<div><strong>Phone:</strong> ${sale.customer_phone}</div>` : ''}
                    </div>
                    
                    <table style="width: 100%; font-size: 8px; border-collapse: collapse; margin: 4px 0;">
                        <thead>
                            <tr style="border-bottom: 2px solid #333;">
                                <th style="text-align: left; padding: 2px 4px;">Item</th>
                                <th style="text-align: center; padding: 2px 4px;">Qty</th>
                                <th style="text-align: right; padding: 2px 4px;">Price</th>
                                <th style="text-align: right; padding: 2px 4px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHTML || '<tr><td colspan="4" style="text-align: center; padding: 4px;">No items</td></tr>'}
                        </tbody>
                    </table>
                    
                    <div style="border-top: 2px solid #333; padding-top: 4px; margin-top: 4px; font-size: 8px;">
                        <div style="display: flex; justify-content: space-between; padding: 1px 0;">
                            <span>Subtotal:</span>
                            <span>UGX ${subtotal.toFixed(2)}</span>
                        </div>
                        ${discount > 0 ? `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0; color: #e74c3c;">
                            <span>Discount:</span>
                            <span>- UGX ${discount.toFixed(2)}</span>
                        </div>` : ''}
                        ${tax > 0 ? `
                        <div style="display: flex; justify-content: space-between; padding: 1px 0;">
                            <span>Tax:</span>
                            <span>UGX ${tax.toFixed(2)}</span>
                        </div>` : ''}
                        <div style="display: flex; justify-content: space-between; padding: 4px 0; font-weight: bold; font-size: 10px; border-top: 2px solid #1a237e; margin-top: 2px;">
                            <span>GRAND TOTAL:</span>
                            <span style="color: #1a237e;">UGX ${grandTotal.toFixed(2)}</span>
                        </div>
                    </div>
                    
                    <div style="text-align: center; border-top: 2px solid #1a237e; padding-top: 6px; margin-top: 6px; font-size: 7px; color: #666;">
                        <div>Thank you for shopping at Palami Shoppers!</div>
                        <div>Quality Products, Best Prices!</div>
                        <div style="font-size: 6px; color: #999; margin-top: 2px;">FB: @PalamiShoppers | IG: @PalamiShoppers</div>
                        <div style="font-size: 6px; color: #ccc; margin-top: 2px;">Payment: ${paymentDisplay}</div>
                    </div>
                </div>
            `;
        }

        function downloadPDFReceipt() {
            if (!lastSaleData) {
                showToast('No receipt data to download', 'error');
                return;
            }
            
            if (typeof html2pdf === 'undefined') {
                showToast('⚠️ PDF library not loaded. Please refresh the page.', 'error');
                return;
            }
            
            const htmlContent = generateReceiptHTML(lastSaleData);
            
            const container = document.createElement('div');
            container.innerHTML = htmlContent;
            container.style.position = 'fixed';
            container.style.left = '-9999px';
            container.style.top = '0';
            container.style.background = 'white';
            container.style.padding = '10px';
            container.style.width = '400px';
            container.style.zIndex = '-1';
            document.body.appendChild(container);
            
            const element = container.querySelector('#pdf-receipt');
            
            const opt = {
                margin:        [5, 5, 5, 5],
                filename:     `receipt-${lastSaleData.invoice_number || 'sale'}.pdf`,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, letterRendering: true, useCORS: true, logging: false },
                jsPDF:        { unit: 'mm', format: [80, 150], orientation: 'portrait' },
                pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
            };
            
            showToast('⏳ Generating PDF...', 'info');
            
            html2pdf().set(opt).from(element).save().then(() => {
                document.body.removeChild(container);
                showToast('✅ PDF receipt downloaded successfully!', 'success');
            }).catch((error) => {
                document.body.removeChild(container);
                console.error('PDF generation error:', error);
                showToast('❌ Error generating PDF: ' + error.message, 'error');
            });
        }

        function showReceipt(sale) {
            lastSaleData = sale;
            const receiptText = generateReceiptText(sale);
            document.getElementById('receiptPrintContent').textContent = receiptText;
            document.getElementById('receiptModal').classList.add('show');
            
            // Reset scroll position
            const body = document.querySelector('.receipt-modal-body');
            if (body) body.scrollTop = 0;
        }

        function closeReceipt() {
            document.getElementById('receiptModal').classList.remove('show');
        }

        function printReceipt() {
            window.print();
        }

        function emailReceipt() {
            if (!lastSaleData) {
                showToast('No receipt data to email', 'error');
                return;
            }
            
            const receiptText = generateReceiptText(lastSaleData);
            const subject = `Receipt - ${lastSaleData.invoice_number || 'Sale'}`;
            const body = encodeURIComponent(receiptText);
            const email = document.getElementById('customerEmail')?.value || '';
            
            window.location.href = `mailto:${email}?subject=${encodeURIComponent(subject)}&body=${body}`;
            showToast('📧 Email client opened!', 'success');
        }

        // Close modal on outside click
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('receiptModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeReceipt();
                    }
                });
            }
        });

        // Keyboard shortcuts for receipt modal
        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById('receiptModal');
            if (!modal || !modal.classList.contains('show')) return;
            
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printReceipt();
            }
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                downloadPDFReceipt();
            }
            if (e.key === 'Escape') {
                closeReceipt();
            }
        });

        // ========================================
        // COMPLETE SALE
        // ========================================
        function completeSale() {
            if (cart.length === 0) {
                showToast('❌ Cart is empty!', 'error');
                return;
            }

            const customerType = document.getElementById('customerType').value;
            const customerName = document.getElementById('customerName').value || (customerType === 'online' ? 'Online Customer' : 'Walk-in Customer');
            const customerEmail = document.getElementById('customerEmail').value || '';
            const customerPhone = document.getElementById('customerPhone').value || '';
            const paymentMethod = document.getElementById('paymentMethod').value;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const tax = parseFloat(document.getElementById('tax').value) || 0;

            let subtotal = 0;
            cart.forEach(item => {
                subtotal += item.quantity * item.unit_price;
            });
            const grandTotal = subtotal - discount + tax;

            if (!confirm(`💳 Confirm sale for UGX ${grandTotal.toLocaleString()}?`)) {
                return;
            }

            const btn = document.getElementById('completeBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

            const formData = new FormData();
            formData.append('ajax_action', 'complete_sale');
            formData.append('cart', JSON.stringify(cart));
            formData.append('customer_type', customerType);
            formData.append('customer_name', customerName);
            formData.append('customer_email', customerEmail);
            formData.append('customer_phone', customerPhone);
            formData.append('payment_method', paymentMethod);
            formData.append('discount', discount);
            formData.append('tax', tax);

            fetch('pos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showReceipt(data.sale);
                    
                    const typeLabel = customerType === 'walk_in' ? 'Walk-in' : 'Online Order';
                    showToast(`✅ Sale completed! Invoice: ${data.sale.invoice_number} | ${typeLabel} | Total: UGX ${data.sale.grand_total.toLocaleString()}`, 'success');
                    
                    cart = [];
                    updateCart();
                    
                    document.getElementById('customerName').value = customerType === 'walk_in' ? '' : 'Online Customer';
                    document.getElementById('customerEmail').value = '';
                    document.getElementById('customerPhone').value = '';
                    document.getElementById('discount').value = '0';
                    document.getElementById('tax').value = '0';
                    document.getElementById('barcodeInput').focus();
                } else {
                    showToast('❌ Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('❌ Error completing sale: ' + error.message, 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Complete Sale';
            });
        }

        // ========================================
        // KEYBOARD SHORTCUTS
        // ========================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F1') {
                e.preventDefault();
                document.getElementById('barcodeInput').focus();
            }
            if (e.key === 'F2') {
                e.preventDefault();
                addToCart();
            }
            if (e.key === 'F9') {
                e.preventDefault();
                completeSale();
            }
            if (e.key === 'Escape') {
                document.getElementById('productInfo').classList.remove('visible');
                document.getElementById('barcodeInput').focus();
            }
        });

        console.log('🔑 Keyboard Shortcuts:');
        console.log('  F1 - Focus Search');
        console.log('  Enter - Search');
        console.log('  F2 - Add to Cart');
        console.log('  F9 - Complete Sale');
        console.log('  ESC - Cancel Product');
        console.log('  Ctrl+P - Print Receipt');
        console.log('  Ctrl+D - Download PDF');
    </script>

</body>
</html>