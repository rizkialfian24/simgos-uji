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
$HASIL = $cfg['hasil'] ?? '';

function berhenti(string $m, int $kode = 1): never { fwrite(STDERR, "BERHENTI: $m\n"); exit($kode); }
function info(string $m): void { echo $m, "\n"; }

// ---------------------------------------------------------------- data uji (penanda: nama UJI KLAIMTTE, nomor 9999…)
const PENANDA = 'UJI KLAIMTTE';
$RENCANA = [
    1 => ['jk' => 1, 'lahir' => '1968-04-12', 'jenis' => 'rj', 'ruangan' => '101010106', 'dokter' => 18, 'kelas' => 3, 'icd' => 'I10',
          'keluhan' => 'Pusing dan tengkuk terasa berat sejak 3 hari', 'rps' => 'Tekanan darah tinggi tidak terkontrol, obat habis 1 minggu'],
    2 => ['jk' => 2, 'lahir' => '1990-09-03', 'jenis' => 'rj', 'ruangan' => '101010102', 'dokter' => 22, 'kelas' => 3, 'icd' => 'K35.8',
          'keluhan' => 'Nyeri perut kanan bawah sejak 2 hari', 'rps' => 'Nyeri bertambah saat berjalan, mual, demam ringan'],
    3 => ['jk' => 1, 'lahir' => '2019-02-21', 'jenis' => 'rj', 'ruangan' => '101010101', 'dokter' => 27, 'kelas' => 3, 'icd' => 'J06.9',
          'keluhan' => 'Batuk pilek dan demam sejak 3 hari', 'rps' => 'Demam naik turun, nafsu makan menurun'],
    4 => ['jk' => 2, 'lahir' => '1985-12-30', 'jenis' => 'igd', 'ruangan' => '101020201', 'dokter' => 9, 'kelas' => 3, 'icd' => 'A09',
          'keluhan' => 'BAB cair lebih dari 6 kali sejak pagi', 'rps' => 'Disertai muntah dan lemas'],
    5 => ['jk' => 1, 'lahir' => '1957-07-17', 'jenis' => 'ri', 'ruangan' => '101020201', 'dokter' => 9, 'kelas' => 1, 'icd' => 'J18.9',
          'ri_ruangan' => '101030401', 'ri_dokter' => 18,
          'keluhan' => 'Sesak napas dan batuk berdahak sejak 4 hari', 'rps' => 'Demam tinggi, sesak bertambah, saturasi turun'],
];
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

// =====================================================================================================  PEMERIKSAAN AWAL
$masalah = [];
$dokterUser = [];
foreach ($RENCANA as $i => $r) {
    $pairs = [[$r['ruangan'], $r['dokter'], $r['jenis'] === 'ri' ? 2 : JK_RUANGAN[$r['jenis']]]];
    if ($r['jenis'] === 'ri') { $pairs[] = [$r['ri_ruangan'], $r['ri_dokter'], 3]; }
    foreach ($pairs as [$ru, $dk, $jk]) {
        $row = q("SELECT DESKRIPSI, JENIS_KUNJUNGAN FROM master.ruangan WHERE ID = ? AND STATUS = 1", [$ru])->fetch();
        if (!$row) { $masalah[] = "kunjungan $i: ruangan $ru tidak ada/nonaktif"; continue; }
        if ((int) $row['JENIS_KUNJUNGAN'] !== $jk) { $masalah[] = "kunjungan $i: ruangan $ru bukan jenis kunjungan $jk"; }
        if (!satu("SELECT 1 FROM master.dokter_ruangan WHERE DOKTER = ? AND RUANGAN = ? AND STATUS = 1", [$dk, $ru])) { $masalah[] = "kunjungan $i: dokter $dk tidak terdaftar di ruangan $ru"; }
        $u = q("SELECT u.ID, u.LOGIN FROM master.dokter d JOIN aplikasi.pengguna u ON u.NIP = d.NIP AND u.STATUS = 1 WHERE d.ID = ? AND d.STATUS = 1", [$dk])->fetchAll();
        if (count($u) !== 1) { $masalah[] = "kunjungan $i: dokter $dk tidak punya tepat satu akun SIMGOS aktif"; } else { $dokterUser[$dk] = (int) $u[0]['ID']; }
        if (!satu("SELECT SMF FROM master.dokter_smf WHERE DOKTER = ? AND STATUS = 1 LIMIT 1", [$dk])) { $masalah[] = "kunjungan $i: SMF dokter $dk tidak ada"; }
    }
    if (!satu("SELECT 1 FROM master.mrconso WHERE SAB = 'ICD10_1998' AND CODE = ? LIMIT 1", [$r['icd']])) { $masalah[] = "kunjungan $i: kode ICD-10 {$r['icd']} tidak ada"; }
    if ($r['jenis'] === 'ri' && !satu("SELECT 1 FROM master.ruang_kamar rk JOIN master.ruang_kamar_tidur t ON t.RUANG_KAMAR = rk.ID WHERE rk.RUANGAN = ? AND rk.STATUS = 1 AND t.STATUS = 1 LIMIT 1", [$r['ri_ruangan']])) {
        $masalah[] = "kunjungan $i: tidak ada tempat tidur kosong di ruangan {$r['ri_ruangan']}";
    }
}
if ($masalah) { berhenti("data master server ini tidak cocok dengan rencana:\n  - " . implode("\n  - ", $masalah) . "\n  Tidak ada yang diubah."); }

