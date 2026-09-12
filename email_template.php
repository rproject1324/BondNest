<?php
// Shared branded HTML email template for BondNest OTP emails.
//
// Used as the `htmlContent` payload for Brevo transactional mail
// (signup verification, registration resend, password reset, and
// settings email-change). Table-based layout with inline styles so it
// renders consistently across email clients (Gmail, Outlook, mobile).

function bondOtpEmailHtml($title, $name, $intro, $code, $outroLines = []) {
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $outroHtml = '';
    foreach ((array)$outroLines as $line) {
        $outroHtml .= "<p style='margin:0 0 8px 0;font-size:13px;line-height:1.6;color:#5f6b6b;'>" . $e($line) . "</p>";
    }

    return "<html><body style='margin:0;padding:0;background-color:#f2f5f5;'>"
        . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background-color:#f2f5f5;padding:24px 12px;'>"
        . "<tr><td align='center'>"
        . "<table role='presentation' width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%;background-color:#ffffff;border-radius:10px;border:1px solid #dfe9e9;'>"
        . "<tr><td align='center' style='background-color:#008080;padding:22px 24px;'>"
        . "<div style='font-family:Arial,Helvetica,sans-serif;font-size:24px;font-weight:bold;color:#ffffff;letter-spacing:1px;'>BondNest</div>"
        . "</td></tr>"
        . "<tr><td style='padding:28px 32px;font-family:Arial,Helvetica,sans-serif;color:#1e3333;'>"
        . "<h2 style='margin:0 0 12px 0;font-size:20px;color:#1e3333;'>" . $e($title) . "</h2>"
        . "<p style='margin:0 0 8px 0;font-size:14px;line-height:1.6;'>Hello " . $e($name) . ",</p>"
        . "<p style='margin:0 0 16px 0;font-size:14px;line-height:1.6;'>" . $e($intro) . "</p>"
        . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0'><tr>"
        . "<td align='center' style='background-color:#e0f2f1;border:1px dashed #008080;border-radius:8px;padding:18px 12px;font-size:30px;font-weight:bold;letter-spacing:8px;color:#005a5a;'>"
        . $e($code)
        . "</td></tr></table>"
        . "<div style='height:16px;line-height:16px;font-size:16px;'>&nbsp;</div>"
        . $outroHtml
        . "</td></tr>"
        . "<tr><td align='center' style='background-color:#f7fafa;padding:14px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8a9a9a;border-top:1px solid #e3eeee;'>"
        . "This is an automated message from BondNest. Please do not reply."
        . "</td></tr>"
        . "</table>"
        . "</td></tr></table>"
        . "</body></html>";
}
