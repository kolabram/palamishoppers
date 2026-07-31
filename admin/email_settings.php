<?php
/**
 * Palami Shoppers Kagoma - Email Settings
 */

require_once __DIR__ . '/../bootstrap.php';

SessionManager::startSession();
SessionManager::requireRole('admin');

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Update email settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        $adminEmails = Security::sanitizeInput($_POST['admin_emails']);
        $fromEmail = Security::sanitizeInput($_POST['from_email']);
        $fromName = Security::sanitizeInput($_POST['from_name']);
        
        // Save to database or config file
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute(['admin_emails', $adminEmails, $adminEmails]);
        $stmt->execute(['from_email', $fromEmail, $fromEmail]);
        $stmt->execute(['from_name', $fromName, $fromName]);
        
        $message = 'Email settings updated successfully!';
    } catch (Exception $e) {
        $error = 'Failed to update settings: ' . $e->getMessage();
    }
}

// Get current settings
$settings = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('admin_emails', 'from_email', 'from_name')");
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Settings table might not exist yet
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palami Shoppers Kagoma - Email Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; min-height: 100vh; color: #333; }
        a { text-decoration: none; }
        
        .header { background: linear-gradient(135deg, #0d47a1 0%, #1a237e 50%, #283593 100%); color: white; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 15px rgba(0,0,0,0.3); position: sticky; top: 0; z-index: 1000; flex-wrap: wrap; gap: 10px; }
        .header-left { display: flex; align-items: center; gap: 20px; }
        .header-logo { display: flex; align-items: center; gap: 15px; }
        .header-logo img { height: 45px; width: auto; filter: brightness(0) invert(1); }
        .header-logo h1 { font-size: 22px; font-weight: 700; color: #ffd700; }
        .header-logo .subtitle { font-size: 11px; opacity: 0.8; color: #bbdefb; }
        .header-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .header-right .user-name { font-weight: 600; color: #ffd700; }
        .header-right .user-role { font-size: 11px; opacity: 0.8; color: #bbdefb; text-transform: uppercase; }
        .logout-btn { color: white; padding: 8px 20px; background: rgba(255,215,0,0.15); border-radius: 6px; transition: all 0.3s; border: 1px solid rgba(255,215,0,0.25); display: flex; align-items: center; gap: 8px; }
        .logout-btn:hover { background: rgba(255,215,0,0.25); border-color: #ffd700; transform: translateY(-2px); }
        
        .container { padding: 30px; max-width: 800px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { color: #1a237e; font-size: 24px; font-weight: 700; }
        .page-header h2 i { color: #ffd700; margin-right: 10px; }
        .page-header .breadcrumb { color: #7f8c8d; font-size: 14px; }
        .page-header .breadcrumb a { color: #1a237e; }
        
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .card h3 { color: #1a237e; margin-bottom: 15px; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #555; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; font-family: inherit; }
        .form-group input:focus, .form-group select:focus { border-color: #1a237e; outline: none; }
        .form-group .help-text { font-size: 12px; color: #95a5a6; margin-top: 4px; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #1a237e, #0d47a1); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26,35,126,0.3); }
        .btn-success { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39,174,96,0.3); }
        .btn-outline { background: transparent; color: #1a237e; border: 2px solid #1a237e; }
        .btn-outline:hover { background: #1a237e; color: white; }
        
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-danger { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert i { font-size: 20px; }
        
        @media (max-width: 768px) {
            .header { padding: 10px 15px; flex-direction: column; align-items: stretch; gap: 10px; }
            .container { padding: 15px; }
            .page-header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

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

    <div class="container">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-envelope"></i> Email Settings</h2>
                <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / Settings / Email</div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h3><i class="fas fa-cog"></i> Email Configuration</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Admin Email Addresses</label>
                    <input type="text" name="admin_emails" value="<?php echo $settings['admin_emails'] ?? 'admin@palamishoppers.com'; ?>" placeholder="admin@example.com, manager@example.com">
                    <div class="help-text">Separate multiple emails with commas</div>
                </div>

                <div class="form-group">
                    <label>Sender Email</label>
                    <input type="email" name="from_email" value="<?php echo $settings['from_email'] ?? 'sales@palamishoppers.com'; ?>" placeholder="sales@example.com">
                    <div class="help-text">Email address that will appear as the sender</div>
                </div>

                <div class="form-group">
                    <label>Sender Name</label>
                    <input type="text" name="from_name" value="<?php echo $settings['from_name'] ?? 'Palami Shoppers Kagoma'; ?>" placeholder="Your Store Name">
                </div>

                <button type="submit" name="update_settings" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </form>
        </div>

        <div class="card">
            <h3><i class="fas fa-info-circle"></i> Test Email</h3>
            <p style="color:#7f8c8d;margin-bottom:15px;">Send a test email to verify your email settings are working.</p>
            <form method="POST">
                <div class="form-group">
                    <label>Test Email Address</label>
                    <input type="email" name="test_email" placeholder="your-email@example.com" required>
                </div>
                <button type="submit" name="send_test" class="btn btn-success">
                    <i class="fas fa-paper-plane"></i> Send Test Email
                </button>
            </form>
        </div>
    </div>

</body>
</html>