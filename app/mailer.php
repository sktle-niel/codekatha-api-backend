<?php
// Email notifications via PHPMailer (vendored under app/PHPMailer — no Composer).
// The submission is always saved to MySQL first; sending the email is a
// best-effort step, so a missing/invalid SMTP config never breaks the API.

use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

function budget_label(string $id, string $custom): string
{
    $map = [
        '3-5k'   => 'PHP 3,000 - 5,000',
        '5-10k'  => 'PHP 5,000 - 10,000',
        '10-20k' => 'PHP 10,000 - 20,000',
        '20-50k' => 'PHP 20,000 - 50,000',
    ];
    if ($id === 'custom') {
        return $custom !== '' ? $custom : 'Custom budget';
    }
    return $map[$id] ?? ($id !== '' ? $id : '—');
}

function system_label(string $id): string
{
    return [
        'website' => 'Website / Web System',
        'desktop' => 'Desktop App',
        'mobile'  => 'Mobile App',
    ][$id] ?? ($id !== '' ? $id : '—');
}

function service_label(string $id): string
{
    return [
        'business-website' => 'Business Website',
        'landing-page'     => 'Landing Page',
        'web-system'       => 'Web System / Portal',
        'inventory'        => 'Inventory System',
        'booking'          => 'Booking & Services',
        'other'            => 'Something else',
    ][$id] ?? ($id !== '' ? $id : '—');
}

// Flat label/value list used for the plain-text version of the email.
function request_rows(array $r): array
{
    $isCapstone = ($r['path'] === 'capstone');
    $rows = [
        'Reference' => $r['reference'],
        'For'       => $isCapstone ? 'Capstone / Thesis' : 'Business',
    ];

    if ($isCapstone) {
        $rows['System'] = system_label((string) $r['system_type']);
        if (!empty($r['project_title'])) $rows['Title'] = $r['project_title'];
        if (!empty($r['deadline']))      $rows['Deadline'] = $r['deadline'];
    } else {
        $rows['Need'] = service_label((string) $r['service']);
        if (!empty($r['business_name'])) $rows['Business'] = $r['business_name'];
        if (!empty($r['industry']))      $rows['Industry'] = $r['industry'];
        if (!empty($r['has_existing']))  $rows['Existing site'] = $r['has_existing'];
    }

    $rows['Budget'] = budget_label((string) $r['budget'], (string) $r['custom_budget']);
    $rows['Name']   = $r['name'];
    $rows['Email']  = $r['email'];
    if (!empty($r['phone'])) $rows['Phone'] = $r['phone'];
    if (!empty($r['org']))   $rows[$isCapstone ? 'School' : 'Company'] = $r['org'];

    return $rows;
}

// Render an associative array as an email-safe two-column table.
function render_rows(array $rows): string
{
    $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
    foreach ($rows as $k => $v) {
        $out .= '<tr>'
            . '<td style="padding:10px 0;border-bottom:1px solid #efeee9;color:#6f6f6a;'
            . 'font-size:13px;width:160px;vertical-align:top;">' . htmlspecialchars((string) $k) . '</td>'
            . '<td style="padding:10px 0;border-bottom:1px solid #efeee9;color:#18181b;'
            . 'font-size:14px;font-weight:600;vertical-align:top;">'
            . nl2br(htmlspecialchars((string) $v)) . '</td>'
            . '</tr>';
    }
    return $out . '</table>';
}

