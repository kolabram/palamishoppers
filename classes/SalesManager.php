<?php
/**
 * SalesManager Class
 * Handles all sales-related operations
 */

class SalesManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get dashboard statistics
     * @return array Dashboard stats
     */
    public function getDashboardStats() {
        $stats = [
            'total_sales' => 0,
            'total_revenue' => 0,
            'today_sales' => 0,
            'today_revenue' => 0,
            'total_products' => 0,
            'low_stock' => 0,
            'total_customers' => 0,
            'recent_sales' => []
        ];
        
        try {
            // Get total sales count
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM sales");
            $result = $stmt->fetch();
            $stats['total_sales'] = $result['total'] ?? 0;
            
            // Get total revenue
            $stmt = $this->db->query("SELECT SUM(grand_total) as total FROM sales");
            $result = $stmt->fetch();
            $stats['total_revenue'] = $result['total'] ?? 0;
            
            // Get today's sales
            $stmt = $this->db->query("SELECT COUNT(*) as total, SUM(grand_total) as revenue FROM sales WHERE DATE(sale_date) = CURDATE()");
            $result = $stmt->fetch();
            $stats['today_sales'] = $result['total'] ?? 0;
            $stats['today_revenue'] = $result['revenue'] ?? 0;
            
            // Get total products
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM products");
            $result = $stmt->fetch();
            $stats['total_products'] = $result['total'] ?? 0;
            
            // Get low stock products (less than 10)
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM products WHERE current_stock < 10 AND is_active = 1");
            $result = $stmt->fetch();
            $stats['low_stock'] = $result['total'] ?? 0;
            
            // Get recent sales (last 5)
            $stmt = $this->db->query("SELECT * FROM sales ORDER BY sale_date DESC LIMIT 5");
            $stats['recent_sales'] = $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting dashboard stats: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Get sales by date range
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Sales data
     */
    public function getSalesByDateRange($start_date, $end_date) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM sales 
                WHERE DATE(sale_date) BETWEEN ? AND ? 
                ORDER BY sale_date DESC
            ");
            $stmt->execute([$start_date, $end_date]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error getting sales by date range: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get sales summary for charts
     * @param string $period daily, weekly, monthly
     * @return array Chart data
     */
    public function getSalesChartData($period = 'daily') {
        $data = [
            'labels' => [],
            'values' => []
        ];
        
        try {
            switch ($period) {
                case 'daily':
                    $sql = "SELECT DATE(sale_date) as date, COUNT(*) as count, SUM(grand_total) as total 
                            FROM sales 
                            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                            GROUP BY DATE(sale_date) 
                            ORDER BY date ASC";
                    break;
                case 'weekly':
                    $sql = "SELECT YEARWEEK(sale_date) as week, COUNT(*) as count, SUM(grand_total) as total 
                            FROM sales 
                            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK) 
                            GROUP BY YEARWEEK(sale_date) 
                            ORDER BY week ASC";
                    break;
                case 'monthly':
                    $sql = "SELECT DATE_FORMAT(sale_date, '%Y-%m') as month, COUNT(*) as count, SUM(grand_total) as total 
                            FROM sales 
                            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                            GROUP BY DATE_FORMAT(sale_date, '%Y-%m') 
                            ORDER BY month ASC";
                    break;
                default:
                    $sql = "SELECT DATE(sale_date) as date, COUNT(*) as count, SUM(grand_total) as total 
                            FROM sales 
                            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                            GROUP BY DATE(sale_date) 
                            ORDER BY date ASC";
            }
            
            $stmt = $this->db->query($sql);
            $results = $stmt->fetchAll();
            
            foreach ($results as $row) {
                $data['labels'][] = $row['date'] ?? $row['week'] ?? $row['month'] ?? '';
                $data['values'][] = $row['total'] ?? 0;
            }
            
        } catch (Exception $e) {
            error_log("Error getting sales chart data: " . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Get top selling products
     * @param int $limit Number of products to return
     * @return array Top products
     */
    public function getTopProducts($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    p.product_id,
                    p.product_name,
                    p.unit_price,
                    SUM(si.quantity) as total_sold,
                    SUM(si.total_price) as total_revenue
                FROM sale_items si
                JOIN products p ON si.product_id = p.product_id
                GROUP BY si.product_id
                ORDER BY total_sold DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error getting top products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total customers (unique)
     * @return int Total customers
     */
    public function getTotalCustomers() {
        try {
            $stmt = $this->db->query("SELECT COUNT(DISTINCT customer_email) as total FROM sales WHERE customer_email != ''");
            $result = $stmt->fetch();
            return $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("Error getting total customers: " . $e->getMessage());
            return 0;
        }
    }
}
?>