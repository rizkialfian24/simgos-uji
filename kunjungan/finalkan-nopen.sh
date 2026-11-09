#!/usr/bin/env bash
# =====================================================================================================
# simgos-uji — FINALKAN pendaftaran pasien uji per NOPEN di server SIMGOS DEV (berkas mandiri, tanpa git).
#
#   bash finalkan-nopen.sh --cek 2611060006 2611060007     coba lalu ROLLBACK (tidak menyimpan)
#   bash finalkan-nopen.sh 2611060006 2611060007           tampilkan hasil uji, lalu terapkan (ketik YA)
#
# Per NOPEN, dengan kolom/trigger yang sama dengan tombol SIMGOS:
#   1. rawat inap yang masih dirawat -> Pasien Pulang (Diijinkan Pulang, Membaik); trigger menutup kunjungan
#   2. kunjungan lain yang masih aktif -> selesai
#   3. Final RM (Detail Kunjungan: FINAL_HASIL)
#   4. tagihan dikunci lalu difinalkan (tanpa transaksi kasir)
#   5. Kelengkapan Berkas Klaim = Lengkap
# Hanya NOPEN milik pasien uji (nama "UJI KLAIMTTE n", NIK 9999...). Struktur tabel & data master tidak diubah
# (17 tabel master dibandingkan sebelum/sesudah; bila berubah -> ROLLBACK). Status akhir diperiksa = aturan KlaimTTE.
# Koneksi: ~/.my.cnf bila ada, selain itu akun MySQL + password (ditanya, tidak ditampilkan/disimpan).
# =====================================================================================================
set -Eeuo pipefail
[[ -f /etc/simgos-dev ]] || { echo 'BERHENTI: bukan server dev (/etc/simgos-dev tidak ada). Jangan jalankan di produksi.'; exit 1; }
MODE=terapkan; NOPEN=()
for a in "$@"; do
  case $a in
    --cek) MODE=cek ;;
    -h|--help) sed -n '/^# simgos-uji/,/^# =====/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) [[ $a =~ ^[0-9]{10}$ ]] || { echo "NOPEN tidak valid: $a (10 digit)"; exit 2; }; NOPEN+=("$a") ;;
  esac
