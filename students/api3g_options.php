<?php
require_once __DIR__ . '/../includes/api3g.php';

// Reads raw POST body (XML) or uses example XML when none provided
$raw = file_get_contents('php://input');
if (trim($raw) === '') {
    // Example payload (from user)
    $raw = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<API3G>\n  <paymentoptionsmobile>\n    <terminalmobile>\n      <terminalredirecturi>0</terminalredirecturi>\n      <terminaltype>Mobile</terminaltype>\n      <terminalmno>WUC</terminalmno>\n      <terminalmnocountry>Kenya</terminalmnocountry>\n    </terminalmobile>\n    <terminalmobile>\n      <terminalredirecturi>0</terminalredirecturi>\n      <terminaltype>CL</terminaltype>\n      <terminalmnocountry>zambia</terminalmnocountry>\n      <terminalmno>airtel</terminalmno>\n    </terminalmobile>\n  </paymentoptionsmobile>\n</API3G>";
}

$parsed = parseApi3gMobileOptions($raw);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($parsed, JSON_PRETTY_PRINT);
