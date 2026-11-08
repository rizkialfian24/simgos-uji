<?php
/**
 * simgos-uji — QR TTE bawaan pada template RS (<KODE>-*.jrxml): isi yang ada dipertahankan, teks petunjuk ditambahkan.
 *   QR ber-INFO_DOC : "ID : <kode>" + INFO_DOC (instansi, pasien, RM, NOPEN, dokumen, tanggal, penanda tangan) + teks
 *   QR hanya kode   : "ID : <kode>" + teks
 * Dipanggil oleh qr-teks.sh. Hanya ekspresi QR yang diubah; database tidak disentuh.
 *
 * QR yang disasar (di berkas biasa, bukan tautan), ekspresi persis salah satu dari:
 *   $P{REQUEST_REPORT_ID}                                         (bawaan: hanya kode)
 *   "ID : "+ $P{REQUEST_REPORT_ID} + "\n" + $F{INFO_DOC}          (bawaan acuan / hasil qr-info-doc)
 *   susunan teks + kode + INFO_DOC buatan alat ini sebelumnya        (teks bisa diganti lagi)
 * QR lain (NOPEN, PERAWAT_QR, dll.) tidak disentuh.
 */
declare(strict_types=1);

$opt = json_decode((string) stream_get_contents(STDIN), true);
[$MODE, $ROOT, $KODE, $BK, $BARIS, $PAKAI_ID] = [$opt['mode'], rtrim($opt['root'], '/'), $opt['kode'], $opt['backup'] ?? '', $opt['baris'], (bool) $opt['pakai_id']];

const QR = '~(<jr:QRCode\b[^>]*>\s*<jr:codeExpression>\s*<!\[CDATA\[)(.*?)(\]\]>\s*</jr:codeExpression>)~s';

/**
 * Jenis QR sasaran: 'info' (memakai $F{INFO_DOC}), 'id' (hanya kode), atau null (bukan sasaran).
 * Dikenali dari SUSUNAN ekspresi (tanpa penanda komentar): hanya literal teks, $P{REQUEST_REPORT_ID}, $F{INFO_DOC},
 * pemeriksaan kosong INFO_DOC, tanda +, ( ), ?, : — mencakup bentuk bawaan SIMGOS dan hasil alat ini sebelumnya.
 */
function sasaran(string $e): ?string {
    if (!str_contains($e, '$P{REQUEST_REPORT_ID}')) { return null; }
    $sisa = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '', $e);                      // buang literal teks
    $sisa = str_replace(['$F{INFO_DOC} != null && !$F{INFO_DOC}.trim().isEmpty()', '$P{REQUEST_REPORT_ID}', '$F{INFO_DOC}'], '', $sisa);
    if (trim(preg_replace('/[\s+()?:]/', '', $sisa)) !== '') { return null; }        // ada bagian lain -> bukan milik kita
    return str_contains($e, '$F{INFO_DOC}') ? 'info' : 'id';
}
/** Literal string Java dari teks yang sudah divalidasi (tanpa " \ ]]> dan karakter kontrol). */
function jstr(string $s): string { return '"' . $s . '"'; }

foreach ($BARIS as $b) {
    if ($b === '' || mb_strlen($b) > 120 || preg_match('/["\\\\\x00-\x1f\x7f]|\]\]>/', $b) || !preg_match('//u', $b)) {
        fwrite(STDERR, "BERHENTI: baris teks tidak valid (kosong, >120 karakter, atau berisi \" \\ ]]>): $b\n"); exit(2);
    }
}
$teks = implode(' + "\n" + ', array_map('jstr', $BARIS));
$awal = $PAKAI_ID ? '"ID : " + $P{REQUEST_REPORT_ID} + "\n" + ' : '';
// INFO_DOC kosong (belum ada catatan TTD) -> barisnya dilewati, bukan tertulis "null".
$EKSPRESI = [
    'info' => $awal . '($F{INFO_DOC} != null && !$F{INFO_DOC}.trim().isEmpty() ? $F{INFO_DOC} + "\n" : "") + ' . $teks,
    'id'   => $awal . $teks,
];

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
    $jenis = [];
    $baru = preg_replace_callback(QR, static function ($m) use (&$n, &$nSama, &$jenis, $EKSPRESI) {
        $j = sasaran($m[2]);
        if ($j === null) { return $m[0]; }
        if (trim($m[2]) === $EKSPRESI[$j]) { $nSama++; return $m[0]; }
        $n++; $jenis[$j] = true; return $m[1] . $EKSPRESI[$j] . $m[3];
    }, $xml);
    if ($n === 0) { if ($nSama > 0) { $sama++; } continue; }
    $d = new DOMDocument(); $prev = libxml_use_internal_errors(true); $ok = $d->loadXML($baru, LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) { fwrite(STDERR, "  LEWATI  $rel: hasil XML tidak valid\n"); continue; }
    $hasil[] = [$rel, $n, hash('sha256', $xml), hash('sha256', $baru), isset($jenis['info']) ? 'data dokumen + teks' : 'ID + teks'];
    if ($MODE === 'terapkan') {
        $dst = $BK . '/' . $rel;
        if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0700, true); }
        if (!copy($path, $dst)) { fwrite(STDERR, "BERHENTI: cadangan $rel gagal\n"); exit(1); }
        if (file_put_contents($path, $baru, LOCK_EX) !== strlen($baru)) { fwrite(STDERR, "BERHENTI: gagal menulis $rel (cadangan: $dst)\n"); exit(1); }
    }
}

echo "Isi QR baru:\n";
foreach (array_merge($PAKAI_ID ? ['ID : <kode dokumen>'] : [], ['<NAMA INSTANSI … TTD OLEH … (hanya template ber-INFO_DOC)>'], $BARIS) as $b) { echo "  | $b\n"; }
printf("Template RS %s-*: %d diubah%s.\n", $KODE, count($hasil), $sama ? ", $sama sudah berisi teks ini" : '');
foreach ($hasil as [$rel, $n, , , $isi]) { printf("  UBAH    %-60s %s%s\n", $rel, $isi, $n > 1 ? " ($n QR)" : ''); }
if ($MODE === 'terapkan') {
    file_put_contents($BK . '/MANIFEST.tsv', "# berkas\tsha256_sebelum\tsha256_sesudah\n"
        . implode("\n", array_map(fn($r) => "$r[0]\t$r[2]\t$r[3]", $hasil)) . "\n");
    echo "Cadangan & manifest: $BK\n";
}
