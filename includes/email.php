<?php
/**
 * Email Notification System — Fleckfrei
 * HTML-Emails mit White-Label aus config.php
 */

function sendEmail($to, $subject, $bodyHtml, $replyTo = null) {
    if (empty($to)) return false;

    $from = SITE . ' <noreply@' . SITE_DOMAIN . '>';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from,
        'X-Mailer: ' . SITE . '/Admin',
    ];
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;

    return @mail($to, $subject, $bodyHtml, implode("\r\n", $headers));
}

function emailTemplate($title, $content, $ctaText = '', $ctaUrl = '') {
    $brand = BRAND;
    $site = SITE;
    $domain = SITE_DOMAIN;
    $email = CONTACT_EMAIL;
    $phone = CONTACT_PHONE;
    $year = date('Y');

    $ctaBlock = '';
    if ($ctaText && $ctaUrl) {
        $ctaBlock = "<div style='text-align:center;margin:30px 0'><a href='$ctaUrl' style='display:inline-block;padding:14px 32px;background:$brand;color:white;text-decoration:none;border-radius:10px;font-weight:600;font-size:15px'>$ctaText</a></div>";
    }

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width"/></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:'Helvetica Neue',Arial,sans-serif">
<div style="max-width:600px;margin:0 auto;padding:20px">
  <div style="text-align:center;padding:30px 0">
    <div style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:12px;background:$brand;color:white;font-size:22px;font-weight:700;text-align:center">F</div>
    <div style="font-size:20px;font-weight:700;color:#1f2937;margin-top:8px">$site</div>
  </div>
  <div style="background:white;border-radius:16px;padding:40px;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
    <h2 style="font-size:20px;font-weight:600;color:#1f2937;margin:0 0 20px">$title</h2>
    <div style="font-size:15px;line-height:1.6;color:#4b5563">$content</div>
    $ctaBlock
  </div>
  <div style="text-align:center;padding:20px;font-size:12px;color:#9ca3af">
    <p>$site &middot; $email &middot; $domain</p>
    <p>&copy; $year $site. Alle Rechte vorbehalten.</p>
  </div>
</div>
</body></html>
HTML;
}

// ═══════════════════════════════════════
// Notification Functions
// ═══════════════════════════════════════

