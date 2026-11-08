<?php
/**
 * simgos-uji — isi QR TTE bawaan pada template RS (<KODE PPK>-*.jrxml) menjadi teks INFO_DOC bawaan SIMGOS:
 *   "ID : " + REQUEST_REPORT_ID + "\n" + INFO_DOC
 * Dipanggil oleh qr-info-doc.sh. Hanya mengubah 2 hal per berkas: deklarasi field INFO_DOC dan ekspresi QR.
 *
 * Template diubah HANYA bila semua syarat terpenuhi:
 *   1. berkas biasa (bukan tautan), QR-nya persis $P{REQUEST_REPORT_ID}
 *   2. query template memanggil prosedur (CALL skema.Prosedur) yang mengeluarkan kolom INFO_DOC (daftar dari DB)
 *   3. template acuan <KODE ACUAN>-<nama>.jrxml ada, memanggil prosedur yang sama, dan memakai field + QR INFO_DOC
 *   4. hasil perubahan XML-nya valid
 */
declare(strict_types=1);

$opt = json_decode((string) stream_get_contents(STDIN), true);
[$MODE, $ROOT, $KODE, $ACUAN, $BK] = [$opt['mode'], rtrim($opt['root'], '/'), $opt['kode'], $opt['acuan'], $opt['backup'] ?? ''];
$PROC = array_flip(array_map('strtolower', $opt['prosedur']));     // skema.prosedur yang mengeluarkan INFO_DOC

const QR_LAMA = '~(<jr:QRCode\b[^>]*>\s*<jr:codeExpression>\s*<!\[CDATA\[)\s*\$P\{REQUEST_REPORT_ID\}\s*(\]\]>\s*</jr:codeExpression>)~';
const QR_BARU = '"ID : "+ $P{REQUEST_REPORT_ID} + "\n" + $F{INFO_DOC}';
const QR_INFO = '~<jr:QRCode\b[^>]*>\s*<jr:codeExpression>\s*<!\[CDATA\[[^\]]*\$F\{INFO_DOC\}~';

/** Bagian utama laporan: blok <subDataset>…</subDataset> (punya query & field sendiri) diganti spasi sepanjang aslinya,
 *  sehingga posisi karakter tetap sama dengan berkas asli. */
function utama(string $xml): string {
    return (string) preg_replace_callback('~<subDataset\b.*?</subDataset>~s', static fn($m) => str_repeat(' ', strlen($m[0])), $xml);
}
/** Prosedur yang dipanggil query UTAMA (bukan subDataset). */
function prosedur(string $xml): ?string {
    if (!preg_match('~<queryString[^>]*>\s*<!\[CDATA\[(.*?)\]\]>~s', utama($xml), $m)) { return null; }
    return preg_match('~\bcall\s+`?([A-Za-z0-9_-]+)`?\s*\.\s*`?([A-Za-z0-9_]+)`?~i', $m[1], $p) ? strtolower($p[1] . '.' . $p[2]) : null;
}
/** Deklarasi field INFO_DOC di bagian UTAMA; mengembalikan class-nya atau null. */
function fieldInfoDoc(string $xml): ?string {
    return preg_match('~<field\s+name="INFO_DOC"\s+class="([A-Za-z0-9_.]+)"~', utama($xml), $m) ? $m[1] : null;
}
function xmlValid(string $xml): bool {
    $d = new DOMDocument(); $prev = libxml_use_internal_errors(true);
    $ok = $d->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    return $ok;
}

