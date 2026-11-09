<?php
/**
 * simgos-uji — buat / batalkan 5 kunjungan uji KlaimTTE di server SIMGOS DEV.
 * Dipanggil oleh buat-kunjungan-uji.sh / batalkan-kunjungan-uji.sh (konfigurasi koneksi lewat STDIN, JSON).
 *
 * Meniru urutan aplikasi SIMGOS (PendaftaranService, KunjunganService, MutasiService): nomor dari fungsi
 * generator.*, lalu INSERT berurutan; logika bisnis (tagihan, karcis, antrian, penjamin tagihan, kelengkapan klaim,
 * tempat tidur, akomodasi) dikerjakan trigger database seperti saat petugas mendaftarkan pasien.
 * Semua dalam SATU transaksi: --cek = jalankan semuanya lalu ROLLBACK (tidak ada data tersisa, kecuali nomor urut
 * generator MyISAM yang terpakai).
 */
declare(strict_types=1);

$cfg = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($cfg)) { fwrite(STDERR, "konfigurasi tidak valid\n"); exit(2); }
$MODE = $cfg['mode'];                         // cek | buat | batal-cek | batal
$PAKAI_TT = !empty($cfg['tempat_tidur']);     // bawaan: rawat inap TANPA tempat tidur (status master.ruang_kamar_tidur tidak disentuh)
$HASIL = $cfg['hasil'] ?? '';
$SET_STATUS = ($cfg['set'] ?? '') === 'status';   // --status: pasien 6-10, satu pasien per status berkas KlaimTTE

function berhenti(string $m, int $kode = 1): never { fwrite(STDERR, "BERHENTI: $m\n"); exit($kode); }
function info(string $m): void { echo $m, "\n"; }

// ---------------------------------------------------------------- data uji (penanda: nama UJI KLAIMTTE, nomor 9999…)
const PENANDA = 'UJI KLAIMTTE';
// 'cari' = kata kunci nama ruangan (urut prioritas); 'pilih' = ruangan & dokter pilihan (dipakai bila valid di server ini).
$RENCANA = [
    1 => ['jk' => 1, 'lahir' => '1968-04-12', 'jenis' => 'rj', 'cari' => ['Penyakit Dalam', 'Interna', 'Dalam'], 'pilih' => ['101010106', 18], 'kelas' => 3, 'icd' => 'I10',
          'keluhan' => 'Pusing dan tengkuk terasa berat sejak 3 hari', 'rps' => 'Tekanan darah tinggi tidak terkontrol, obat habis 1 minggu'],
    2 => ['jk' => 2, 'lahir' => '1990-09-03', 'jenis' => 'rj', 'cari' => ['Bedah Umum', 'Bedah'], 'pilih' => ['101010102', 22], 'kelas' => 3, 'icd' => 'K35.8',
          'keluhan' => 'Nyeri perut kanan bawah sejak 2 hari', 'rps' => 'Nyeri bertambah saat berjalan, mual, demam ringan'],
    3 => ['jk' => 1, 'lahir' => '2019-02-21', 'jenis' => 'rj', 'cari' => ['Anak'], 'pilih' => ['101010101', 27], 'kelas' => 3, 'icd' => 'J06.9',
          'keluhan' => 'Batuk pilek dan demam sejak 3 hari', 'rps' => 'Demam naik turun, nafsu makan menurun'],
    4 => ['jk' => 2, 'lahir' => '1985-12-30', 'jenis' => 'igd', 'cari' => ['IGD', 'Gawat Darurat', 'Darurat'], 'pilih' => ['101020201', 9], 'kelas' => 3, 'icd' => 'A09',
          'keluhan' => 'BAB cair lebih dari 6 kali sejak pagi', 'rps' => 'Disertai muntah dan lemas'],
    5 => ['jk' => 1, 'lahir' => '1957-07-17', 'jenis' => 'ri', 'cari' => ['IGD', 'Gawat Darurat', 'Darurat'], 'pilih' => ['101020201', 9], 'kelas' => 1, 'icd' => 'J18.9',
          'ri_cari' => ['Penyakit Dalam', 'Interna', 'Dalam'], 'ri_pilih' => ['101030401', 18],
          'keluhan' => 'Sesak napas dan batuk berdahak sejak 4 hari', 'rps' => 'Demam tinggi, sesak bertambah, saturasi turun'],
];
// --status: pasien 6-10. 'akhir' = keadaan akhir yang dibuat (lewat kolom/trigger yang sama dengan tombol SIMGOS):
//   dilayani : kunjungan dibiarkan aktif                                  -> MASIH DILAYANI
//   rm       : kunjungan selesai, RM belum Final                          -> RM BELUM FINAL
//   tagihan  : + Final RM (Detail Kunjungan)                              -> TAGIHAN BELUM FINAL
//   berkas   : + tagihan dikunci & difinalkan (Kasir)                     -> FINAL + BERKAS BELUM LENGKAP
//   lengkap  : + kelengkapan Berkas Klaim = Lengkap                       -> FINAL + BERKAS LENGKAP
$RENCANA_STATUS = [
    6  => ['jk' => 2, 'lahir' => '1972-03-08', 'jenis' => 'rj', 'cari' => ['Penyakit Dalam', 'Interna', 'Dalam'], 'pilih' => ['101010106', 18], 'kelas' => 3, 'icd' => 'E11.9', 'akhir' => 'dilayani',
           'keluhan' => 'Sering haus dan buang air kecil malam hari', 'rps' => 'Gula darah sewaktu tinggi, berat badan turun 1 bulan'],
    7  => ['jk' => 1, 'lahir' => '1964-11-19', 'jenis' => 'rj', 'cari' => ['Bedah Umum', 'Bedah'], 'pilih' => ['101010102', 22], 'kelas' => 3, 'icd' => 'K40.9', 'akhir' => 'rm',
           'keluhan' => 'Benjolan di lipat paha kanan', 'rps' => 'Benjolan hilang timbul saat mengejan sejak 6 bulan'],
    8  => ['jk' => 2, 'lahir' => '2017-06-14', 'jenis' => 'rj', 'cari' => ['Anak'], 'pilih' => ['101010101', 27], 'kelas' => 3, 'icd' => 'J20.9', 'akhir' => 'tagihan',
           'keluhan' => 'Batuk berdahak 5 hari', 'rps' => 'Batuk makin sering malam hari, demam ringan'],
    9  => ['jk' => 1, 'lahir' => '1979-01-25', 'jenis' => 'igd', 'cari' => ['IGD', 'Gawat Darurat', 'Darurat'], 'pilih' => ['101020201', 9], 'kelas' => 3, 'icd' => 'K29.7', 'akhir' => 'berkas',
           'keluhan' => 'Nyeri ulu hati dan mual sejak semalam', 'rps' => 'Telat makan, riwayat sakit maag'],
    10 => ['jk' => 2, 'lahir' => '1960-05-02', 'jenis' => 'rj', 'cari' => ['Penyakit Dalam', 'Interna', 'Dalam'], 'pilih' => ['101010106', 18], 'kelas' => 3, 'icd' => 'I10', 'akhir' => 'lengkap',
           'keluhan' => 'Kontrol tekanan darah', 'rps' => 'Obat rutin habis, tidak ada keluhan berat'],
];
if ($SET_STATUS) { $RENCANA = $RENCANA_STATUS; }
const JK_RUANGAN = ['rj' => 1, 'igd' => 2, 'ri' => 3];