function notifyBookingConfirmation($jobId) {
    $job = one("SELECT j.*, c.name as cname, c.email as cemail, s.title as stitle, e.name as ename
        FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.j_id=?", [$jobId]);
    if (!$job || !$job['cemail']) return false;

    $date = date('d.m.Y', strtotime($job['j_date']));
    $time = substr($job['j_time'], 0, 5);
    $hours = $job['j_hours'];
    $service = $job['stitle'] ?: 'Service';
    $partner = $job['ename'] ?: 'wird zugewiesen';
    $address = $job['address'] ?: '';

    $content = "<p>Vielen Dank für Ihre Buchung!</p>
    <table style='width:100%;border-collapse:collapse;margin:20px 0'>
      <tr><td style='padding:8px 0;color:#6b7280;width:120px'>Service:</td><td style='padding:8px 0;font-weight:600'>$service</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Datum:</td><td style='padding:8px 0;font-weight:600'>$date um $time Uhr</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Dauer:</td><td style='padding:8px 0;font-weight:600'>{$hours}h</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Partner:</td><td style='padding:8px 0'>{$partner}</td></tr>"
      . ($address ? "<tr><td style='padding:8px 0;color:#6b7280'>Adresse:</td><td style='padding:8px 0'>{$address}</td></tr>" : '') .
    "</table>
    <p style='color:#6b7280'>Bei Fragen erreichen Sie uns per WhatsApp oder E-Mail.</p>";

    $html = emailTemplate('Buchungsbestätigung', $content, 'Meine Buchungen', 'https://app.' . SITE_DOMAIN . '/customer/');
    return sendEmail($job['cemail'], SITE . ' — Buchungsbestätigung #' . $jobId, $html);
}

function notifyJobStarted($jobId) {
    $job = one("SELECT j.*, c.name as cname, c.email as cemail, s.title as stitle, e.name as ename
        FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.j_id=?", [$jobId]);
    if (!$job || !$job['cemail']) return false;

    $date = date('d.m.Y', strtotime($job['j_date']));
    $time = date('H:i');
    $partner = $job['ename'] ?: 'Unser Partner';

    $content = "<p><strong>{$partner}</strong> hat den Job gestartet.</p>
    <table style='width:100%;border-collapse:collapse;margin:20px 0'>
      <tr><td style='padding:8px 0;color:#6b7280;width:120px'>Service:</td><td style='padding:8px 0;font-weight:600'>" . ($job['stitle'] ?: 'Service') . "</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Gestartet:</td><td style='padding:8px 0;font-weight:600'>$date um $time Uhr</td></tr>
    </table>";

    return sendEmail($job['cemail'], SITE . ' — Job gestartet', emailTemplate('Job gestartet', $content));
}

function notifyJobCompleted($jobId) {
    $job = one("SELECT j.*, c.name as cname, c.email as cemail, s.title as stitle, e.name as ename
        FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.j_id=?", [$jobId]);
    if (!$job || !$job['cemail']) return false;

    $date = date('d.m.Y', strtotime($job['j_date']));
    $hours = round($job['total_hours'] ?: $job['j_hours'], 1);

    $content = "<p>Ihr Job wurde erfolgreich abgeschlossen!</p>
    <table style='width:100%;border-collapse:collapse;margin:20px 0'>
      <tr><td style='padding:8px 0;color:#6b7280;width:120px'>Service:</td><td style='padding:8px 0;font-weight:600'>" . ($job['stitle'] ?: 'Service') . "</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Datum:</td><td style='padding:8px 0;font-weight:600'>$date</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Dauer:</td><td style='padding:8px 0;font-weight:600'>{$hours}h</td></tr>
    </table>
    <p style='color:#6b7280'>Die Rechnung wird automatisch erstellt und Ihnen zugesandt.</p>";

    return sendEmail($job['cemail'], SITE . ' — Job abgeschlossen', emailTemplate('Job abgeschlossen', $content, 'Bewertung abgeben', 'https://wa.me/' . CONTACT_WA));
}

function notifyInvoiceCreated($invoiceId) {
    $inv = one("SELECT i.*, c.name as cname, c.email as cemail
        FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invoiceId]);
    if (!$inv || !$inv['cemail']) return false;

    $total = number_format((float)$inv['total_price'], 2, ',', '.') . ' ' . CURRENCY;
    $date = date('d.m.Y', strtotime($inv['issue_date']));

    $content = "<p>Ihre Rechnung ist bereit.</p>
    <table style='width:100%;border-collapse:collapse;margin:20px 0'>
      <tr><td style='padding:8px 0;color:#6b7280;width:140px'>Rechnungsnr.:</td><td style='padding:8px 0;font-weight:600'>" . htmlspecialchars($inv['invoice_number']) . "</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Datum:</td><td style='padding:8px 0'>$date</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Betrag:</td><td style='padding:8px 0;font-weight:700;font-size:18px;color:" . BRAND . "'>$total</td></tr>
    </table>
    <p style='color:#6b7280'>Sie können die Rechnung im Kundenportal einsehen und herunterladen.</p>";

    $html = emailTemplate('Neue Rechnung', $content, 'Rechnung ansehen', 'https://app.' . SITE_DOMAIN . '/customer/invoices.php');
    return sendEmail($inv['cemail'], SITE . ' — Rechnung ' . $inv['invoice_number'], $html);
}

function notifyJobReminder($jobId) {
    $job = one("SELECT j.*, c.name as cname, c.email as cemail, s.title as stitle, e.name as ename
        FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.j_id=?", [$jobId]);
    if (!$job || !$job['cemail']) return false;

    $date = date('d.m.Y', strtotime($job['j_date']));
    $time = substr($job['j_time'], 0, 5);

    $content = "<p>Erinnerung: Morgen steht ein Termin an!</p>
    <table style='width:100%;border-collapse:collapse;margin:20px 0'>
      <tr><td style='padding:8px 0;color:#6b7280;width:120px'>Service:</td><td style='padding:8px 0;font-weight:600'>" . ($job['stitle'] ?: 'Service') . "</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Wann:</td><td style='padding:8px 0;font-weight:600'>$date um $time Uhr</td></tr>
      <tr><td style='padding:8px 0;color:#6b7280'>Partner:</td><td style='padding:8px 0'>" . ($job['ename'] ?: 'wird zugewiesen') . "</td></tr>
    </table>
    <p style='color:#6b7280'>Bitte stellen Sie sicher, dass alles vorbereitet ist.</p>";

    return sendEmail($job['cemail'], SITE . ' — Erinnerung: Termin morgen', emailTemplate('Termin-Erinnerung', $content));
}