done
(( ${#NOPEN[@]} )) || { echo 'Sebutkan NOPEN, mis.: bash finalkan-nopen.sh --cek 2611060006 2611060007'; exit 2; }
command -v php >/dev/null || { echo 'php tidak ada'; exit 1; }

# ---- koneksi MySQL (password lewat variabel lingkungan proses, tidak dicetak)
export KT_DB_USER='' KT_DB_PASS='' KT_DB_DSN=''
if [[ -r $HOME/.my.cnf ]]; then
  mapfile -d '' -t f < <(php -r '$c=@parse_ini_file(getenv("HOME")."/.my.cnf",true,INI_SCANNER_RAW)?:[];$k=$c["client"]??[];
    $v=fn($x)=>preg_replace("/^([\"\x27])(.*)\\1$/s","$2",(string)($k[$x]??""));echo $v("user"),"\0",$v("password"),"\0",$v("socket"),"\0";')
  KT_DB_USER=${f[0]:-}; KT_DB_PASS=${f[1]:-}; S=${f[2]:-}
fi
if [[ -z $KT_DB_USER || -z $KT_DB_PASS ]]; then
  read -r -p "Akun MySQL [admin]: " KT_DB_USER; KT_DB_USER=${KT_DB_USER:-admin}
  read -r -s -p "Password MySQL untuk '$KT_DB_USER': " KT_DB_PASS; echo
fi
for c in "${S:-}" /var/lib/mysql/mysql.sock /var/run/mysqld/mysqld.sock; do [[ -n $c && -S $c ]] && { KT_DB_DSN="mysql:unix_socket=$c;charset=utf8mb4"; break; }; done
KT_DB_DSN=${KT_DB_DSN:-mysql:host=127.0.0.1;charset=utf8mb4}

jalankan() {   # $1 = cek | terapkan
php -d display_errors=stderr -- "$1" "${NOPEN[@]}" <<'PHP'
<?php
declare(strict_types=1);
[$mode, $daftar] = [$argv[1], array_slice($argv, 2)];
function berhenti(string $m): never { fwrite(fopen("php://stderr", "w"), "BERHENTI: $m\n"); exit(1); }
try {
    $db = new PDO(getenv('KT_DB_DSN'), getenv('KT_DB_USER'), getenv('KT_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { berhenti('koneksi MySQL gagal' . ((int) ($e->errorInfo[1] ?? 0) === 1045 ? ' (akun/password salah)' : '') . '.'); }
putenv('KT_DB_PASS');
$db->exec("SET time_zone = @@GLOBAL.time_zone");
function q(string $sql, array $p = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function satu(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

// ---- pengaman data master
// $bebas = ID tempat tidur yang ditempati pasien uji yang dipulangkan: trigger SIMGOS mengosongkannya (boleh berubah).
function sidik(array $bebas = []): array {
    $out = [];
    $tabel = q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='master' AND TABLE_TYPE='BASE TABLE'
        AND (TABLE_NAME LIKE 'tarif%' OR TABLE_NAME IN ('ruangan','ruang_kamar','ruang_kamar_tidur','dokter','dokter_ruangan','dokter_smf',
        'pegawai','perawat','referensi','paket','ppk','smf_ruangan')) ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabel as $t) {
        $cols = q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='master' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION", [$t])->fetchAll(PDO::FETCH_COLUMN);
        $e = implode(',', array_map(fn($c) => "IFNULL(CAST(`" . str_replace('`', '', $c) . "` AS CHAR),'~')", $cols));
        $w = ($t === 'ruang_kamar_tidur' && $bebas) ? ' WHERE ID NOT IN (' . implode(',', array_map('intval', $bebas)) . ')' : '';
        $out[$t] = implode(':', q("SELECT COUNT(*), IFNULL(BIT_XOR(CRC32(CONCAT_WS('|',$e))),0) FROM master.`" . str_replace('`', '', $t) . "`$w")->fetch(PDO::FETCH_NUM));
    }
    return $out;
}
// ---- status menurut aturan KlaimTTE (RegistrationReadService::withServiceStatus)
function status(string $nopen): string {
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

$db->beginTransaction();
try {
    $ph = implode(',', array_fill(0, count($daftar), '?'));
    $bebas = q("SELECT DISTINCT k.RUANG_KAMAR_TIDUR FROM pendaftaran.kunjungan k JOIN master.ruangan r ON r.ID = k.RUANGAN
        WHERE k.NOPEN IN ($ph) AND k.STATUS = 1 AND r.JENIS_KUNJUNGAN = 3 AND k.RUANG_KAMAR_TIDUR > 0", $daftar)->fetchAll(PDO::FETCH_COLUMN);
    $awal = sidik($bebas);
    foreach ($daftar as $nopen) {
        $p = q("SELECT pd.NORM, pd.OLEH, pd.STATUS, ps.NAMA FROM pendaftaran.pendaftaran pd JOIN master.pasien ps ON ps.NORM = pd.NORM
            WHERE pd.NOMOR = ?", [$nopen])->fetch();
        if (!$p) { throw new RuntimeException("NOPEN $nopen tidak ada"); }
        $uji = str_starts_with((string) $p['NAMA'], 'UJI KLAIMTTE ')
            && satu("SELECT 1 FROM master.kartu_identitas_pasien WHERE NORM = ? AND JENIS = 1 AND NOMOR LIKE '9999%'", [$p['NORM']]);
        if (!$uji) { throw new RuntimeException("NOPEN $nopen milik '{$p['NAMA']}', BUKAN pasien uji; tidak disentuh"); }
        if ((int) $p['STATUS'] === 0) { throw new RuntimeException("NOPEN $nopen sudah dibatalkan"); }
        $oleh = (int) $p['OLEH'];
        $sebelum = status($nopen);
        $langkah = [];
        // 1. rawat inap aktif -> Pasien Pulang (trigger onAfterInsertPasienPulang menutup kunjungan)
        foreach (q("SELECT k.NOMOR, k.DPJP FROM pendaftaran.kunjungan k JOIN master.ruangan r ON r.ID = k.RUANGAN
            WHERE k.NOPEN = ? AND k.STATUS = 1 AND r.JENIS_KUNJUNGAN = 3", [$nopen])->fetchAll() as $ri) {
            $diag = (string) (satu("SELECT DIAGNOSA FROM medicalrecord.diagnosa WHERE NOPEN = ? AND UTAMA = 1 AND STATUS = 1 ORDER BY TANGGAL DESC LIMIT 1", [$nopen]) ?: '-');
            q("INSERT INTO layanan.pasien_pulang (KUNJUNGAN, NOPEN, TANGGAL, CARA, KEADAAN, DIAGNOSA, DOKTER, OLEH, STATUS) VALUES (?, ?, NOW(), 1, 2, ?, ?, ?, 1)",
              [$ri['NOMOR'], $nopen, mb_substr($diag, 0, 150), (int) $ri['DPJP'], $oleh]);
            if ((int) satu("SELECT STATUS FROM pendaftaran.kunjungan WHERE NOMOR = ?", [$ri['NOMOR']]) !== 2) {
                throw new RuntimeException("trigger pasien pulang tidak menutup kunjungan {$ri['NOMOR']}");
            }
            $langkah[] = 'pasien pulang';
        }
        // 2. kunjungan lain yang masih aktif -> selesai
        if (q("UPDATE pendaftaran.kunjungan SET KELUAR = NOW(), STATUS = 2 WHERE NOPEN = ? AND STATUS = 1", [$nopen])->rowCount()) { $langkah[] = 'kunjungan selesai'; }
        // 3. Final RM
        $dpjp = (int) (satu("SELECT u.ID FROM pendaftaran.kunjungan k JOIN master.dokter d ON d.ID = k.DPJP JOIN aplikasi.pengguna u ON u.NIP = d.NIP AND u.STATUS = 1
            WHERE k.NOPEN = ? AND k.STATUS != 0 ORDER BY k.MASUK LIMIT 1", [$nopen]) ?: $oleh);
        if (q("UPDATE pendaftaran.kunjungan SET FINAL_HASIL = 1, FINAL_HASIL_OLEH = ?, FINAL_HASIL_TANGGAL = NOW() WHERE NOPEN = ? AND STATUS = 2 AND FINAL_HASIL != 1",
              [$dpjp, $nopen])->rowCount()) { $langkah[] = 'final RM'; }
        // 4. tagihan: kunci lalu final (trigger onAfterUpdateTagihan berjalan seperti dari Kasir)
        $tg = q("SELECT TAGIHAN FROM pembayaran.tagihan_pendaftaran WHERE PENDAFTARAN = ? AND STATUS = 1", [$nopen])->fetchAll(PDO::FETCH_COLUMN);
        if (!$tg) { throw new RuntimeException("tagihan NOPEN $nopen tidak ada"); }
        foreach ($tg as $t) {
            q("UPDATE pembayaran.tagihan SET KUNCI = 1, OLEH = ? WHERE ID = ? AND KUNCI = 0", [$oleh, $t]);
            if (q("UPDATE pembayaran.tagihan SET STATUS = 2, OLEH = ? WHERE ID = ? AND STATUS = 1", [$oleh, $t])->rowCount()) { $langkah[] = 'tagihan final'; }
        }
        // 5. Kelengkapan Berkas Klaim = Lengkap
        if (!satu("SELECT 1 FROM berkas_klaim.kelengkapan WHERE NOPEN = ?", [$nopen])) { throw new RuntimeException("kelengkapan berkas klaim NOPEN $nopen tidak ada"); }
        if (q("UPDATE berkas_klaim.kelengkapan SET STATUS = 2 WHERE NOPEN = ? AND STATUS != 2", [$nopen])->rowCount()) { $langkah[] = 'berkas lengkap'; }

        $sesudah = status($nopen);
        if ($sesudah !== 'FINAL + BERKAS LENGKAP') { throw new RuntimeException("NOPEN $nopen: status jadi '$sesudah'"); }
        printf("  %s  %-16s %-22s -> %s  (%s)\n", $nopen, $p['NAMA'], $sebelum, $sesudah, $langkah ? implode(', ', $langkah) : 'sudah final');
    }
    $berubah = array_keys(array_diff_assoc(sidik($bebas), $awal));
    if ($berubah) { throw new RuntimeException('data master berubah (' . implode(', ', $berubah) . ')'); }
    foreach ($bebas as $tt) {
        if ((int) satu("SELECT STATUS FROM master.ruang_kamar_tidur WHERE ID = ?", [$tt]) !== 1) { throw new RuntimeException("tempat tidur ID $tt tidak kembali kosong"); }
    }
    echo '  data master: ' . count($awal) . " tabel diperiksa, tidak ada yang berubah"
        . ($bebas ? ' (selain tempat tidur pasien uji yang dipulangkan, kini kosong: ID ' . implode(', ', $bebas) . ')' : '') . "\n";
    if ($mode === 'cek') { $db->rollBack(); echo "  (uji: semua dikembalikan / ROLLBACK)\n"; }
    else { $db->commit(); echo "TERSIMPAN. Klik refresh di daftar KlaimTTE.\n"; }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    berhenti('gagal, SEMUA dikembalikan (ROLLBACK): ' . $e->getMessage());
}
PHP
}

echo "simgos-uji — finalkan NOPEN uji  server: $(hostname)"
echo '== uji (ROLLBACK)'; jalankan cek
if [[ $MODE == cek ]]; then echo 'MODE CEK: tidak ada yang diubah.'; exit 0; fi
read -r -p '  Terapkan sungguhan? Ketik YA: ' j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }
echo '== terapkan'; jalankan terapkan
