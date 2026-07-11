<?php
// One-off: strip leftover color classes from already-normalized theads.
$root = dirname(__DIR__);
$dirs = ['admin','admissions','accounts','registrar','lecturers','students','transport','vc','elearning','hod','dean','library','online_services'];
$map = [
    '<thead class="table-light w3-purple"' => '<thead class="table-light"',
    '<thead class="table-light #"'         => '<thead class="table-light"',
    '<thead class="table-light bg-white"'  => '<thead class="table-light"',
    '<thead class="bg-primary"'            => '<thead class="table-light"',
];
$n = 0;
foreach ($dirs as $d) {
    $base = $root . '/' . $d;
    if (!is_dir($base)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = $f->getPathname();
        if (preg_match('/backup|node_modules|vendor/i', $p)) continue;
        $s = file_get_contents($p);
        $o = $s;
        $s = strtr($s, $map);
        if ($s !== $o) { file_put_contents($p, $s); $n++; echo $p, PHP_EOL; }
    }
}
echo "cleaned {$n} files\n";