$hasil = ['ubah' => [], 'lewati' => []];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) {
    if ($f->isFile() && preg_match('~^' . preg_quote($KODE, '~') . '-.*\.jrxml$~', $f->getFilename())) { $files[] = $f->getPathname(); }
}
sort($files);
foreach ($files as $path) {
    $rel = substr($path, strlen($ROOT) + 1);
    if (is_link($path)) { continue; }
    $xml = (string) file_get_contents($path);
    if (!preg_match(QR_LAMA, $xml)) { continue; }                                   // QR bukan "hanya kode" -> bukan sasaran
    $nama = substr(basename($path), strlen($KODE) + 1);
    $acuan = dirname($path) . '/' . $ACUAN . '-' . $nama;
    $proc = prosedur($xml);
    $alasan = null;
    if ($proc === null) { $alasan = 'query tidak memanggil prosedur (CALL)'; }
    elseif (!isset($PROC[$proc])) { $alasan = "prosedur $proc tidak mengeluarkan INFO_DOC"; }
    elseif (!is_file($acuan)) { $alasan = "template acuan {$ACUAN}-{$nama} tidak ada"; }
    else {
        $xa = (string) file_get_contents($acuan);
        if (prosedur($xa) !== $proc) { $alasan = 'template acuan memanggil prosedur lain (' . (prosedur($xa) ?? '-') . ')'; }
        elseif (!preg_match(QR_INFO, $xa) || fieldInfoDoc($xa) === null) { $alasan = 'template acuan tidak memakai QR INFO_DOC'; }
    }
    if ($alasan !== null) { $hasil['lewati'][] = [$rel, $alasan]; continue; }

    // --- perubahan: field INFO_DOC (salin persis dari template acuan) + ekspresi QR
    $baru = $xml; $nQr = 0;
    if (fieldInfoDoc($baru) === null) {
        // Sisipkan tepat sesudah </queryString> UTAMA (di JRXML field utama selalu menyusul queryString utama).
        $decl = '<field name="INFO_DOC" class="' . fieldInfoDoc($xa) . '"/>';
        if (preg_match('~</queryString>~', utama($baru), $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen('</queryString>');
            $indent = preg_match('~\n([ \t]*)<queryString~', utama($baru), $i) ? $i[1] : "\t";
            $baru = substr($baru, 0, $pos) . "\n" . $indent . $decl . substr($baru, $pos);
        } else { $hasil['lewati'][] = [$rel, 'queryString utama tidak ditemukan']; continue; }
    }
    $baru = preg_replace_callback(QR_LAMA, static fn($m) => $m[1] . QR_BARU . $m[2], $baru, -1, $nQr);
    if ($nQr < 1 || !xmlValid($baru)) { $hasil['lewati'][] = [$rel, 'hasil perubahan tidak valid (XML)']; continue; }
    $hasil['ubah'][] = [$rel, $proc, $nQr, hash('sha256', $xml), hash('sha256', $baru)];

    if ($MODE === 'terapkan') {
        $dst = $BK . '/' . $rel;
        if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0700, true); }
        if (!copy($path, $dst)) { fwrite(STDERR, "BERHENTI: cadangan $rel gagal\n"); exit(1); }
        @touch($dst, (int) filemtime($path));
        // tulis ke berkas yang sama (inode, pemilik, izin, label SELinux tetap)
        if (file_put_contents($path, $baru, LOCK_EX) !== strlen($baru)) { fwrite(STDERR, "BERHENTI: gagal menulis $rel (cadangan: $dst)\n"); exit(1); }
    }
}

// --- laporan
printf("Template RS %s-*: %d diubah, %d dilewati (QR hanya kode tetapi tidak aman diubah).\n", $KODE, count($hasil['ubah']), count($hasil['lewati']));
foreach ($hasil['ubah'] as [$rel, $proc, $n]) { printf("  UBAH    %-58s %s%s\n", $rel, $proc, $n > 1 ? " ($n QR)" : ''); }
foreach ($hasil['lewati'] as [$rel, $why]) { printf("  LEWATI  %-58s %s\n", $rel, $why); }
if ($MODE === 'terapkan') {
    $man = $BK . '/MANIFEST.tsv';
    $rows = array_map(fn($r) => implode("\t", [$r[0], $r[3], $r[4]]), $hasil['ubah']);
    file_put_contents($man, "# berkas\tsha256_sebelum\tsha256_sesudah\n" . implode("\n", $rows) . "\n");
    echo "Cadangan & manifest: $BK\n";
}
