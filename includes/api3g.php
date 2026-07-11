<?php
function parseApi3gMobileOptions(string $xmlString): array {
    $result = ['success' => false, 'terminals' => [], 'error' => null];
    libxml_use_internal_errors(true);
    $s = trim($xmlString);
    if ($s === '') {
        $result['error'] = 'Empty payload';
        return $result;
    }
    $xml = simplexml_load_string($s);
    if ($xml === false) {
        $errors = libxml_get_errors();
        $msg = [];
        foreach ($errors as $e) { $msg[] = trim($e->message); }
        libxml_clear_errors();
        $result['error'] = implode('; ', $msg);
        return $result;
    }

    // Navigate to paymentoptionsmobile -> terminalmobile (supports multiple)
    $nodes = [];
    if (isset($xml->paymentoptionsmobile) && isset($xml->paymentoptionsmobile->terminalmobile)) {
        $nodes = $xml->paymentoptionsmobile->terminalmobile;
    } elseif (isset($xml->paymentoptionsmobile)) {
        // fallback if structure slightly different
        foreach ($xml->paymentoptionsmobile->children() as $child) {
            if ($child->getName() === 'terminalmobile') $nodes[] = $child;
        }
    }

    foreach ($nodes as $node) {
        $t = [];
        $t['terminaltype'] = (string)($node->terminaltype ?? '');
        $t['terminalmno'] = (string)($node->terminalmno ?? '');
        $t['terminalmnocountry'] = (string)($node->terminalmnocountry ?? '');
        $t['terminalredirecturi'] = (string)($node->terminalredirecturi ?? '');
        $result['terminals'][] = $t;
    }

    $result['success'] = true;
    return $result;
}
