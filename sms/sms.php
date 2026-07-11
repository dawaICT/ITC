<?php
session_start();

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$apiKey = (string)(getenv('VONAGE_API_KEY') ?: '1b8ae737');
$apiSecret = (string)(getenv('VONAGE_API_SECRET') ?: 'fITUk7DCf5UgWlNy');
$from = (string)(getenv('VONAGE_FROM') ?: 'LVTC');
$to = (string)(getenv('VONAGE_TO') ?: '260974735097');

if (!class_exists('\Vonage\Client') || !class_exists('\Vonage\SMS\Message\SMS')) {
    echo "Vonage SDK is not installed.\n";
    return;
}

try {
    $basic = new \Vonage\Client\Credentials\Basic($apiKey, $apiSecret);
    $client = new \Vonage\Client($basic);
    $response = $client->sms()->send(
        new \Vonage\SMS\Message\SMS($to, $from, 'A text message sent using the Nexmo SMS API')
    );

    $message = $response->current();
    if ((int)$message->getStatus() === 0) {
        echo "The message was sent successfully\n";
    } else {
        echo "The message failed with status: " . $message->getStatus() . "\n";
    }
} catch (Throwable $e) {
    error_log('sms/sms.php: ' . $e->getMessage());
    echo "Unable to send SMS right now.\n";
}

