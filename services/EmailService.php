<?php
require_once __DIR__ . '/../config/mail.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class EmailService
{
    public static function sendOtp(string $recipient, string $otp, string $purpose, int $expiresInSeconds = 300): void
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Email service is not configured. Install Composer dependencies first.');
        }
        require_once $autoload;

        $config = mailConfig();
        if ($config['username'] === '' || $config['password'] === '') {
            throw new RuntimeException('Email service is not configured. Set the Gmail SMTP environment variables.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->Port = $config['port'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $purpose === 'registration' ? 'Verify your OCA account email' : 'Your OCA password recovery code';

            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            $minutes = (int)ceil($expiresInSeconds / 60);
            $heading = $purpose === 'registration' ? 'Verify your email address' : 'Password recovery request';
            $mail->Body = '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
                . '<body style="margin:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#243047">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 12px">'
                . '<tr><td align="center"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e7ec">'
                . '<tr><td style="background:#c93636;padding:24px 28px;color:#ffffff"><div style="font-size:22px;font-weight:bold">OCA Notification System</div><div style="font-size:13px;margin-top:5px;opacity:.9">Culture and Arts Management System</div></td></tr>'
                . '<tr><td style="padding:32px 28px"><h1 style="font-size:24px;line-height:1.3;margin:0 0 12px;color:#243047">' . $heading . '</h1>'
                . '<p style="font-size:15px;line-height:1.6;margin:0 0 22px;color:#526070">Use the verification code below to continue. This code is valid for <strong>' . $minutes . ' minutes</strong>.</p>'
                . '<div style="background:#fff1f1;border:1px solid #f3c1c1;border-radius:10px;text-align:center;padding:20px;margin:0 0 24px"><div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#8b1e1e;margin-bottom:8px">One-time code</div><div style="font-size:32px;line-height:1.2;font-weight:bold;letter-spacing:8px;color:#c93636">' . $safeOtp . '</div></div>'
                . '<p style="font-size:14px;line-height:1.6;margin:0;color:#526070">For your security, this code can only be used once. If you did not request this, ignore this email and do not share the code.</p></td></tr>'
                . '<tr><td style="border-top:1px solid #edf0f3;padding:18px 28px;color:#7a8492;font-size:12px;line-height:1.5">This is an automated message from the OCA Notification System. Please do not reply.</td></tr>'
                . '</table></td></tr></table></body></html>';
            $mail->AltBody = "OCA Notification System\n\n{$heading}\n\nYour one-time verification code is: {$otp}\n\nThis code expires in {$minutes} minutes and can only be used once. If you did not request this, ignore this email and do not share the code.";
            $mail->send();
        } catch (Exception $exception) {
            throw new RuntimeException('The verification email could not be sent. Please try again later.', 0, $exception);
        }
    }
}
