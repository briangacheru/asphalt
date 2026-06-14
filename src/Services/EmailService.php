<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Email service for handling all email communications
 */
class EmailService
{
    private PHPMailer $mailer;
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->initializeMailer();
    }

    /**
     * Initialize PHPMailer with SMTP settings
     */
    private function initializeMailer(): void
    {
        $this->mailer = new PHPMailer(true);

        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host       = SMTP_HOST;
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = SMTP_USER;
            $this->mailer->Password   = SMTP_PASS;
            $this->mailer->SMTPSecure = SMTP_SECURE;
            $this->mailer->Port       = SMTP_PORT;

            // Default sender
            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->isHTML(true);

            // Disable debug output
            $this->mailer->SMTPDebug = SMTP::DEBUG_OFF;

        } catch (Exception $e) {
            error_log("PHPMailer initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Send an email
     */
    public function send(string $to, string $subject, string $htmlBody, string $plainBody = ''): bool
    {
        try {
            // Clear previous recipients and attachments
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();

            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $htmlBody;
            $this->mailer->AltBody = $plainBody ?: strip_tags($htmlBody);

            return $this->mailer->send();

        } catch (Exception $e) {
            error_log("Email send failed: " . $this->mailer->ErrorInfo);
            return false;
        }
    }

    /**
     * Log email to database
     */
    private function logEmail(
        int $vehicleId,
        string $type,
        string $recipient,
        string $subject,
        string $body,
        string $status
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO email_log (vehicle_id, email_type, recipient_email, subject, body, status, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
            $stmt->execute([$vehicleId, $type, $recipient, $subject, $body, $status, $sentAt]);
        } catch (\Exception $e) {
            error_log("Email log failed: " . $e->getMessage());
        }
    }

    /**
     * Get email template wrapper
     */
    private function getEmailTemplate(string $content, string $title = ''): string
    {
        return sprintf('
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>%s</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #000000;">
            <table role="presentation" style="width: 100%%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 40px 20px;">
                        <table role="presentation" style="max-width: 600px; margin: 0 auto; background: linear-gradient(145deg, #1c1c1e, #2c2c2e); border-radius: 16px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,0.5);">
                            <!-- Header -->
                            <tr>
                                <td style="padding: 30px 40px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1);">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        🚗 iVehicle
                                    </h1>
                                </td>
                            </tr>
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px; color: #e5e5ea;">
                                    %s
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="padding: 20px 40px; text-align: center; background: rgba(0,0,0,0.3); border-top: 1px solid rgba(255,255,255,0.1);">
                                    <p style="margin: 0; color: #86868b; font-size: 12px;">
                                        © %d iVehicle. All rights reserved.
                                    </p>
                                    <p style="margin: 10px 0 0; color: #86868b; font-size: 12px;">
                                        <a href="%s" style="color: #0071e3; text-decoration: none;">Visit Dashboard</a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>',
            htmlspecialchars($title),
            $content,
            date('Y'),
            APP_URL
        );
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $email, string $token, string $firstName): bool
    {
        $resetLink = APP_URL . '/auth/reset-password.php?token=' . $token;

        $content = sprintf('
            <h2 style="margin: 0 0 20px; color: #ffffff; font-size: 20px;">Password Reset Request</h2>
            <p style="margin: 0 0 20px; line-height: 1.6;">Hi %s,</p>
            <p style="margin: 0 0 20px; line-height: 1.6;">We received a request to reset your password. Click the button below to create a new password:</p>
            <p style="margin: 30px 0; text-align: center;">
                <a href="%s" style="display: inline-block; padding: 14px 32px; background: #ffffff; color: #000000; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">Reset Password</a>
            </p>
            <p style="margin: 0 0 20px; line-height: 1.6; color: #86868b; font-size: 14px;">This link will expire in 1 hour.</p>
            <p style="margin: 0 0 20px; line-height: 1.6; color: #86868b; font-size: 14px;">If you didn\'t request this, you can safely ignore this email.</p>
            <p style="margin: 20px 0 0; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); color: #86868b; font-size: 12px;">
                Or copy this link: <br>
                <a href="%s" style="color: #0071e3; word-break: break-all;">%s</a>
            </p>
        ',
            htmlspecialchars($firstName),
            $resetLink,
            $resetLink,
            $resetLink
        );

        $subject = 'Reset Your Password - iVehicle';
        $html = $this->getEmailTemplate($content, $subject);

        return $this->send($email, $subject, $html);
    }

    /**
     * Send welcome/verification email
     */
    public function sendWelcomeEmail(string $email, string $token, string $firstName): bool
    {
        $verifyLink = APP_URL . '/auth/verify.php?token=' . $token;

        $content = sprintf('
            <h2 style="margin: 0 0 20px; color: #ffffff; font-size: 20px;">Welcome to iVehicle!</h2>
            <p style="margin: 0 0 20px; line-height: 1.6;">Hi %s,</p>
            <p style="margin: 0 0 20px; line-height: 1.6;">Thank you for registering! Please verify your email address to get started:</p>
            <p style="margin: 30px 0; text-align: center;">
                <a href="%s" style="display: inline-block; padding: 14px 32px; background: #ffffff; color: #000000; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">Verify Email</a>
            </p>
            <p style="margin: 0 0 20px; line-height: 1.6;">With iVehicle, you can:</p>
            <ul style="margin: 0 0 20px; padding-left: 20px; line-height: 1.8;">
                <li>Track multiple vehicles</li>
                <li>Record service history</li>
                <li>Get maintenance reminders</li>
                <li>Monitor fuel consumption</li>
                <li>Track expenses</li>
            </ul>
        ',
            htmlspecialchars($firstName),
            $verifyLink
        );

        $subject = 'Welcome to iVehicle - Verify Your Email';
        $html = $this->getEmailTemplate($content, $subject);

        return $this->send($email, $subject, $html);
    }

    /**
     * Send service details request email
     */
    public function sendServiceDetailsEmail(int $vehicleId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT v.*, sr.service_date, sr.mileage, sr.next_service_mileage, sr.oil_interval, 
                   u.email, u.first_name
            FROM vehicles v
            JOIN service_records sr ON v.id = sr.vehicle_id
            JOIN users u ON v.user_id = u.id
            WHERE v.id = ?
            ORDER BY sr.id DESC LIMIT 1
        ");
        $stmt->execute([$vehicleId]);
        $data = $stmt->fetch();

        if (!$data) {
            return false;
        }

        $vehicleName = $data['make'] . ' ' . $data['model'] . ' (' . $data['year'] . ')';

        $content = sprintf('
            <h2 style="margin: 0 0 20px; color: #ffffff; font-size: 20px;">Service Recorded Successfully!</h2>
            <p style="margin: 0 0 20px; line-height: 1.6;">Hi %s,</p>
            <p style="margin: 0 0 20px; line-height: 1.6;">Your service for <strong>%s</strong> has been recorded.</p>
            
            <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 20px; margin: 20px 0;">
                <table style="width: 100%%; color: #e5e5ea;">
                    <tr><td style="padding: 8px 0; color: #86868b;">Date:</td><td style="padding: 8px 0; text-align: right;">%s</td></tr>
                    <tr><td style="padding: 8px 0; color: #86868b;">Mileage:</td><td style="padding: 8px 0; text-align: right;">%s km</td></tr>
                    <tr><td style="padding: 8px 0; color: #86868b;">Next Service:</td><td style="padding: 8px 0; text-align: right;">%s km</td></tr>
                </table>
            </div>
            
            <p style="margin: 20px 0; line-height: 1.6;"><strong>Don\'t forget to add service items!</strong></p>
            <p style="margin: 0 0 20px; line-height: 1.6;">Record what was changed during this service (oil filter, cabin filter, brake pads, etc.) along with brands and costs.</p>
            
            <p style="margin: 30px 0; text-align: center;">
                <a href="%s/service-items.php?vehicle_id=%d" style="display: inline-block; padding: 14px 32px; background: #ffffff; color: #000000; text-decoration: none; border-radius: 8px; font-weight: 600;">Add Service Items</a>
            </p>
        ',
            htmlspecialchars($data['first_name']),
            htmlspecialchars($vehicleName),
            date('M d, Y', strtotime($data['service_date'])),
            number_format($data['mileage']),
            number_format($data['next_service_mileage']),
            APP_URL,
            $vehicleId
        );

        $subject = "Service Recorded: $vehicleName - Add Details";
        $html = $this->getEmailTemplate($content, $subject);

        $sent = $this->send($data['email'], $subject, $html);
        $this->logEmail($vehicleId, 'service_details', $data['email'], $subject, $html, $sent ? 'sent' : 'failed');

        return $sent;
    }

    /**
     * Send monthly check-in email
     */
    public function sendMonthlyCheckEmail(int $vehicleId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT v.*, u.email, u.first_name,
                   (SELECT next_service_mileage FROM service_records WHERE vehicle_id = v.id ORDER BY id DESC LIMIT 1) as next_service
            FROM vehicles v
            JOIN users u ON v.user_id = u.id
            WHERE v.id = ?
        ");
        $stmt->execute([$vehicleId]);
        $data = $stmt->fetch();

        if (!$data) {
            return false;
        }

        $vehicleName = $data['make'] . ' ' . $data['model'] . ' (' . $data['year'] . ')';
        $kmRemaining = $data['next_service'] ? $data['next_service'] - $data['current_mileage'] : null;

        $statusHtml = '';
        if ($kmRemaining !== null) {
            if ($kmRemaining <= 0) {
                $statusHtml = sprintf(
                    '<div style="background: rgba(255,59,48,0.2); border: 1px solid rgba(255,59,48,0.3); border-radius: 8px; padding: 15px; margin: 20px 0; color: #ff3b30;"><strong>⚠️ Service Overdue!</strong> Your vehicle is %s km past due.</div>',
                    number_format(abs($kmRemaining))
                );
            } elseif ($kmRemaining <= 1000) {
                $statusHtml = sprintf(
                    '<div style="background: rgba(255,149,0,0.2); border: 1px solid rgba(255,149,0,0.3); border-radius: 8px; padding: 15px; margin: 20px 0; color: #ff9500;"><strong>⏰ Service Soon!</strong> Only %s km until next service.</div>',
                    number_format($kmRemaining)
                );
            }
        }

        $content = sprintf('
            <h2 style="margin: 0 0 20px; color: #ffffff; font-size: 20px;">Monthly Check-in</h2>
            <p style="margin: 0 0 20px; line-height: 1.6;">Hi %s,</p>
            <p style="margin: 0 0 20px; line-height: 1.6;">It\'s time for your monthly check-in for <strong>%s</strong>.</p>
            
            %s
            
            <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 20px; margin: 20px 0;">
                <table style="width: 100%%; color: #e5e5ea;">
                    <tr><td style="padding: 8px 0; color: #86868b;">Current Mileage:</td><td style="padding: 8px 0; text-align: right;">%s km</td></tr>
                    %s
                </table>
            </div>
            
            <p style="margin: 20px 0; line-height: 1.6;">Please update your mileage and let us know if anything has changed:</p>
            
            <p style="margin: 30px 0; text-align: center;">
                <a href="%s/update-mileage.php?vehicle_id=%d" style="display: inline-block; padding: 14px 32px; background: #ffffff; color: #000000; text-decoration: none; border-radius: 8px; font-weight: 600;">Update Mileage</a>
            </p>
        ',
            htmlspecialchars($data['first_name']),
            htmlspecialchars($vehicleName),
            $statusHtml,
            number_format($data['current_mileage']),
            $data['next_service'] ? sprintf(
                '<tr><td style="padding: 8px 0; color: #86868b;">Next Service At:</td><td style="padding: 8px 0; text-align: right;">%s km</td></tr>',
                number_format($data['next_service'])
            ) : '',
            APP_URL,
            $vehicleId
        );

        $subject = "Monthly Check-in: $vehicleName";
        $html = $this->getEmailTemplate($content, $subject);

        $sent = $this->send($data['email'], $subject, $html);
        $this->logEmail($vehicleId, 'monthly_check', $data['email'], $subject, $html, $sent ? 'sent' : 'failed');

        return $sent;
    }
}