function build_email_html(array $r): string
{
    $isCapstone = ($r['path'] === 'capstone');

    $project = ['Type' => $isCapstone ? 'Capstone / Thesis' : 'Business'];
    if ($isCapstone) {
        $project['System']         = system_label((string) $r['system_type']);
        if (!empty($r['project_title'])) $project['Project title'] = $r['project_title'];
        if (!empty($r['deadline']))      $project['Target deadline'] = $r['deadline'];
    } else {
        $project['Needs']          = service_label((string) $r['service']);
        if (!empty($r['business_name'])) $project['Business name'] = $r['business_name'];
        if (!empty($r['industry']))      $project['Industry'] = $r['industry'];
        if (!empty($r['has_existing']))  $project['Existing website'] = $r['has_existing'];
    }
    $project['Budget'] = budget_label((string) $r['budget'], (string) $r['custom_budget']);

    $contact = ['Name' => $r['name'], 'Email' => $r['email']];
    if (!empty($r['phone'])) $contact['Phone / Messenger'] = $r['phone'];
    if (!empty($r['org']))   $contact[$isCapstone ? 'School' : 'Company'] = $r['org'];

    $desc = nl2br(htmlspecialchars((string) ($r['description'] ?? ''))) ?: '—';
    $ref  = htmlspecialchars((string) $r['reference']);
    $type = $isCapstone ? 'Capstone / Thesis' : 'Business';
    $when = htmlspecialchars($r['submitted'] ?? '');
    $clientName = htmlspecialchars((string) $r['name']);

    $section = function (string $title, string $inner): string {
        return '<tr><td style="padding:22px 28px 0 28px;">'
            . '<p style="margin:0 0 6px;color:#9a9a96;font-size:11px;letter-spacing:1.5px;'
            . 'text-transform:uppercase;font-family:Arial,Helvetica,sans-serif;">' . $title . '</p>'
            . $inner . '</td></tr>';
    };

    return '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#ffffff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr>
      <td style="background:#18181b;padding:20px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
          <td style="font-size:17px;font-weight:800;letter-spacing:-0.3px;color:#ffffff;">
            CODEKATHA<span style="color:#c2f000;">X</span>
          </td>
          <td align="right" style="color:#9a9a96;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">
            Project Inquiry
          </td>
        </tr></table>
      </td>
    </tr>

    <tr>
      <td style="padding:26px 28px 4px 28px;">
        <p style="margin:0 0 4px;color:#18181b;font-size:16px;font-weight:700;">New project inquiry</p>
        <p style="margin:0;color:#6f6f6a;font-size:14px;line-height:1.6;">
          ' . $clientName . ' submitted a request through your website. The full details are below.
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 28px 0 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
               style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
          <tr>
            <td style="padding:12px 16px;">
              <span style="color:#6f6f6a;font-size:12px;">Reference</span><br>
              <span style="color:#18181b;font-size:15px;font-weight:700;">' . $ref . '</span>
            </td>
            <td style="padding:12px 16px;border-left:1px solid #eceae5;">
              <span style="color:#6f6f6a;font-size:12px;">Type</span><br>
              <span style="color:#18181b;font-size:15px;font-weight:700;">' . $type . '</span>
            </td>
            <td style="padding:12px 16px;border-left:1px solid #eceae5;">
              <span style="color:#6f6f6a;font-size:12px;">Submitted</span><br>
              <span style="color:#18181b;font-size:14px;font-weight:600;">' . $when . '</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    ' . $section('Project details', render_rows($project)) . '
    ' . $section('Message', '<div style="color:#18181b;font-size:14px;line-height:1.7;">' . $desc . '</div>') . '
    ' . $section('Client contact', render_rows($contact)) . '

    <tr>
      <td style="padding:22px 28px 26px 28px;">
        <p style="margin:0;color:#6f6f6a;font-size:13px;line-height:1.6;">
          You can reply directly to this email to respond to '
            . htmlspecialchars((string) $r['name']) . '.
        </p>
      </td>
    </tr>

    <tr>
      <td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
        Sent automatically from the CODEKATHAX website &middot; codekathax.com
      </td>
    </tr>
  </table>
</div>';
}

function send_request_email(array $mail, array $r): bool
{
    // Not configured yet → skip silently (data is already in the database).
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }

    // Stamp the submission time in Manila time for the email.
    $tz = new DateTimeZone('Asia/Manila');
    $r['submitted'] = (new DateTime('now', $tz))->format('M j, Y · g:i A');

    $html = build_email_html($r);

    $textLines = [];
    foreach (request_rows($r) as $k => $v) {
        $textLines[] = "$k: $v";
    }
    $textLines[] = '';
    $textLines[] = 'Message:';
    $textLines[] = (string) ($r['description'] ?? '—');
    $text = implode("\n", $textLines);

    $isCapstone = ($r['path'] === 'capstone');

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host       = $mail['host'];
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $mail['user'];
        $mailer->Password   = $mail['pass'];
        $mailer->SMTPSecure = $mail['secure'] === 'tls' ? 'tls' : 'ssl';
        $mailer->Port       = $mail['port'];
        $mailer->CharSet    = 'UTF-8';

        $mailer->setFrom($mail['from'], $mail['from_name']);
        $mailer->addAddress($mail['to']);
        if (!empty($r['email'])) {
            $mailer->addReplyTo($r['email'], $r['name'] !== '' ? $r['name'] : $r['email']);
        }

        $mailer->Subject = 'New project inquiry — ' . $r['reference']
            . ' (' . ($isCapstone ? 'Capstone / Thesis' : 'Business') . ')';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;

        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX mail failed: ' . $e->getMessage());
        return false;
    }
}
