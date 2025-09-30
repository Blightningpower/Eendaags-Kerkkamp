<?php
/**
 * php/contactform.php
 * - Leest POST-velden
 * - Stuurt een nette HTML-bevestigingsmail naar admin(s)
 * - Redirect direct naar de betaalpagina (mail wordt daarna afgemaakt)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

/** .env laden */
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

/** Helpers */
function envv(string $key, $default = null) {
    return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
}
function csv_to_array(?string $s): array {
    if (!$s) return [];
    return array_values(array_filter(array_map('trim', explode(',', $s))));
}
function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Timezone */
date_default_timezone_set(envv('APP_TIMEZONE', 'Europe/Amsterdam'));

/** Alleen POST-aanvragen afhandelen */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

/** === 1) Invoer ophalen (oudervelden optioneel) === */
$groep            = $_POST['Groep']                   ?? '';
$naamDeelnemer    = $_POST['naamDeelnemer']           ?? '';
$telDeelnemer     = $_POST['telefoonnummerDeelnemer'] ?? '';
$mailDeelnemer    = $_POST['emailDeelnemer']          ?? '';
$iban             = $_POST['iBAN']                    ?? '';
$naamOuder        = $_POST['naamOuder']               ?? '';
$telOuder         = $_POST['telefoonnummerOuder']     ?? '';
$mailOuder        = $_POST['emailOuder']              ?? '';

/** === 2) Onderwerp + nette HTML-body === */
$subject = 'Inschrijving Eendaagse kerkkamp 11/10/2025';
$timestamp = date('Y-m-d H:i:s');

$rows = [
    'Voor- en achternaam deelnemer' => $naamDeelnemer,
    'Groep'                         => $groep,
    'Telefoon deelnemer'            => $telDeelnemer,
    'E-mail deelnemer'              => $mailDeelnemer,
    'IBAN'                          => $iban,
    'Naam ouder (optioneel)'        => $naamOuder,
    'Telefoon ouder (optioneel)'    => $telOuder,
    'E-mail ouder (optioneel)'      => $mailOuder,
];

$plain = "Je hebt een aanmelding ontvangen.\n\n";
foreach ($rows as $label => $val) {
    $plain .= "$label:\n" . (string)$val . "\n\n";
}
$plain .= "Verzonden op: $timestamp\n";

$html = '<!doctype html><html lang="nl"><head><meta charset="utf-8">'.
        '<meta name="viewport" content="width=device-width,initial-scale=1">'.
        '<title>'.h($subject).'</title>'.
        // Simpele, inline-stijl (compatibel met de meeste clients)
        '<style>
            body{margin:0;background:#f6f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827;}
            .wrapper{padding:24px;}
            .card{max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 10px 20px rgba(0,0,0,.06);overflow:hidden;}
            .header{background:#4b2e83;color:#fff;padding:18px 22px;}
            .header h1{margin:0;font-size:18px;font-weight:700;}
            .content{padding:20px 22px;}
            .meta{color:#6b7280;font-size:12px;margin:10px 0 18px;}
            table{width:100%;border-collapse:collapse;}
            th{background:#f3f4f6;text-align:left;font-size:13px;color:#374151;padding:10px;border-bottom:1px solid #e5e7eb;}
            td{padding:10px;border-bottom:1px solid #f3f4f6;font-size:14px;vertical-align:top;}
            tr:hover td{background:#fafafa;}
            .footer{padding:12px 22px;background:#f9fafb;color:#6b7280;font-size:12px;text-align:center;}
        </style>'.
        '</head><body><div class="wrapper"><div class="card">'.
        '<div class="header"><h1>Nieuwe inschrijving – Eendaagse kerkkamp 11/10/2025</h1></div>'.
        '<div class="content">'.
        '<p>Je hebt een aanmelding ontvangen. Hieronder de gegevens:</p>'.
        '<div class="meta">Verzonden op: '.h($timestamp).'</div>'.
        '<table><tbody>';

foreach ($rows as $label => $val) {
    $html .= '<tr><th>'.h($label).'</th><td>'.nl2br(h((string)$val)).'</td></tr>';
}

$html .= '</tbody></table>'.
         '</div>'.
         '<div class="footer">Dit is een automatische melding van het aanmeldformulier.</div>'.
         '</div></div></body></html>';

/** === 3) PHPMailer configureren via .env === */
$mailer = new PHPMailer(true);

try {
    $mailer->isSMTP();
    $mailer->Host       = envv('SMTP_HOST', 'smtp.office365.com');
    $mailer->SMTPAuth   = true;

    $secure = strtolower((string)envv('SMTP_SECURE', 'starttls')); // starttls|ssl|smtps
    if ($secure === 'ssl' || $secure === 'smtps') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $defaultPort = 465;
    } else {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $defaultPort = 587;
    }
    $mailer->Port       = (int)envv('SMTP_PORT', $defaultPort);
    $mailer->Username   = (string)envv('SMTP_USER');
    $mailer->Password   = (string)envv('SMTP_PASS');
    $mailer->CharSet    = 'UTF-8';
    $mailer->Timeout    = 20;
    // Bij sommige providers werkt LOGIN het best:
    $mailer->AuthType   = 'LOGIN';

    // From / Reply-To
    $fromEmail = (string)envv('SMTP_FROM_EMAIL', envv('SMTP_USER'));
    $fromName  = (string)envv('SMTP_FROM_NAME', 'Eendaagse Kerkkamp');
    $mailer->setFrom($fromEmail, $fromName);

    if (filter_var($mailDeelnemer, FILTER_VALIDATE_EMAIL)) {
        $mailer->addReplyTo($mailDeelnemer, $naamDeelnemer ?: 'Deelnemer');
    }

    // Ontvangers (admins)
    $admins = csv_to_array(envv('ADMIN_EMAILS', envv('ADMIN_EMAIL', '')));
    if (empty($admins)) {
        $admins = [$fromEmail]; // fallback
    }
    foreach ($admins as $addr) {
        if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $mailer->addAddress($addr);
        }
    }

    // Inhoud (HTML + tekst-fallback)
    $mailer->Subject = $subject;
    $mailer->isHTML(true);
    $mailer->Body    = $html;
    $mailer->AltBody = $plain;

    /** === 4) Eerst doorsturen naar betalen, daarna mailen === */
    $paymentUrl = envv('PAYMENT_URL_29');
    if (!$paymentUrl) {
        $paymentUrl = '../html/payment.html';
    }

    // Stuur browser meteen door (snelle UX)
    header('Location: ' . $paymentUrl);
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    echo 'OK';
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();

    // Mail versturen nadat de browser al is doorgestuurd
    $mailer->send();

} catch (Exception $e) {
    error_log('Mailer Error: ' . $mailer->ErrorInfo);
    // User alsnog doorsturen bij fout
    $fallbackUrl = isset($paymentUrl) ? $paymentUrl : '../html/payment.html';
    header('Location: ' . $fallbackUrl);
}
exit;