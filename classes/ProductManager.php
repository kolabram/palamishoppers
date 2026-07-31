<?php
/**
 * Palami Shoppers Kagoma - Product Manager
 */

require_once 'Security.php';
require_once 'AuditLogger.php';

class ProductManager {
    private $db;
    private $auditLogger;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->auditLogger = new AuditLogger();
    }
    
    public function getProductByBarcode($barcode) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM products WHERE barcode = ? AND is_active = 1");
            $stmt->execute([$barcode]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error fetching product: " . $e->getMessage());
            return null;
        }
    }
    
    public function getProductById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error fetching product: " . $e->getMessage());
            return null;
        }
    }
    
    public function searchProducts($search) {
        try {
            $searchTerm = '%' . $search . '%';
            $stmt = $this->db->prepare("
                SELECT * FROM products 
                WHERE (product_name LIKE ? OR barcode LIKE ? OR category LIKE ?) 
                AND is_active = 1
                ORDER BY product_name
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error searching products: " . $e->getMessage());
            return [];
        }
    }
    
    public function getAllProducts($limit = null, $offset = 0) {
        try {
            $query = "SELECT * FROM products WHERE is_active = 1 ORDER BY product_name";
            if ($limit) {
                $query .= " LIMIT ? OFFSET ?";
                $stmt = $this->db->prepare($query);
                $stmt->execute([$limit, $offset]);
            } else {
                $stmt = $this->db->prepare($query);
                $stmt->execute();
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error fetching products: " . $e->getMessage());
            return [];
        }
    }
    
    public function addProduct($data, $userId) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO products (barcode, product_name, description, category, 
                    unit_price, cost_price, current_stock, min_stock_level, 
                    max_stock_level, supplier, store_location)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $data['barcode'],
                Security::sanitizeInput($data['product_name']),
                Security::sanitizeInput($data['description'] ?? ''),
                Security::sanitizeInput($data['category']),
                $data['unit_price'],
                $data['cost_price'] ?? 0,
                $data['current_stock'] ?? 0,
                $data['min_stock_level'] ?? 10,
                $data['max_stock_level'] ?? 100,
                Security::sanitizeInput($data['supplier'] ?? ''),
                Security::sanitizeInput($data['store_location'] ?? 'Main Store')
            ]);
            
            $productId = $this->db->lastInsertId();
            
            // Log inventory transaction
            if ($data['current_stock'] > 0) {
                $this->logInventoryTransaction($productId, $userId, 'purchase', 
                    $data['current_stock'], 0, $data['current_stock']);
            }
            
            // Log audit
            $this->auditLogger->log($userId, 'create_product', 'products', 
                $productId, null, $data);
            
            // Check low stock
            if ($data['current_stock'] <= $data['min_stock_level']) {
                $this->createLowStockAlert($productId, $data['current_stock'], 
                    $data['min_stock_level']);
            }
            
            $this->db->commit();
            return $productId;
        } catch (PDOException $e) {
            $this->db->rollback();
            error_log("Error adding product: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateStock($productId, $quantity, $userId, $transactionType = 'adjustment', 
        $referenceId = null, $notes = null) {
        try {
            $this->db->beginTransaction();
            
            $product = $this->getProductById($productId);
            if (!$product) {
                throw new Exception("Product not found");
            }
            
            $oldStock = $product['current_stock'];
            $newStock = $oldStock + $quantity;
            
            if ($newStock < 0) {
                throw new Exception("Insufficient stock");
            }
            
            $stmt = $this->db->prepare("UPDATE products SET current_stock = ? WHERE product_id = ?");
            $stmt->execute([$newStock, $productId]);
            
            $this->logInventoryTransaction($productId, $userId, $transactionType, 
                $quantity, $oldStock, $newStock, $referenceId, $notes);
            
            if ($newStock <= $product['min_stock_level']) {
                $this->createLowStockAlert($productId, $newStock, $product['min_stock_level']);
            } else {
                $this->resolveLowStockAlert($productId);
            }
            
            $this->auditLogger->log($userId, 'update_stock', 'products', $productId,
                ['current_stock' => $oldStock], ['current_stock' => $newStock]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Error updating stock: " . $e->getMessage());
            return false;
        }
    }
    
    private function logInventoryTransaction($productId, $userId, $type, $quantity, 
        $previousStock, $newStock, $referenceId = null, $notes = null) {
        $stmt = $this->db->prepare("
            INSERT INTO inventory_transactions (product_id, user_id, transaction_type, 
                quantity_change, previous_stock, new_stock, reference_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$productId, $userId, $type, $quantity, $previousStock, 
            $newStock, $referenceId, $notes]);
    }
    
    private function createLowStockAlert($productId, $currentStock, $threshold) {
        // Check if active alert exists
        $stmt = $this->db->prepare("
            SELECT alert_id FROM low_stock_alerts 
            WHERE product_id = ? AND is_resolved = 0
        ");
        $stmt->execute([$productId]);
        
        if (!$stmt->fetch()) {
            $alertType = $currentStock <= 0 ? 'out_of_stock' : 'low_stock';
            $stmt = $this->db->prepare("
                INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $alertType, $currentStock, $threshold]);
        } else {
            // Update existing alert
            $stmt = $this->db->prepare("
                UPDATE low_stock_alerts SET current_stock = ? 
                WHERE product_id = ? AND is_resolved = 0
            ");
            $stmt->execute([$currentStock, $productId]);
        }
    }
    
    private function resolveLowStockAlert($productId) {
        $stmt = $this->db->prepare("
            UPDATE low_stock_alerts SET is_resolved = 1, resolved_at = NOW() 
            WHERE product_id = ? AND is_resolved = 0
        ");
        return $stmt->execute([$productId]);
    }
    
    public function getLowStockProducts() {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.alert_type, l.current_stock as alert_stock, l.threshold,
                       l.created_at as alert_created
                FROM products p
                JOIN low_stock_alerts l ON p.product_id = l.product_id
                WHERE l.is_resolved = 0
                ORDER BY 
                    CASE l.alert_type
                        WHEN 'out_of_stock' THEN 1
                        WHEN 'low_stock' THEN 2
                        ELSE 3
                    END,
                    l.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error fetching low stock products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check all products for low stock and create alerts
     * This should be run periodically (e.g., daily via cron)
     */
    public function checkAllLowStock() {
        try {
            // Find all products with low stock that don't have alerts
            $stmt = $this->db->prepare("
                SELECT p.product_id, p.current_stock, p.min_stock_level
                FROM products p
                LEFT JOIN low_stock_alerts l ON p.product_id = l.product_id AND l.is_resolved = 0
                WHERE p.current_stock <= p.min_stock_level 
                AND p.is_active = 1
                AND l.alert_id IS NULL
            ");
            $stmt->execute();
            $products = $stmt->fetchAll();
            
            $created = 0;
            foreach ($products as $product) {
                $alertType = $product['current_stock'] <= 0 ? 'out_of_stock' : 'low_stock';
                $stmt = $this->db->prepare("
                    INSERT INTO low_stock_alerts (product_id, alert_type, current_stock, threshold)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $product['product_id'],
                    $alertType,
                    $product['current_stock'],
                    $product['min_stock_level']
                ]);
                $created++;
            }
            
            return $created;
        } catch (Exception $e) {
            error_log("Error checking low stock: " . $e->getMessage());
            return false;
        }
    }
}
?>