// =====================================================================================================  BUAT
$ringkasan = [];
$db->beginTransaction();
try {
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
            $bed = (int) satu("SELECT t.ID FROM master.ruang_kamar rk JOIN master.ruang_kamar_tidur t ON t.RUANG_KAMAR = rk.ID
                WHERE rk.RUANGAN = ? AND rk.STATUS = 1 AND t.STATUS = 1 ORDER BY t.ID LIMIT 1 FOR UPDATE", [$r['ri_ruangan']]);
            $kunjRi = $terima($r['ri_ruangan'], $r['ri_dokter'], $tgl($mulai - 75), $mutasi, $bed);
            q("UPDATE pendaftaran.kunjungan SET STATUS = 2 WHERE NOMOR = ?", [$kunjPertama]);   // trigger: KELUAR = masuk RI - 1 detik
            $rekam($kunjRi, $dokterUser[$r['ri_dokter']], true);
            $baris['kunjungan'][] = $kunjRi;
            $baris['ruangan'] .= ' -> ' . (string) satu("SELECT DESKRIPSI FROM master.ruangan WHERE ID = ?", [$r['ri_ruangan']])
                . ' (TT ' . (string) satu("SELECT TEMPAT_TIDUR FROM master.ruang_kamar_tidur WHERE ID = ?", [$bed]) . ')';
            $baris['dpjp'] .= ' -> ' . (string) satu("SELECT LOGIN FROM aplikasi.pengguna WHERE ID = ?", [$dokterUser[$r['ri_dokter']]]);
            $baris['status'] = 'masih dirawat';
        } else {
            q("UPDATE pendaftaran.kunjungan SET KELUAR = ?, STATUS = 2 WHERE NOMOR = ?", [$tgl($mulai - 55), $kunjPertama]);
        }

        // ---- verifikasi hasil trigger
        $cek = q("SELECT (SELECT COUNT(*) FROM pembayaran.tagihan_pendaftaran WHERE PENDAFTARAN = ?) tagihan,
                         (SELECT COUNT(*) FROM berkas_klaim.kelengkapan WHERE NOPEN = ?) klaim,
                         (SELECT COUNT(*) FROM pendaftaran.kunjungan WHERE NOPEN = ? AND STATUS != 0) kunjungan", [$nopen, $nopen, $nopen])->fetch();
        if ((int) $cek['kunjungan'] !== count($baris['kunjungan'])) { throw new RuntimeException("jumlah kunjungan $nopen tidak sesuai"); }
        $baris['tagihan'] = (int) $cek['tagihan'] > 0 ? 'ya' : 'TIDAK';
        $baris['kelengkapan_klaim'] = (int) $cek['klaim'] > 0 ? 'ya' : 'TIDAK';
        $ringkasan[] = $baris;
    }

    if ($MODE === 'cek') { $db->rollBack(); } else { $db->commit(); }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    berhenti('gagal, SEMUA perubahan dikembalikan (ROLLBACK): ' . get_class($e) . ': ' . $e->getMessage());
}

// ---------------------------------------------------------------- laporan
info('');
info(sprintf('%-3s %-15s %-8s %-12s %-19s %-19s %-10s %s', 'No', 'Pasien', 'NORM', 'NOPEN', 'SEP (dummy)', 'Kunjungan', 'Status', 'Ruangan / DPJP / ICD'));
foreach ($ringkasan as $b) {
    info(sprintf('%-3s %-15s %-8s %-12s %-19s %-19s %-10s %s', $b['no'], $b['nama'], $b['norm'], $b['nopen'], $b['sep'], $b['kunjungan'][0], $b['status'],
        $b['ruangan'] . ' / ' . $b['dpjp'] . ' / ' . $b['icd']));
    for ($k = 1; $k < count($b['kunjungan']); $k++) { info(sprintf('%-63s %-19s', '', $b['kunjungan'][$k])); }
}
info('');
info('Tagihan terbentuk (trigger): ' . implode(' ', array_column($ringkasan, 'tagihan')) . '   kelengkapan berkas klaim: ' . implode(' ', array_column($ringkasan, 'kelengkapan_klaim')));
info('Petugas pendaftaran (OLEH): ' . $olehLogin . " (ID $OLEH)");
if ($MODE === 'cek') {
    info('MODE CEK: semua langkah berhasil dijalankan lalu DIBATALKAN (ROLLBACK). Tidak ada data yang tersimpan.');
} elseif ($HASIL !== '') {
    file_put_contents($HASIL, json_encode($ringkasan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    info("TERSIMPAN. Rincian: $HASIL");
}
