<?php
/**
 * Login Page - Palami Shoppers Kagoma
 */

// Simple bootstrap without external dependencies
session_start();

// Database connection
try {
    $db = new PDO("mysql:host=localhost;dbname=supermarket_db;charset=utf8mb4", "root", "");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// If already logged in, redirect
if (isset($_SESSION['user_id']) || isset($_SESSION['palami_user_id'])) {
    $role = $_SESSION['role'] ?? $_SESSION['palami_role'] ?? 'cashier';
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: cashier/pos.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        try {
            // Check if user exists by username or email
            $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Check password - try different field names
                $passwordValid = false;
                
                // Try password_hash field
                if (isset($user['password_hash']) && !empty($user['password_hash'])) {
                    if (password_verify($password, $user['password_hash'])) {
                        $passwordValid = true;
                    }
                }
                
                // Try password field
                if (!$passwordValid && isset($user['password']) && !empty($user['password'])) {
                    if (password_verify($password, $user['password'])) {
                        $passwordValid = true;
                    }
                }
                
                // Plain text fallback (for testing only)
                if (!$passwordValid && isset($user['password']) && $user['password'] === $password) {
                    $passwordValid = true;
                }
                
                if ($passwordValid) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['palami_user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['palami_full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['role'] = $user['role'] ?? 'cashier';
                    $_SESSION['palami_role'] = $user['role'] ?? 'cashier';
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['palami_username'] = $user['username'];
                    
                    // Update last login
                    try {
                        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                        $updateStmt->execute([$user['user_id']]);
                    } catch (Exception $e) {
                        // Ignore if column doesn't exist
                    }
                    
                    session_regenerate_id(true);
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header('Location: admin/dashboard.php');
                    } else {
                        header('Location: cashier/pos.php');
                    }
                    exit;
                } else {
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Invalid username or password';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Palami Shoppers Kagoma</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Background decorative elements */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,215,0,0.05) 0%, transparent 70%);
            pointer-events: none;
        }
        
        body::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,215,0,0.03) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px;
            padding: 45px 40px 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
            position: relative;
            z-index: 1;
            animation: slideUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .login-header .logo-icon {
            font-size: 52px;
            color: #ffd700;
            margin-bottom: 12px;
            display: block;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .login-header h1 {
            font-size: 26px;
            color: #1a237e;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        
        .login-header h1 span {
            color: #ffd700;
        }
        
        .login-header p {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 6px;
            font-weight: 400;
        }
        
        .error-message {
            background: #fde8e8;
            color: #c0392b;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid #c0392b;
            font-size: 14px;
            display: <?php echo $error ? 'flex' : 'none'; ?>;
            align-items: center;
            gap: 10px;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .error-message i {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .form-group .input-group {
            position: relative;
        }
        
        .form-group .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            font-size: 16px;
            transition: color 0.3s;
        }
        
        .form-group .input-group input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e8ecf1;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: inherit;
            background: #f8f9fa;
            color: #333;
        }
        
        .form-group .input-group input:focus {
            border-color: #1a237e;
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(26, 35, 126, 0.1);
        }
        
        .form-group .input-group input:focus + i {
            color: #1a237e;
        }
        
        .form-group .input-group input::placeholder {
            color: #b0b0b0;
            font-size: 14px;
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #1a237e, #0d47a1);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(26, 35, 126, 0.35);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login i {
            font-size: 18px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 28px;
        }
        
        .login-footer .credentials {
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            font-size: 12px;
            line-height: 1.8;
            color: #7f8c8d;
            border: 1px solid #e8ecf1;
        }
        
        .login-footer .credentials strong {
            color: #333;
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
        }
        
        .login-footer .credentials .role {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-right: 4px;
        }
        
        .role-admin {
            background: #1a237e;
            color: white;
        }
        
        .role-cashier {
            background: #27ae60;
            color: white;
        }
        
        .login-footer .credentials .user-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
            border-bottom: 1px solid #eee;
        }
        
        .login-footer .credentials .user-row:last-child {
            border-bottom: none;
        }
        
        .login-footer .credentials .user-row .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .login-footer .credentials .user-row .user-info .name {
            font-weight: 600;
            color: #333;
        }
        
        .login-footer .credentials .user-row .password-text {
            color: #95a5a6;
            font-family: monospace;
            font-size: 11px;
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 24px 28px;
                border-radius: 18px;
            }
            
            .login-header h1 {
                font-size: 22px;
            }
            
            .login-header .logo-icon {
                font-size: 40px;
            }
            
            .form-group .input-group input {
                padding: 12px 14px 12px 42px;
                font-size: 14px;
            }
            
            .btn-login {
                padding: 14px;
                font-size: 15px;
            }
            
            .login-footer .credentials .user-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 2px;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .login-container {
                background: rgba(30, 30, 40, 0.98);
            }
            
            .login-header h1 {
                color: #e8ecf1;
            }
            
            .login-header p {
                color: #95a5a6;
            }
            
            .form-group label {
                color: #a8b2d1;
            }
            
            .form-group .input-group input {
                background: #2a2a3a;
                border-color: #3a3a4a;
                color: #e8ecf1;
            }
            
            .form-group .input-group input:focus {
                background: #2a2a3a;
                border-color: #ffd700;
                box-shadow: 0 0 0 4px rgba(255,215,0,0.1);
            }
            
            .form-group .input-group input::placeholder {
                color: #6a6a7a;
            }
            
            .form-group .input-group i {
                color: #6a6a7a;
            }
            
            .login-footer .credentials {
                background: #2a2a3a;
                border-color: #3a3a4a;
                color: #95a5a6;
            }
            
            .login-footer .credentials strong {
                color: #e8ecf1;
            }
            
            .login-footer .credentials .user-row {
                border-bottom-color: #3a3a4a;
            }
            
            .login-footer .credentials .user-row .user-info .name {
                color: #e8ecf1;
            }
            
            .error-message {
                background: #2a1a1a;
                color: #f44336;
                border-left-color: #f44336;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <span class="logo-icon">
                <i class="fas fa-store-alt"></i>
            </span>
            <h1>Palami Shoppers <span>Kagoma</span></h1>
            <p>Point of Sale System</p>
        </div>

        <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>
                    <i class="fas fa-user" style="margin-right: 6px;"></i>
                    Username or Email
                </label>
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" placeholder="Enter your username or email" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-lock" style="margin-right: 6px;"></i>
                    Password
                </label>
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        
    </div>

    <script>
        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const inputs = form.querySelectorAll('input');
            
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.parentElement.style.transform = 'scale(1)';
                });
            });
            
            // Toggle password visibility
            const passwordInput = document.querySelector('input[name="password"]');
            const passwordGroup = passwordInput.closest('.input-group');
            
            // Auto-focus username field
            document.querySelector('input[name="username"]').focus();
        });
    </script>
</body>
</html>