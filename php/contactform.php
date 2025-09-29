<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

/** Load .env */
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

/** Timezone */
date_default_timezone_set(envv('APP_TIMEZONE', 'Europe/Amsterdam'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // === 1) Input ===
    $groep            = $_POST['Groep']                   ?? '';
    $naamDeelnemer    = $_POST['naamDeelnemer']           ?? '';
    $telDeelnemer     = $_POST['telefoonnummerDeelnemer'] ?? '';
    $mailDeelnemer    = $_POST['emailDeelnemer']          ?? '';
    $iban             = $_POST['iBAN']                    ?? '';
    $naamOuder        = $_POST['naamOuder']               ?? '';
    $telOuder         = $_POST['telefoonnummerOuder']     ?? '';
    $mailOuder        = $_POST['emailOuder']              ?? '';

    // === 2) Mailinhoud ===
    $subject = "Inschrijving Eendaagse kerkkamp 11/10/2025";
    $body = "Je hebt een aanmelding ontvangen.\n\n"
          . "Voor- en Achternaam deelnemer:\n$naamDeelnemer\n\n"
          . "Groep:\n$groep\n\n"
          . "Telefoonnummer deelnemer:\n$telDeelnemer\n\n"
          . "Email deelnemer:\n$mailDeelnemer\n\n"
          . "IBAN:\n$iban\n\n"
          . "Naam ouder (optioneel):\n$naamOuder\n\n"
          . "Telefoon ouder (optioneel):\n$telOuder\n\n"
          . "Email ouder (optioneel):\n$mailOuder\n\n";

    // === 3) PHPMailer via SMTP met .env ===
    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host       = envv('SMTP_HOST', 'smtp.office365.com');
        $mailer->SMTPAuth   = true;
        $secure             = strtolower((string)envv('SMTP_SECURE', 'starttls')); // starttls|ssl
        if ($secure === 'ssl' || $secure === 'smtps') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mailer->Port       = (int)envv('SMTP_PORT', ($mailer->SMTPSecure === PHPMailer::ENCRYPTION_SMTPS ? 465 : 587));
        $mailer->Username   = envv('SMTP_USER');            // verplicht
        $mailer->Password   = envv('SMTP_PASS');            // verplicht (bij voorkeur app-wachtwoord)
        $mailer->CharSet    = 'UTF-8';
        $mailer->Timeout    = 20; // seconden

        // From / Reply-To
        $fromEmail = envv('SMTP_FROM_EMAIL', envv('SMTP_USER'));
        $fromName  = envv('SMTP_FROM_NAME', 'Formulier Kerkkamp');
        $mailer->setFrom($fromEmail, $fromName);

        if (filter_var($mailDeelnemer, FILTER_VALIDATE_EMAIL)) {
            $mailer->addReplyTo($mailDeelnemer, $naamDeelnemer ?: 'Deelnemer');
        }

        // Ontvangers (admin(s))
        $admins = csv_to_array(envv('ADMIN_EMAILS', envv('ADMIN_EMAIL', '')));
        if (empty($admins)) {
            // fallback: als niets gezet is, stuur naar from
            $admins = [$fromEmail];
        }
        foreach ($admins as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mailer->addAddress($addr);
            }
        }

        $mailer->Subject = $subject;
        $mailer->Body    = $body;
        $mailer->AltBody = $body;

        // === 4) User snel doorsturen; mail op de achtergrond afmaken ===
        $paymentUrl = envv('PAYMENT_URL_29');
        if (!$paymentUrl) {
            // fallback naar lokale html
            $paymentUrl = '../html/payment.html';
        }

        // Stuur de browser alvast door
        header('Location: ' . $paymentUrl);
        header('Connection: close');
        ignore_user_abort(true);
        ob_start();
        echo 'OK';
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();

        // Nu pas daadwerkelijk mailen (browser is al weg)
        $mailer->send();

    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mailer->ErrorInfo);
        // Als mail faalt, sturen we de user alsnog door:
        header('Location: ' . ($paymentUrl ?? '../html/payment.html'));
    }
    exit;
}