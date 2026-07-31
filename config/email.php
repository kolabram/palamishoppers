<?php
/**
 * Palami Shoppers Kagoma - Email Configuration
 */

// Email settings
define('SMTP_HOST', 'smtp.gmail.com'); // or your SMTP server
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // or 'ssl'
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'sales@palamishoppers.com');
define('SMTP_FROM_NAME', 'Palami Shoppers Kagoma');
define('ADMIN_EMAIL', 'admin@palamishoppers.com');
define('ADMIN_EMAILS', [
    'admin@palamishoppers.com',
    'manager@palamishoppers.com',
    // Add more admin emails here
]);

// Use PHPMailer for better email sending (recommended)
// Download PHPMailer from: https://github.com/PHPMailer/PHPMailer
// Place in: classes/PHPMailer/
?>