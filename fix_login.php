<?php
require_once 'config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Login - Palami Shoppers</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 8px; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; padding: 15px; border-radius: 8px; margin: 10px 0; }
        button { padding: 12px 30px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #218838; }
    </style>
</head>
<body>
    <h1>🔧 Palami Shoppers - Fix Login</h1>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if users table exists
    $stmt = $db->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() == 0) {
        echo "<div class='error'>❌ Users table doesn't exist! Please run setup.php first.</div>";
        echo "<p><a href='setup.php'><button>Run Setup</button></a></p>";
        exit;
    }
    
    // Check if admin exists
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['admin']);
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "<div class='info'>✅ Admin user exists. Resetting password...</div>";
        
        // Reset admin password to Admin@123
        $hashedPassword = password_hash('Admin@123', PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
        $stmt->execute([$hashedPassword, 'admin']);
        
        echo "<div class='success'>✅ Password reset successfully!</div>";
        echo "<div class='info'>";
        echo "<strong>Login Credentials:</strong><br>";
        echo "Username: <code>admin</code><br>";
        echo "Password: <code>Admin@123</code><br>";
        echo "</div>";
    } else {
        echo "<div class='info'>⚠️ Admin user not found. Creating admin user...</div>";
        
        // Create admin user
        $hashedPassword = password_hash('Admin@123', PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $result = $stmt->execute([
            'admin', 
            $hashedPassword, 
            'System Administrator', 
            'admin@palamishoppers.com', 
            'admin', 
            1
        ]);
        
        if ($result) {
            echo "<div class='success'>✅ Admin user created successfully!</div>";
            echo "<div class='info'>";
            echo "<strong>Login Credentials:</strong><br>";
            echo "Username: <code>admin</code><br>";
            echo "Password: <code>Admin@123</code><br>";
            echo "</div>";
        } else {
            echo "<div class='error'>❌ Failed to create admin user.</div>";
        }
    }
    
    // Also create cashier user if not exists
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute(['cashier']);
    if (!$stmt->fetch()) {
        $hashedPassword = password_hash('Cashier@123', PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'cashier', 
            $hashedPassword, 
            'Cashier User', 
            'cashier@palamishoppers.com', 
            'cashier', 
            1
        ]);
        echo "<div class='info'>✅ Cashier user created: cashier / Cashier@123</div>";
    }
    
    echo "<br><a href='login.php'><button>Go to Login →</button></a>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<p>Make sure MySQL is running in XAMPP.</p>";
}

echo "</body></html>";
?>