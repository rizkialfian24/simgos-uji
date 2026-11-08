<?php
/**
 * simgos-uji — isi QR TTE bawaan pada template RS (<KODE>-*.jrxml) menjadi teks tetap + (opsional) ID dokumen.
 * Dipanggil oleh qr-teks.sh. Hanya ekspresi QR yang diubah; database tidak disentuh; tidak ada data pasien di QR.
 *
 * QR yang disasar (di berkas biasa, bukan tautan), ekspresi persis salah satu dari:
 *   $P{REQUEST_REPORT_ID}                                         (bawaan: hanya kode)
 *   "ID : "+ $P{REQUEST_REPORT_ID} + "\n" + $F{INFO_DOC}          (bawaan acuan / hasil qr-info-doc)
 *   /*simgos-qr-teks*\/ ...                                        (hasil alat ini sebelumnya -> teks bisa diganti lagi)
 * QR lain (NOPEN, PERAWAT_QR, dll.) tidak disentuh.
 */
declare(strict_types=1);

$opt = json_decode((string) stream_get_contents(STDIN), true);
[$MODE, $ROOT, $KODE, $BK, $BARIS, $PAKAI_ID] = [$opt['mode'], rtrim($opt['root'], '/'), $opt['kode'], $opt['backup'] ?? '', $opt['baris'], (bool) $opt['pakai_id']];

const PENANDA = '/*simgos-qr-teks*/';
const QR = '~(<jr:QRCode\b[^>]*>\s*<jr:codeExpression>\s*<!\[CDATA\[)(.*?)(\]\]>\s*</jr:codeExpression>)~s';

function sasaran(string $e): bool {
    $e = trim($e);
    if (str_starts_with($e, PENANDA)) { return true; }
    $n = preg_replace('/\s+/', '', $e);
    return $n === '$P{REQUEST_REPORT_ID}' || $n === '"ID:"+$P{REQUEST_REPORT_ID}+"\n"+$F{INFO_DOC}';
}
/** Literal string Java dari teks yang sudah divalidasi (tanpa " \ ]]> dan karakter kontrol). */
function jstr(string $s): string { return '"' . $s . '"'; }

foreach ($BARIS as $b) {
    if ($b === '' || mb_strlen($b) > 120 || preg_match('/["\\\\\x00-\x1f\x7f]|\]\]>/', $b) || !preg_match('//u', $b)) {
        fwrite(STDERR, "BERHENTI: baris teks tidak valid (kosong, >120 karakter, atau berisi \" \\ ]]>): $b\n"); exit(2);
    }
}
$teks = implode(' + "\n" + ', array_map('jstr', $BARIS));
$EKSPRESI = PENANDA . ' ' . ($PAKAI_ID ? '"ID : " + $P{REQUEST_REPORT_ID} + "\n" + ' : '') . $teks;

$hasil = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
$files = [];
foreach ($it as $f) {
    if ($f->isFile() && !is_link($f->getPathname()) && preg_match('~^' . preg_quote($KODE, '~') . '-.*\.jrxml$~', $f->getFilename())) { $files[] = $f->getPathname(); }
}
sort($files);
$sama = 0;
foreach ($files as $path) {
    $rel = substr($path, strlen($ROOT) + 1);
    $xml = (string) file_get_contents($path);
    $n = 0; $nSama = 0;
    $baru = preg_replace_callback(QR, static function ($m) use (&$n, &$nSama, $EKSPRESI) {
        if (!sasaran($m[2])) { return $m[0]; }
        if (trim($m[2]) === $EKSPRESI) { $nSama++; return $m[0]; }
        $n++; return $m[1] . $EKSPRESI . $m[3];
    }, $xml);
    if ($n === 0) { if ($nSama > 0) { $sama++; } continue; }
    $d = new DOMDocument(); $prev = libxml_use_internal_errors(true); $ok = $d->loadXML($baru, LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) { fwrite(STDERR, "  LEWATI  $rel: hasil XML tidak valid\n"); continue; }
    $hasil[] = [$rel, $n, hash('sha256', $xml), hash('sha256', $baru)];
    if ($MODE === 'terapkan') {
        $dst = $BK . '/' . $rel;
        if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0700, true); }
        if (!copy($path, $dst)) { fwrite(STDERR, "BERHENTI: cadangan $rel gagal\n"); exit(1); }
        if (file_put_contents($path, $baru, LOCK_EX) !== strlen($baru)) { fwrite(STDERR, "BERHENTI: gagal menulis $rel (cadangan: $dst)\n"); exit(1); }
    }
}

echo "Isi QR baru:\n";
foreach (array_merge($PAKAI_ID ? ['ID : <kode dokumen>'] : [], $BARIS) as $b) { echo "  | $b\n"; }
printf("Template RS %s-*: %d diubah%s.\n", $KODE, count($hasil), $sama ? ", $sama sudah berisi teks ini" : '');
foreach ($hasil as [$rel, $n]) { printf("  UBAH    %s%s\n", $rel, $n > 1 ? " ($n QR)" : ''); }
if ($MODE === 'terapkan') {
    file_put_contents($BK . '/MANIFEST.tsv', "# berkas\tsha256_sebelum\tsha256_sesudah\n"
        . implode("\n", array_map(fn($r) => "$r[0]\t$r[2]\t$r[3]", $hasil)) . "\n");
    echo "Cadangan & manifest: $BK\n";
}