// ---------------------------------------------------------------- koneksi
try {
    $db = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) {
    $c = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    berhenti('koneksi MySQL gagal (kode ' . $c . ($c === 1045 ? ': akun/password salah' : '') . ').');
}
unset($cfg['pass']);
$db->exec("SET time_zone = @@GLOBAL.time_zone");
function q(string $sql, array $p = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function satu(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

// ---------------------------------------------------------------- petugas (kolom OLEH)
$olehLogin = (string) ($cfg['oleh'] ?? '');
if ($olehLogin === '') {
    $olehLogin = (string) satu("SELECT u.LOGIN FROM pendaftaran.pendaftaran p JOIN aplikasi.pengguna u ON u.ID=p.OLEH AND u.STATUS=1
        WHERE p.TANGGAL > NOW() - INTERVAL 365 DAY GROUP BY u.LOGIN ORDER BY COUNT(*) DESC LIMIT 1");
}
$OLEH = (int) satu("SELECT ID FROM aplikasi.pengguna WHERE LOGIN = ? AND STATUS = 1", [$olehLogin]);
if ($OLEH < 1) { berhenti("akun petugas '$olehLogin' tidak ada/nonaktif (pakai --oleh LOGIN)."); }

// =====================================================================================================  BATALKAN
if ($MODE === 'batal' || $MODE === 'batal-cek') {
    $pas = q("SELECT p.NORM, p.NAMA FROM master.pasien p JOIN master.kartu_identitas_pasien k ON k.NORM=p.NORM AND k.JENIS=1
        WHERE p.NAMA LIKE ? AND k.NOMOR LIKE '9999%' AND p.STATUS = 1 ORDER BY p.NORM", [PENANDA . ' %'])->fetchAll();
    if (!$pas) { info('Tidak ada pasien uji aktif.'); exit(0); }
    $db->beginTransaction();
    try {
        $n = 0;
        foreach ($pas as $p) {
            foreach (q("SELECT NOMOR FROM pendaftaran.pendaftaran WHERE NORM = ? AND STATUS != 0", [$p['NORM']])->fetchAll(PDO::FETCH_COLUMN) as $nopen) {
                // Kunjungan rawat inap dulu (trigger mengosongkan tempat tidur), lalu yang lain.
                foreach (q("SELECT k.NOMOR FROM pendaftaran.kunjungan k WHERE k.NOPEN = ? AND k.STATUS != 0 ORDER BY k.RUANG_KAMAR_TIDUR > 0 DESC, k.MASUK DESC", [$nopen])->fetchAll(PDO::FETCH_COLUMN) as $k) {
                    q("UPDATE pendaftaran.kunjungan SET STATUS = 0 WHERE NOMOR = ?", [$k]);
                }
                q("UPDATE pendaftaran.mutasi m JOIN pendaftaran.kunjungan k ON k.NOMOR = m.KUNJUNGAN SET m.STATUS = 0 WHERE k.NOPEN = ? AND m.STATUS != 0", [$nopen]);
                q("UPDATE pendaftaran.tujuan_pasien SET STATUS = 0 WHERE NOPEN = ? AND STATUS != 0", [$nopen]);
                q("UPDATE pendaftaran.pendaftaran SET STATUS = 0 WHERE NOMOR = ?", [$nopen]);
                info("  dibatalkan: NORM {$p['NORM']} {$p['NAMA']}  NOPEN $nopen");
                $n++;
            }
        }
        if ($MODE === 'batal-cek') { $db->rollBack(); info("MODE CEK: $n pendaftaran akan dibatalkan; tidak ada yang diubah (ROLLBACK)."); }
        else { $db->commit(); info("Selesai: $n pendaftaran uji dibatalkan (status 0). Data pasien uji dipertahankan untuk dipakai lagi."); }
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        berhenti('pembatalan gagal, semua dikembalikan (ROLLBACK): ' . $e->getMessage());
    }
    exit(0);
}

// =====================================================================================================  RUANGAN & DOKTER
// Dokter "siap": aktif, tepat satu akun SIMGOS aktif ber-NIP sama, NIP tunggal di master.pegawai, tepat satu NIK KTP
// 16 digit di pegawai.kartu_identitas (syarat penanda tangan KlaimTTE), punya SMF, terdaftar aktif di ruangan itu.
const SQL_DOKTER_SIAP = "FROM master.dokter d JOIN master.dokter_ruangan dr ON dr.DOKTER = d.ID AND dr.STATUS = 1 AND dr.RUANGAN = ?
    WHERE d.STATUS = 1
      AND (SELECT COUNT(*) FROM aplikasi.pengguna u WHERE u.NIP = d.NIP AND u.STATUS = 1) = 1
      AND (SELECT COUNT(*) FROM master.pegawai p WHERE p.NIP = d.NIP) = 1
      AND (SELECT COUNT(*) FROM pegawai.kartu_identitas k WHERE k.NIP = d.NIP AND k.JENIS = 1 AND k.NOMOR REGEXP '^[0-9]{16}$') = 1
      AND EXISTS (SELECT 1 FROM master.dokter_smf s WHERE s.DOKTER = d.ID AND s.STATUS = 1)";
function ruanganAktif(string $id, int $jk): bool { return (bool) satu("SELECT 1 FROM master.ruangan WHERE ID = ? AND STATUS = 1 AND JENIS_KUNJUNGAN = ?", [$id, $jk]); }
function adaTempatTidur(string $ru): bool {
    return (bool) satu("SELECT 1 FROM master.ruang_kamar rk JOIN master.ruang_kamar_tidur t ON t.RUANG_KAMAR = rk.ID WHERE rk.RUANGAN = ? AND rk.STATUS = 1 AND t.STATUS = 1 LIMIT 1", [$ru]);
}
/** @return array{0:string,1:int,2:string} ruangan, dokter, asal ('pilihan' | 'otomatis') */
function tentukan(int $jk, array $cari, array $pilih, bool $butuhTT): ?array {
    [$pr, $pd] = getenv('KT_ABAIKAN_PILIHAN') === '1' ? ['', 0] : $pilih;   // uji: paksa pemilihan otomatis
    if (ruanganAktif($pr, $jk) && (!$butuhTT || adaTempatTidur($pr)) && satu("SELECT 1 " . SQL_DOKTER_SIAP . " AND d.ID = ?", [$pr, $pd])) {
        return [$pr, $pd, 'pilihan'];
    }
    $urut = []; $par = [];
    foreach ($cari as $n => $kata) { $urut[] = "WHEN r.DESKRIPSI LIKE ? THEN $n"; $par[] = '%' . $kata . '%'; }
    $rows = q("SELECT r.ID FROM master.ruangan r WHERE r.STATUS = 1 AND r.JENIS_KUNJUNGAN = ?
        ORDER BY CASE " . implode(' ', $urut) . " ELSE 99 END, CHAR_LENGTH(r.DESKRIPSI), r.ID", array_merge([$jk], $par))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $ru) {
        if ($butuhTT && !adaTempatTidur($ru)) { continue; }
        $dk = satu("SELECT d.ID " . SQL_DOKTER_SIAP . " ORDER BY (SELECT COUNT(*) FROM pendaftaran.kunjungan k WHERE k.DPJP = d.ID AND k.RUANGAN = dr.RUANGAN) DESC, d.ID LIMIT 1", [$ru]);
        if ($dk) { return [(string) $ru, (int) $dk, 'otomatis']; }
    }
    return null;
}

$masalah = [];
$dokterUser = [];
info('Rencana di server ' . gethostname() . ':');
foreach ($RENCANA as $i => &$r) {
    $t = tentukan(JK_RUANGAN[$r['jenis'] === 'ri' ? 'igd' : $r['jenis']], $r['cari'], $r['pilih'], false);
    if ($t === null) { $masalah[] = "kunjungan $i: tidak ada ruangan jenis " . ($r['jenis'] === 'rj' ? 'rawat jalan' : 'IGD') . " dengan dokter siap TTE"; continue; }
    [$r['ruangan'], $r['dokter'], $asal] = $t;
    $ket = sprintf('  %d %-6s %-28s dokter %-4d (%s)', $i, strtoupper($r['jenis']), (string) satu("SELECT DESKRIPSI FROM master.ruangan WHERE ID = ?", [$r['ruangan']]), $r['dokter'], $asal);
    if ($r['jenis'] === 'ri') {
        $t2 = tentukan(3, $r['ri_cari'], $r['ri_pilih'], $PAKAI_TT);
        if ($t2 === null) { $masalah[] = "kunjungan $i: tidak ada ruang rawat inap" . ($PAKAI_TT ? ' dengan tempat tidur kosong' : '') . " & dokter siap TTE"; continue; }
        [$r['ri_ruangan'], $r['ri_dokter'], $asal2] = $t2;
        $ket .= sprintf(' -> %s dokter %d (%s)', (string) satu("SELECT DESKRIPSI FROM master.ruangan WHERE ID = ?", [$r['ri_ruangan']]), $r['ri_dokter'], $asal2);
    }
    info($ket);
    foreach (array_filter([$r['dokter'], $r['ri_dokter'] ?? null]) as $dk) {
        $dokterUser[$dk] = (int) satu("SELECT u.ID FROM master.dokter d JOIN aplikasi.pengguna u ON u.NIP = d.NIP AND u.STATUS = 1 WHERE d.ID = ?", [$dk]);
    }
    if (!satu("SELECT 1 FROM master.mrconso WHERE SAB = 'ICD10_1998' AND CODE = ? LIMIT 1", [$r['icd']])) { $masalah[] = "kunjungan $i: kode ICD-10 {$r['icd']} tidak ada"; }
}
unset($r);
if ($masalah) { berhenti("data master server ini belum memenuhi:\n  - " . implode("\n  - ", $masalah) . "\n  Tidak ada yang diubah."); }

// =====================================================================================================  PENGAMAN DATA MASTER
// Skrip hanya MENAMBAH pasien & kunjungan. Sidik jari tabel master dihitung di dalam transaksi sebelum & sesudah;
// bila ada satu baris berubah (termasuk oleh trigger SIMGOS), semuanya di-ROLLBACK.
function tabelMaster(): array {
    return q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'master' AND TABLE_TYPE = 'BASE TABLE'
        AND (TABLE_NAME LIKE 'tarif%' OR TABLE_NAME IN ('ruangan','ruang_kamar','ruang_kamar_tidur','dokter','dokter_ruangan','dokter_smf',
             'pegawai','perawat','referensi','paket','ppk','smf_ruangan')) ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
}
function sidikMaster(array $tabel): array {
    $out = [];
    foreach ($tabel as $t) {
        $cols = q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'master' AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$t])->fetchAll(PDO::FETCH_COLUMN);
        $expr = implode(', ', array_map(fn($c) => "IFNULL(CAST(`" . str_replace('`', '', $c) . "` AS CHAR),'~')", $cols));
        $out[$t] = implode(':', q("SELECT COUNT(*), IFNULL(BIT_XOR(CRC32(CONCAT_WS('|', $expr))),0) FROM master.`" . str_replace('`', '', $t) . "`")->fetch(PDO::FETCH_NUM));
    }
    return $out;
}

/** Status yang akan tampil di KlaimTTE (aturan sama dengan RegistrationReadService::withServiceStatus). */
function statusKlaimtte(string $nopen): string {
    $k = q("SELECT k.STATUS, k.FINAL_HASIL, r.JENIS_KUNJUNGAN FROM pendaftaran.kunjungan k JOIN master.ruangan r ON r.ID = k.RUANGAN
        WHERE k.NOPEN = ? AND k.STATUS != 0", [$nopen])->fetchAll();
    if (array_filter($k, fn($x) => (int) $x['STATUS'] === 1)) { return 'MASIH DILAYANI'; }
    if (array_filter($k, fn($x) => in_array((int) $x['JENIS_KUNJUNGAN'], [1, 2, 3], true) && (int) $x['FINAL_HASIL'] !== 1)) { return 'RM BELUM FINAL'; }
    $t = q("SELECT MIN(t.STATUS) FROM pembayaran.tagihan_pendaftaran tp JOIN pembayaran.tagihan t ON t.ID = tp.TAGIHAN
        WHERE tp.PENDAFTARAN = ? AND tp.STATUS <> 0 AND t.STATUS <> 0", [$nopen])->fetchColumn();
    if ($t === null || $t === false) { return 'BELUM ADA TAGIHAN'; }
    if ((int) $t < 2) { return 'TAGIHAN BELUM FINAL'; }
    return 'FINAL + ' . ((int) satu("SELECT STATUS FROM berkas_klaim.kelengkapan WHERE NOPEN = ?", [$nopen]) === 2 ? 'BERKAS LENGKAP' : 'BERKAS BELUM LENGKAP');
}

// =====================================================================================================  BUAT
$ringkasan = [];
$db->beginTransaction();
try {
    $TABEL_MASTER = tabelMaster();
    $sidikAwal = sidikMaster($TABEL_MASTER);
    $now = new DateTimeImmutable((string) satu("SELECT NOW()"));
    $tgl = fn(int $menitLalu): string => $now->modify("-$menitLalu minutes")->format('Y-m-d H:i:s');

    foreach ($RENCANA as $i => $r) {
        $nama = PENANDA . " $i";
        $nik = sprintf('9999%012d', $i);
        $bpjs = sprintf('9999%09d', $i);
        $du = $dokterUser[$r['dokter']];

        // ---- pasien (dipakai ulang bila sudah ada: NIK uji yang sama)
        $norm = (int) satu("SELECT p.NORM FROM master.pasien p JOIN master.kartu_identitas_pasien k ON k.NORM = p.NORM AND k.JENIS = 1 AND k.NOMOR = ?
            WHERE p.NAMA = ? AND p.STATUS = 1 LIMIT 1", [$nik, $nama]);
        $pasienBaru = $norm === 0;
        if ($pasienBaru) {
            if (satu("SELECT 1 FROM master.kartu_identitas_pasien WHERE JENIS = 1 AND NOMOR = ?", [$nik])) { throw new RuntimeException("NIK uji $nik sudah dipakai pasien lain"); }
            q("INSERT INTO master.pasien (NAMA, PANGGILAN, TEMPAT_LAHIR, TANGGAL_LAHIR, JENIS_KELAMIN, ALAMAT, RT, RW, TANGGAL, OLEH, STATUS)
               VALUES (?, ?, 'KOTA UJI', ?, ?, ?, '001', '001', ?, ?, 1)",
              [$nama, "UJI $i", $r['lahir'] . ' 00:00:00', $r['jk'], "JL. UJI KLAIMTTE NO. $i", $tgl(240), $OLEH]);
            $norm = (int) $db->lastInsertId();
            q("INSERT INTO master.kartu_identitas_pasien (JENIS, NORM, NOMOR, ALAMAT, RT, RW) VALUES (1, ?, ?, ?, '001', '001')", [$norm, $nik, "JL. UJI KLAIMTTE NO. $i"]);
            q("INSERT INTO master.kartu_asuransi_pasien (JENIS, NORM, NOMOR) VALUES (2, ?, ?)", [$norm, $bpjs]);
        }

        // ---- pendaftaran + tujuan + penjamin (PendaftaranService::simpan)
        $mulai = 200 - $i * 10;                                   // pasien 1 datang paling awal
        $nopen = (string) satu("SELECT generator.generateNoPendaftaran(DATE(NOW()))");
        q("INSERT INTO pendaftaran.pendaftaran (NOMOR, NORM, TANGGAL, OLEH, STATUS) VALUES (?, ?, ?, ?, 1)", [$nopen, $norm, $tgl($mulai), $OLEH]);
        $smf = (int) satu("SELECT SMF FROM master.dokter_smf WHERE DOKTER = ? AND STATUS = 1 LIMIT 1", [$r['dokter']]);
        q("INSERT INTO pendaftaran.tujuan_pasien (NOPEN, RUANGAN, SMF, DOKTER, STATUS) VALUES (?, ?, ?, ?, 1)", [$nopen, $r['ruangan'], $smf, $r['dokter']]);
        $sep = '9999R001' . $now->format('my') . 'V' . substr($nopen, -6);   // 19 karakter, penanda 9999
        q("INSERT INTO pendaftaran.penjamin (JENIS, NOPEN, NOMOR, KELAS, JENIS_PESERTA, COB, KATARAK, NO_SURAT, DPJP, TUJUAN_KUNJUNGAN, SATU_EPISODE)
           VALUES (2, ?, ?, ?, 21, 0, 0, '', '', '0', 0)", [$nopen, $sep, $r['kelas']]);

        // ---- penerimaan di ruangan tujuan (KunjunganService::simpan)
        $terima = function (string $ruangan, int $dpjp, string $masuk, ?string $ref = null, ?int $bed = null) use ($nopen, $OLEH): string {
            $no = (string) satu("SELECT generator.generateNoKunjungan(?, DATE(NOW()))", [$ruangan]);
            q("INSERT INTO pendaftaran.kunjungan (NOMOR, NOPEN, RUANGAN, MASUK, RUANG_KAMAR_TIDUR, REF, DITERIMA_OLEH, DPJP, STATUS)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)", [$no, $nopen, $ruangan, $masuk, $bed, $ref, $OLEH, $dpjp]);
            if ($ref === null) { q("UPDATE pendaftaran.antrian_ruangan SET STATUS = 2 WHERE RUANGAN = ? AND JENIS = 1 AND REF = ? AND STATUS = 1", [$ruangan, $nopen]); }
            return $no;
        };
        $rekam = function (string $kunj, int $oleh, bool $pengkajianAwal) use ($nopen, $r): void {
            if ($pengkajianAwal) {
                q("INSERT INTO medicalrecord.anamnesis (KUNJUNGAN, PENDAFTARAN, DESKRIPSI, RPP, KELUHAN_UTAMA, RPS, RPT, RIWAYAT_ALERGI, OLEH, STATUS)
                   VALUES (?, ?, ?, '', ?, ?, 'Tidak ada', 'Tidak ada', ?, 1)",
                  [$kunj, $nopen, '[DATA UJI KLAIMTTE] ' . $r['keluhan'] . '. ' . $r['rps'] . '.', $r['keluhan'], $r['rps'], $oleh]);
            }
            q("INSERT INTO medicalrecord.keluhan_utama (KUNJUNGAN, DESKRIPSI, SNOMED_CT_ID, OLEH, STATUS) VALUES (?, ?, 0, ?, 1)",
              [$kunj, '[DATA UJI KLAIMTTE] ' . $r['keluhan'], $oleh]);
        };

        $kunjPertama = $terima($r['ruangan'], $r['dokter'], $tgl($mulai - 15));
        $rekam($kunjPertama, $du, true);
        $diag = (string) satu("SELECT STR FROM master.mrconso WHERE SAB = 'ICD10_1998' AND CODE = ? ORDER BY TTY = 'PT' DESC LIMIT 1", [$r['icd']]);
        q("INSERT INTO medicalrecord.diagnosa (NOPEN, KODE, SNOMED_CT_ID, DIAGNOSA, UTAMA, INACBG, BARU, DIAGNOSA_OLEH, DIAGNOSA_TANGGAL, TANGGAL, OLEH, STATUS, INA_GROUPER)
           VALUES (?, ?, '', ?, 1, 1, 1, ?, ?, ?, ?, 1, 0)", [$nopen, $r['icd'], mb_substr($diag, 0, 250), $du, $tgl($mulai - 30), $tgl($mulai - 30), $du]);

        $baris = ['no' => $i, 'nama' => $nama, 'norm' => $norm, 'pasien' => $pasienBaru ? 'baru' : 'dipakai ulang', 'nopen' => $nopen, 'sep' => $sep,
                  'kunjungan' => [$kunjPertama], 'ruangan' => (string) satu("SELECT DESKRIPSI FROM master.ruangan WHERE ID = ?", [$r['ruangan']]),
                  'dpjp' => (string) satu("SELECT LOGIN FROM aplikasi.pengguna WHERE ID = ?", [$du]), 'icd' => $r['icd'], 'status' => 'selesai'];

        if ($r['jenis'] === 'ri') {
            // ---- IGD -> rawat inap (MutasiService + penerimaan di ruang rawat dengan tempat tidur)
            $mutasi = (string) satu("SELECT generator.generateNoMutasi(?, DATE(NOW()))", [$r['ruangan']]);
            q("INSERT INTO pendaftaran.mutasi (NOMOR, KUNJUNGAN, TANGGAL, TUJUAN, DPJP, OLEH, STATUS) VALUES (?, ?, ?, ?, ?, ?, 1)",
              [$mutasi, $kunjPertama, $tgl($mulai - 60), $r['ri_ruangan'], $r['ri_dokter'], $OLEH]);
            $bed = $PAKAI_TT ? (int) satu("SELECT t.ID FROM master.ruang_kamar rk JOIN master.ruang_kamar_tidur t ON t.RUANG_KAMAR = rk.ID
                WHERE rk.RUANGAN = ? AND rk.STATUS = 1 AND t.STATUS = 1 ORDER BY t.ID LIMIT 1 FOR UPDATE", [$r['ri_ruangan']]) : null;
            $kunjRi = $terima($r['ri_ruangan'], $r['ri_dokter'], $tgl($mulai - 75), $mutasi, $bed);
            q("UPDATE pendaftaran.kunjungan SET STATUS = 2 WHERE NOMOR = ?", [$kunjPertama]);   // trigger: KELUAR = masuk RI - 1 detik
            $rekam($kunjRi, $dokterUser[$r['ri_dokter']], true);
            $baris['kunjungan'][] = $kunjRi;
            $baris['ruangan'] .= ' -> ' . (string) satu("SELECT DESKRIPSI FROM master.ruangan WHERE ID = ?", [$r['ri_ruangan']])
                . ($bed ? ' (TT ' . (string) satu("SELECT TEMPAT_TIDUR FROM master.ruang_kamar_tidur WHERE ID = ?", [$bed]) . ')' : ' (tanpa TT)');
            $baris['dpjp'] .= ' -> ' . (string) satu("SELECT LOGIN FROM aplikasi.pengguna WHERE ID = ?", [$dokterUser[$r['ri_dokter']]]);
            $v = q("SELECT (SELECT KELUAR FROM pendaftaran.kunjungan WHERE NOMOR = ?) igd_keluar, (SELECT STATUS FROM pendaftaran.mutasi WHERE NOMOR = ?) mutasi_st",
                   [$kunjPertama, $mutasi])->fetch();
            if ($v['igd_keluar'] === null || (int) $v['mutasi_st'] !== 2) {
                throw new RuntimeException('trigger server ini tidak menutup kunjungan IGD / menyelesaikan mutasi seperti SIMGOS acuan (keluar='
                    . var_export($v['igd_keluar'], true) . ', mutasi=' . $v['mutasi_st'] . ')');
            }
            $baris['status'] = 'masih dirawat';
        } elseif (($r['akhir'] ?? '') === 'dilayani') {
            $baris['status'] = 'masih dilayani';                 // kunjungan tetap aktif (STATUS 1)
        } else {
            q("UPDATE pendaftaran.kunjungan SET KELUAR = ?, STATUS = 2 WHERE NOMOR = ?", [$tgl($mulai - 55), $kunjPertama]);
        }

        // ---- --status: Final RM, final tagihan, kelengkapan berkas (kolom yang sama dengan tombol SIMGOS; trigger ikut berjalan)
        $akhir = $r['akhir'] ?? '';
        if (in_array($akhir, ['tagihan', 'berkas', 'lengkap'], true)) {
            // Detail Kunjungan -> Final (KunjunganResource: FINAL_HASIL, FINAL_HASIL_OLEH, FINAL_HASIL_TANGGAL)
            q("UPDATE pendaftaran.kunjungan SET FINAL_HASIL = 1, FINAL_HASIL_OLEH = ?, FINAL_HASIL_TANGGAL = ? WHERE NOPEN = ? AND STATUS = 2",
              [$du, $tgl($mulai - 50), $nopen]);
        }
        if (in_array($akhir, ['berkas', 'lengkap'], true)) {
            // Kasir: kunci tagihan lalu finalkan (TagihanResource: KUNCI = 1, lalu STATUS = 2). Trigger onAfterUpdateTagihan
            // mengantrekan hitung distribusi tarif dan menandai pendaftaran final, seperti dari aplikasi.
            $tg = q("SELECT TAGIHAN FROM pembayaran.tagihan_pendaftaran WHERE PENDAFTARAN = ? AND STATUS = 1", [$nopen])->fetchAll(PDO::FETCH_COLUMN);
            if (!$tg) { throw new RuntimeException("tagihan $nopen tidak terbentuk; tidak bisa difinalkan"); }
            foreach ($tg as $t) {
                q("UPDATE pembayaran.tagihan SET KUNCI = 1, OLEH = ? WHERE ID = ? AND KUNCI = 0", [$OLEH, $t]);
                q("UPDATE pembayaran.tagihan SET STATUS = 2, OLEH = ? WHERE ID = ? AND STATUS = 1", [$OLEH, $t]);
            }
        }
        if ($akhir === 'lengkap') {
            // Berkas Klaim -> Kelengkapan: Lengkap (berkas_klaim.kelengkapan.STATUS 2)
            if (q("UPDATE berkas_klaim.kelengkapan SET STATUS = 2 WHERE NOPEN = ?", [$nopen])->rowCount() !== 1) {
                throw new RuntimeException("kelengkapan berkas klaim $nopen tidak ada; tidak bisa ditandai lengkap");
            }
        }

        // ---- verifikasi hasil trigger
        $cek = q("SELECT (SELECT COUNT(*) FROM pembayaran.tagihan_pendaftaran WHERE PENDAFTARAN = ?) tagihan,
                         (SELECT COUNT(*) FROM berkas_klaim.kelengkapan WHERE NOPEN = ?) klaim,
                         (SELECT COUNT(*) FROM pendaftaran.kunjungan WHERE NOPEN = ? AND STATUS != 0) kunjungan", [$nopen, $nopen, $nopen])->fetch();
        if ((int) $cek['kunjungan'] !== count($baris['kunjungan'])) { throw new RuntimeException("jumlah kunjungan $nopen tidak sesuai"); }
        $baris['tagihan'] = (int) $cek['tagihan'] > 0 ? 'ya' : 'TIDAK';
        $baris['kelengkapan_klaim'] = (int) $cek['klaim'] > 0 ? 'ya' : 'TIDAK';
        if ($akhir !== '') {
            $baris['status_klaimtte'] = statusKlaimtte($nopen);
            $harap = ['dilayani' => 'MASIH DILAYANI', 'rm' => 'RM BELUM FINAL', 'tagihan' => 'TAGIHAN BELUM FINAL',
                      'berkas' => 'FINAL + BERKAS BELUM LENGKAP', 'lengkap' => 'FINAL + BERKAS LENGKAP'][$akhir];
            if ($baris['status_klaimtte'] !== $harap) { throw new RuntimeException("pasien $i: status jadi '{$baris['status_klaimtte']}', seharusnya '$harap'"); }
            $baris['status'] = $baris['status_klaimtte'];
        }
        $ringkasan[] = $baris;
    }

    $sidikAkhir = sidikMaster($TABEL_MASTER);
    $berubah = array_keys(array_diff_assoc($sidikAkhir, $sidikAwal));
    if ($PAKAI_TT && getenv('KT_UJI_KETAT') !== '1') { $berubah = array_values(array_diff($berubah, ['ruang_kamar_tidur'])); }   // diizinkan hanya dengan --dengan-tempat-tidur
    if ($berubah) { throw new RuntimeException('data master berubah (' . implode(', ', $berubah) . '); dibatalkan agar data master tetap utuh'); }
    $ringkasanMaster = count($TABEL_MASTER) . ' tabel master diperiksa (' . implode(', ', $TABEL_MASTER) . '): tidak ada yang berubah'
        . ($PAKAI_TT ? ' selain status tempat tidur' : '');
    if ($MODE === 'cek') { $db->rollBack(); } else { $db->commit(); }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    berhenti('gagal, SEMUA perubahan dikembalikan (ROLLBACK): ' . get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------- laporan
info('');
info(sprintf('%-3s %-16s %-8s %-12s %-19s %-19s %-28s %s', 'No', 'Pasien', 'NORM', 'NOPEN', 'SEP (dummy)', 'Kunjungan', 'Status', 'Ruangan / DPJP / ICD'));
foreach ($ringkasan as $b) {
    info(sprintf('%-3s %-16s %-8s %-12s %-19s %-19s %-28s %s', $b['no'], $b['nama'], $b['norm'], $b['nopen'], $b['sep'], $b['kunjungan'][0], $b['status'],
        $b['ruangan'] . ' / ' . $b['dpjp'] . ' / ' . $b['icd']));
    for ($k = 1; $k < count($b['kunjungan']); $k++) { info(sprintf('%-64s %-19s', '', $b['kunjungan'][$k])); }
}
info('');
info('Tagihan terbentuk (trigger): ' . implode(' ', array_column($ringkasan, 'tagihan')) . '   kelengkapan berkas klaim: ' . implode(' ', array_column($ringkasan, 'kelengkapan_klaim')));
info('Petugas pendaftaran (OLEH): ' . $olehLogin . " (ID $OLEH)");
info('Data master: ' . $ringkasanMaster);
if ($MODE === 'cek') {
    info('MODE CEK: semua langkah berhasil dijalankan lalu DIBATALKAN (ROLLBACK). Tidak ada data yang tersimpan.');
} elseif ($HASIL !== '') {
    file_put_contents($HASIL, json_encode($ringkasan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    info("TERSIMPAN. Rincian: $HASIL");
}
