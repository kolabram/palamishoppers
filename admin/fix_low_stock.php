<?php
/**
 * Fix Low Stock Alerts - Run this to create alerts for all low stock products
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$productManager = new ProductManager();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Low Stock Alerts</title>
    <style>
        body { font-family: Arial; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 10px 0; }
        .danger { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #1a237e; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .btn { padding: 10px 20px; background: #1a237e; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .btn:hover { background: #0d47a1; }
    </style>
</head>
<body>
    <h1>🔧 Fix Low Stock Alerts</h1>";

// Check all low stock products
try {
    // Get all low stock products
    $stmt = $db->prepare("
        SELECT p.product_id, p.product_name, p.current_stock, p.min_stock_level,
               l.alert_id, l.is_resolved
        FROM products p
        LEFT JOIN low_stock_alerts l ON p.product_id = l.product_id AND l.is_resolved = 0
        WHERE p.current_stock <= p.min_stock_level AND p.is_active = 1
    ");
    $stmt->execute();
    $lowStockProducts = $stmt->fetchAll();
    
    echo "<h2>Products with Low Stock</h2>";
    
    if (empty($lowStockProducts)) {
        echo "<div class='success'>✅ No products with low stock found.</div>";
    } else {
        echo "<table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Current Stock</th>
                    <th>Min Level</th>
                    <th>Alert Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>";
        
        $createdCount = 0;
        foreach ($lowStockProducts as $product) {
            $hasAlert = $product['alert_id'] !== null;
            echo "<tr>
                <td>" . htmlspecialchars($product['product_name']) . "</td>
                <td style='color:" . ($product['current_stock'] <= 0 ? '#e74c3c' : '#f39c12') . "; font-weight:bold;'>" . $product['current_stock'] . "</td>
                <td>" . $product['min_stock_level'] . "</td>
                <td>" . ($hasAlert ? '✅ Alert exists' : '❌ No alert') . "</td>
                <td>";
            
            if (!$hasAlert) {
                echo "<a href='?create_alert=" . $product['product_id'] . "' class='btn'>Create Alert</a>";
            } else {
                echo "<span style='color:#27ae60;'>✓ Resolved</span>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table>";
        
        // Handle creating alerts
        if (isset($_GET['create_alert'])) {
            $productId = (int)$_GET['create_alert'];
            
            // Get product details
            $stmt = $db->prepare("SELECT * FROM products WHERE product_id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                $alertType = $product['current_stock'] <= 0 ? 'out_of_stock' : 'low_stock';
                $stmt = $db->prepare("
                    INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $product['product_id'],
                    $alertType,
                    $product['current_stock'],
                    $product['min_stock_level']
                ]);
                
                echo "<div class='success'>✅ Alert created for " . htmlspecialchars($product['product_name']) . "</div>";
                echo "<script>setTimeout(function(){ window.location.href = 'fix_low_stock.php'; }, 1500);</script>";
            }
        }
    }
    
    // Create alerts for all low stock products at once
    if (isset($_GET['create_all'])) {
        $stmt = $db->prepare("
            SELECT p.product_id, p.current_stock, p.min_stock_level
            FROM products p
            LEFT JOIN low_stock_alerts l ON p.product_id = l.product_id AND l.is_resolved = 0
            WHERE p.current_stock <= p.min_stock_level 
            AND p.is_active = 1
            AND l.alert_id IS NULL
        ");
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        $count = 0;
        foreach ($products as $product) {
            $alertType = $product['current_stock'] <= 0 ? 'out_of_stock' : 'low_stock';
            $stmt = $db->prepare("
                INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $product['product_id'],
                $alertType,
                $product['current_stock'],
                $product['min_stock_level']
            ]);
            $count++;
        }
        
        echo "<div class='success'>✅ Created $count alerts for all low stock products!</div>";
        echo "<script>setTimeout(function(){ window.location.href = 'fix_low_stock.php'; }, 1500);</script>";
    }
    
    if (!isset($_GET['create_all']) && !isset($_GET['create_alert'])) {
        echo "<div style='margin-top:20px;'>
            <a href='?create_all=1' class='btn' style='background:#27ae60;'>Create Alerts for All Low Stock Products</a>
            <a href='products.php' class='btn' style='background:#3498db;margin-left:10px;'>Go to Products</a>
            <a href='dashboard.php' class='btn' style='background:#1a237e;margin-left:10px;'>Go to Dashboard</a>
        </div>";
    }
    
} catch (Exception $e) {
    echo "<div class='danger'>❌ Error: " . $e->getMessage() . "</div>";
}

echo "</body></html>";
?>