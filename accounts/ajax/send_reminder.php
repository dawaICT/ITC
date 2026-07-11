<?php
// Sends AR reminder via SMS or Email for a given SID
header('Content-Type: application/json');

require_once __DIR__ . '/../../db/connect.php';

$sid = isset($_POST['sid']) ? trim($_POST['sid']) : '';
$channel = isset($_POST['channel']) ? trim($_POST['channel']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($sid === '' || $channel === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Look up student contact details
$stmt = $db->prepare("SELECT SID, Fname, Lname, email, phone, mobile FROM students WHERE SID = ?");
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Failed to prepare contact lookup']);
    exit;
}
$stmt->bind_param('s', $sid);
$stmt->execute();
$res = $stmt->get_result();
$student = $res->fetch_assoc();
$stmt->close();

if (!$student) {
    echo json_encode(['ok' => false, 'error' => 'Student not found']);
    exit;
}

$fullName = $student['Fname'] . ' ' . $student['Lname'];
$info = null;

try {
    if ($channel === 'sms') {
        // Prefer phone then mobile
        $to = $student['phone'] ?: ($student['mobile'] ?: '');
        if ($to === '') {
            echo json_encode(['ok' => false, 'error' => 'No phone/mobile on record']);
            exit;
        }
        // Send via Vonage (Nexmo). Ensure composer autoload is present if used in other areas.
        require_once __DIR__ . '/../../vendor/autoload.php';
        $apiKey = getenv('VONAGE_API_KEY') ?: '';
        $apiSecret = getenv('VONAGE_API_SECRET') ?: '';
        $fromName = getenv('VONAGE_FROM') ?: 'ITC';
        if ($apiKey === '' || $apiSecret === '') {
            echo json_encode(['ok' => false, 'error' => 'SMS provider not configured']);
            exit;
        }
        $basic = new \Vonage\Client\Credentials\Basic($apiKey, $apiSecret);
        $client = new \Vonage\Client($basic);
        $sms = new \Vonage\SMS\Message\SMS($to, $fromName, $message !== '' ? $message : ("Dear $fullName, please settle your outstanding balance. - Accounts"));
        $response = $client->sms()->send($sms);
        $msg = $response->current();
        if ($msg->getStatus() == 0) {
            $info = 'SMS sent to ' . $to;
        } else {
            echo json_encode(['ok' => false, 'error' => 'SMS failed: status ' . $msg->getStatus()]);
            exit;
        }
    } elseif ($channel === 'email') {
        $toEmail = $student['email'] ?: '';
        if ($toEmail === '') {
            echo json_encode(['ok' => false, 'error' => 'No email on record']);
            exit;
        }
        require_once __DIR__ . '/../../vendor/autoload.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        // Basic transport: use mail() or configure SMTP via env
        $smtpHost = getenv('SMTP_HOST');
        $smtpUser = getenv('SMTP_USER');
        $smtpPass = getenv('SMTP_PASS');
        $smtpPort = getenv('SMTP_PORT') ?: 587;
        $smtpSecure = getenv('SMTP_SECURE') ?: 'tls';
        if ($smtpHost && $smtpUser && $smtpPass) {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = $smtpSecure;
            $mail->Port = (int)$smtpPort;
        }
        $fromAddress = getenv('MAIL_FROM') ?: 'no-reply@wuc.edu.zm';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'ITC Accounts';
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail, $fullName);
        $mail->Subject = 'Payment Reminder - Industrial training college';
        $mail->isHTML(true);
        $body = $message !== '' ? nl2br(htmlspecialchars($message)) : 'Dear ' . htmlspecialchars($fullName) . ',<br/>Please settle your outstanding balance. Thank you.';
        $mail->Body = $body;
        $mail->AltBody = strip_tags($message !== '' ? $message : 'Please settle your outstanding balance. Thank you.');
        $mail->send();
        $info = 'Email sent to ' . $toEmail;
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unsupported channel']);
        exit;
    }

    echo json_encode(['ok' => true, 'info' => $info]);
} catch (Exception $e) {
    error_log('send_reminder error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to send reminder. Please try again.']);
}


