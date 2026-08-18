<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/tcpdf/tcpdf_barcodes_2d.php';

if (!function_exists('wuc_qr_svg_data_uri')) {
    /** Generate a self-contained QR image with the bundled TCPDF encoder. */
    function wuc_qr_svg_data_uri(string $payload, int $moduleSize = 4): string
    {
        $payload = trim($payload);
        if ($payload === '' || strlen($payload) > 2048) {
            return '';
        }
        $barcode = new TCPDF2DBarcode($payload, 'QRCODE,H');
        $svg = $barcode->getBarcodeSVGcode(max(2, $moduleSize), max(2, $moduleSize), 'black');
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

if (!function_exists('wuc_public_app_url')) {
    function wuc_public_app_url(string $path): string
    {
        $configured = trim((string)(getenv('WUC_PUBLIC_BASE_URL') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}
