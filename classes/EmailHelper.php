<?php
/**
 * Palami Shoppers Kagoma - Email Helper
 */

require_once __DIR__ . '/../config/email.php';

// Check if PHPMailer is available
if (file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')) {
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
}

class EmailHelper {
    
    /**
     * Send email using PHPMailer (if available)
     */
    public static function sendEmail($to, $subject, $message, $isHtml = true) {
        // Try using PHPMailer first
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return self::sendViaPHPMailer($to, $subject, $message, $isHtml);
        }
        
        // Fallback to PHP mail() function
        return self::sendViaMail($to, $subject, $message, $isHtml);
    }
    
    /**
     * Send email using PHPMailer
     */
    private static function sendViaPHPMailer($to, $subject, $message, $isHtml = true) {
        try {
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body    = $message;
            
            if (!$isHtml) {
                $mail->AltBody = strip_tags($message);
            }
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Send email using PHP mail() function
     */
    private static function sendViaMail($to, $subject, $message, $isHtml = true) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        return mail($to, $subject, $message, $headers);
    }
    
    /**
     * Send sale notification to admin
     */
    public static function sendSaleNotification($saleData) {
        $subject = 'New Sale Notification - ' . $saleData['invoice_number'];
        $message = self::buildSaleEmail($saleData);
        
        $sent = false;
        foreach (ADMIN_EMAILS as $email) {
            if (self::sendEmail($email, $subject, $message)) {
                $sent = true;
            }
        }
        
        // Also send to customer if email is provided
        if (!empty($saleData['customer_email'])) {
            self::sendCustomerReceipt($saleData);
        }
        
        return $sent;
    }
    
    /**
     * Build sale notification email
     */
    private static function buildSaleEmail($saleData) {
        $itemsHtml = '';
        foreach ($saleData['items'] as $item) {
            $itemsHtml .= "
                <tr>
                    <td style='padding:8px;border-bottom:1px solid #ddd;'>{$item['product_name']}</td>
                    <td style='padding:8px;border-bottom:1px solid #ddd;text-align:center;'>{$item['quantity']}</td>
                    <td style='padding:8px;border-bottom:1px solid #ddd;text-align:right;'>UGX " . number_format($item['unit_price'], 2) . "</td>
                    <td style='padding:8px;border-bottom:1px solid #ddd;text-align:right;'>UGX " . number_format($item['total_price'], 2) . "</td>
                </tr>
            ";
        }
        
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background: #1a237e; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-top: none; }
                .sale-info { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th { background: #1a237e; color: white; padding: 10px; text-align: left; }
                .total-row { font-weight: bold; font-size: 18px; color: #1a237e; }
                .footer { text-align: center; padding: 15px; color: #666; font-size: 12px; }
                .badge { display: inline-block; padding: 4px 12px; background: #ffd700; color: #1a237e; border-radius: 4px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🛒 New Sale Notification</h1>
                    <p>Palami Shoppers Kagoma</p>
                </div>
                <div class='content'>
                    <div class='sale-info'>
                        <h3>Sale Details</h3>
                        <p><strong>Invoice Number:</strong> <span class='badge'>{$saleData['invoice_number']}</span></p>
                        <p><strong>Date:</strong> " . date('d F Y H:i:s', strtotime($saleData['sale_date'])) . "</p>
                        <p><strong>Customer:</strong> " . ($saleData['customer_name'] ?: 'Walk-in Customer') . "</p>
                        <p><strong>Payment Method:</strong> " . strtoupper(str_replace('_', ' ', $saleData['payment_method'])) . "</p>
                    </div>
                    
                    <h3>Items Purchased</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style='text-align:center;'>Qty</th>
                                <th style='text-align:right;'>Price</th>
                                <th style='text-align:right;'>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$itemsHtml}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan='3' style='text-align:right;padding:8px;'><strong>Subtotal:</strong></td>
                                <td style='text-align:right;padding:8px;'>UGX " . number_format($saleData['total_amount'], 2) . "</td>
                            </tr>
                            <tr>
                                <td colspan='3' style='text-align:right;padding:8px;'><strong>Discount:</strong></td>
                                <td style='text-align:right;padding:8px;color:#e74c3c;'>- UGX " . number_format($saleData['discount'], 2) . "</td>
                            </tr>
                            <tr>
                                <td colspan='3' style='text-align:right;padding:8px;'><strong>Tax:</strong></td>
                                <td style='text-align:right;padding:8px;'>UGX " . number_format($saleData['tax'], 2) . "</td>
                            </tr>
                            <tr class='total-row'>
                                <td colspan='3' style='text-align:right;padding:8px;font-size:20px;'>GRAND TOTAL:</td>
                                <td style='text-align:right;padding:8px;font-size:20px;color:#ffd700;'>UGX " . number_format($saleData['grand_total'], 2) . "</td>
                            </tr>
                        </tfoot>
                    </table>
                    
                    <p style='text-align:center;margin-top:20px;'>
                        <a href='http://localhost/supermarket/admin/sales.php' style='background:#1a237e;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;'>
                            View in Dashboard
                        </a>
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated notification from Palami Shoppers Kagoma.</p>
                    <p>&copy; " . date('Y') . " Palami Shoppers Kagoma. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Send receipt to customer
     */
    private static function sendCustomerReceipt($saleData) {
        $subject = 'Your Receipt - ' . $saleData['invoice_number'] . ' - Palami Shoppers Kagoma';
        $message = self::buildCustomerReceipt($saleData);
        return self::sendEmail($saleData['customer_email'], $subject, $message);
    }
    
    /**
     * Build customer receipt email
     */
    private static function buildCustomerReceipt($saleData) {
        $itemsHtml = '';
        foreach ($saleData['items'] as $item) {
            $itemsHtml .= "
                <tr>
                    <td style='padding:6px;border-bottom:1px solid #eee;'>{$item['product_name']}</td>
                    <td style='padding:6px;border-bottom:1px solid #eee;text-align:center;'>{$item['quantity']}</td>
                    <td style='padding:6px;border-bottom:1px solid #eee;text-align:right;'>UGX " . number_format($item['unit_price'], 2) . "</td>
                    <td style='padding:6px;border-bottom:1px solid #eee;text-align:right;'>UGX " . number_format($item['total_price'], 2) . "</td>
                </tr>
            ";
        }
        
        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #1a237e; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th { background: #1a237e; color: white; padding: 8px; text-align: left; }
                .total { font-weight: bold; font-size: 18px; color: #1a237e; }
                .footer { text-align: center; padding: 15px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🛒 Thank You for Shopping!</h1>
                    <p>Palami Shoppers Kagoma</p>
                </div>
                <div class='content'>
                    <h3>Your Receipt</h3>
                    <p><strong>Invoice:</strong> {$saleData['invoice_number']}</p>
                    <p><strong>Date:</strong> " . date('d F Y H:i', strtotime($saleData['sale_date'])) . "</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style='text-align:center;'>Qty</th>
                                <th style='text-align:right;'>Price</th>
                                <th style='text-align:right;'>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$itemsHtml}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan='3' style='text-align:right;'><strong>Total:</strong></td>
                                <td style='text-align:right;'>UGX " . number_format($saleData['grand_total'], 2) . "</td>
                            </tr>
                        </tfoot>
                    </table>
                    
                    <p style='text-align:center;margin-top:20px;'>
                        <strong>Payment Method:</strong> " . strtoupper(str_replace('_', ' ', $saleData['payment_method'])) . "
                    </p>
                    
                    <p style='text-align:center;'>
                        <em>Thank you for choosing Palami Shoppers Kagoma!</em>
                    </p>
                </div>
                <div class='footer'>
                    <p>Palami Shoppers Kagoma | Kagoma Town, Uganda</p>
                    <p>Phone: +256 700 000 000 | Email: info@palamishoppers.com</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
?>