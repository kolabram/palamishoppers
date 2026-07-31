<?php
/**
 * Cron Job - Check Low Stock Alerts
 * Run this script daily to auto-create low stock alerts
 */

require_once __DIR__ . '/../bootstrap.php';

$db = Database::getInstance()->getConnection();

// Find all products with low stock that don't have alerts
$stmt = $db->prepare("
    SELECT p.product_id, p.product_name, p.current_stock, p.min_stock_level
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

echo "✅ Created $count low stock alerts\n";