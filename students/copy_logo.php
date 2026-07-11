<?php
// Simple utility to duplicate the logo for watermark usage
$src = __DIR__ . '/images/LOGO2.jpeg';
$dst = __DIR__ . '/images/LOGO2_wm.jpeg';
if (!file_exists($src)) {
    echo "Source file not found: $src\n";
    exit(1);
}
if (copy($src, $dst)) {
    echo "Created watermark copy: $dst\n";
    exit(0);
} else {
    echo "Failed to copy logo to: $dst\n";
    exit(2);
}
