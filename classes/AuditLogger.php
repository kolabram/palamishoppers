<?php
/**
 * Palami Shoppers Kagoma - Audit Logger
 */

// Include database configuration
require_once __DIR__ . '/../config/database.php';

class AuditLogger {
    private $db;
    
    public function __construct() {
        try {
            // Check if Database class exists
            if (class_exists('Database')) {
                $this->db = Database::getInstance()->getConnection();
            } else {
                // If Database class doesn't exist, try to load it
                require_once __DIR__ . '/../config/database.php';
                if (class_exists('Database')) {
                    $this->db = Database::getInstance()->getConnection();
                } else {
                    $this->db = null;
                }
            }
        } catch (Exception $e) {
            // Log error but don't crash
            error_log("AuditLogger: Failed to connect to database - " . $e->getMessage());
            $this->db = null;
        }
    }
    
    public function log($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        // If database connection failed, skip logging
        if ($this->db === null) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, 
                    old_values, new_values, ip_address, user_agent, store_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $oldJson = $oldValues ? json_encode($oldValues) : null;
            $newJson = $newValues ? json_encode($newValues) : null;
            $ip = $this->getClientIP();
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
            $storeName = 'Palami Shoppers Kagoma';
            
            return $stmt->execute([$userId, $action, $tableName, $recordId, 
                $oldJson, $newJson, $ip, $userAgent, $storeName]);
        } catch (PDOException $e) {
            error_log("Audit log error: " . $e->getMessage());
            return false;
        }
    }
    
    private function getClientIP() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }
    
    public function getLogs($limit = 100, $offset = 0, $filters = []) {
        if ($this->db === null) {
            return [];
        }
        
        try {
            $query = "SELECT * FROM audit_logs WHERE 1=1";
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $query .= " AND user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['action'])) {
                $query .= " AND action = ?";
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['date_from'])) {
                $query .= " AND log_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $query .= " AND log_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            $query .= " ORDER BY log_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error fetching audit logs: " . $e->getMessage());
            return [];
        }
    }
}
?>