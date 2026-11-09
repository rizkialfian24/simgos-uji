# simgos-uji — alat data uji untuk server SIMGOS **DEV**

Repositori privat, terpisah dari `klaimtte`. Berisi skrip yang **menulis data uji** (pasien fiktif, kunjungan,
rekam medis minimal) untuk menguji KlaimTTE dan modul SIMGOS lain.

**Tidak boleh dijalankan di server produksi.** Setiap skrip yang menulis data memeriksa berkas penanda
`/etc/simgos-dev` dan berhenti bila tidak ada. Buat penanda itu hanya di server dev:

```bash
echo "dev $(hostname) $(date +%F)" | sudo tee /etc/simgos-dev
```

## Penanda data uji
- Nama pasien: `UJI KLAIMTTE <n>`
- NIK, No. BPJS, No. SEP: diawali `9999`
- Pembatalan: lewat pembatalan kunjungan SIMGOS (bukan DELETE), dengan skrip pasangan

## Isi
| Folder | Isi |
|---|---|
| `kunjungan/` | pembuat & pembatal 5 kunjungan uji KlaimTTE |
| `template-qr/` | QR TTE bawaan (versi dev). **Untuk produksi pakai `klaimtte/alat-simgos/template-qr/`** (tanpa penjaga server dev) |
| `skrip/` | penjaga server dev, pemeriksa rahasia, hook pre-commit |

## 5 kunjungan uji KlaimTTE

```bash
bash kunjungan/buat-kunjungan-uji.sh --cek    # jalankan semua langkah lalu ROLLBACK (aman, tidak menyimpan)
bash kunjungan/buat-kunjungan-uji.sh          # buat sungguhan (ketik YA)
bash kunjungan/batalkan-kunjungan-uji.sh --cek
bash kunjungan/batalkan-kunjungan-uji.sh      # batalkan semua pendaftaran pasien uji
```

| No | Jenis | Ruangan | DPJP | ICD-10 |
|---|---|---|---|---|
| 1 | Rawat jalan | Poli Penyakit Dalam | dokter011 | I10 |
| 2 | Rawat jalan | Poli Bedah | dokter030 | K35.8 |
| 3 | Rawat jalan | Poli Anak | dokter017 | J06.9 |
| 4 | IGD | IGD | dokter037 | A09 |
| 5 | IGD → rawat inap (masih dirawat, tanpa tempat tidur) | IGD → RI Penyakit Dalam Kelas III | dokter037 → dokter011 | J18.9 |

### Pasien 6–10: satu pasien per status berkas KlaimTTE (`--status`)

```bash
bash kunjungan/buat-kunjungan-uji.sh --status --cek   # coba lalu ROLLBACK
bash kunjungan/buat-kunjungan-uji.sh --status         # buat sungguhan (ketik YA)
```

| No | Jenis | Status di KlaimTTE | Yang dikerjakan skrip (kolom yang sama dengan tombol SIMGOS) |
|---|---|---|---|
| 6 | RJ Penyakit Dalam (E11.9) | MASIH DILAYANI | kunjungan dibiarkan aktif |
| 7 | RJ Bedah (K40.9) | RM BELUM FINAL | kunjungan selesai, RM belum Final |
| 8 | RJ Anak (J20.9) | TAGIHAN BELUM FINAL | + Final RM (`kunjungan.FINAL_HASIL`) |
| 9 | IGD (K29.7) | ✓ FINAL + BERKAS BELUM LENGKAP | + tagihan dikunci & difinalkan (`tagihan.KUNCI`, `STATUS = 2`) |
| 10 | RJ Penyakit Dalam (I10) | ✓ FINAL + ✓ BERKAS LENGKAP | + kelengkapan Berkas Klaim `STATUS = 2` |

Status tiap pasien diperiksa di akhir dengan aturan yang sama dengan KlaimTTE; bila tidak sesuai, semuanya di-ROLLBACK.
Final tagihan **tanpa** transaksi kasir (`pembayaran_tagihan`); trigger SIMGOS tetap berjalan (antrean hitung distribusi
tarif, outbox `TAGIHAN_FINAL`, pendaftaran final). Pembatalan: `batalkan-kunjungan-uji.sh` (tagihan final tetap tercatat).

### Jadikan pasien uji "acc semua" (FINAL + BERKAS LENGKAP)

```bash
bash kunjungan/finalkan-kunjungan-uji.sh --cek           # pasien 6-10, coba lalu ROLLBACK
bash kunjungan/finalkan-kunjungan-uji.sh                 # terapkan (ketik YA)
bash kunjungan/finalkan-kunjungan-uji.sh --pasien 1-4    # nomor lain
```
Kunjungan aktif diselesaikan, Final RM, tagihan dikunci & final, kelengkapan Berkas Klaim = Lengkap. Rawat inap yang
masih dirawat dilewati (pulangkan lewat menu Pasien Pulang). Status diperiksa dan data master dijaga seperti di atas.

**Hanya menambah pasien & kunjungan.** Ruangan, tarif, dokter, tempat tidur: memakai yang sudah ada dan TIDAK diubah.
Pengaman: sidik jari 17 tabel master (ruangan, kamar, tempat tidur, dokter, SMF, pegawai, perawat, referensi, paket, ppk,
semua `tarif*`) dihitung sebelum & sesudah di dalam transaksi; bila ada yang berubah (termasuk oleh trigger) semuanya
di-ROLLBACK. Rawat inap bawaan TANPA tempat tidur; `--dengan-tempat-tidur` bila status tempat tidur boleh berubah.

Semua BPJS (penjamin 2, peserta 21) dengan SEP dummy `9999R…`. Yang diisi: pasien + KTP + kartu BPJS, pendaftaran,
tujuan, penjamin, kunjungan (diterima), anamnesis, keluhan utama, diagnosa utama; mutasi IGD -> rawat inap untuk no. 5.
Tagihan, karcis, antrian, kelengkapan berkas klaim, akomodasi: dibuat trigger SIMGOS seperti pendaftaran biasa.
Ruangan & dokter: pilihan di vbox dipakai bila valid; di server lain dipilih **otomatis** (ruangan menurut jenis
& nama, dokter siap TTE: akun aktif + NIK KTP 16 digit + terdaftar di ruangan; rawat inap: ada tempat tidur kosong).
Pilihan dicetak di awal (`pilihan`/`otomatis`). Lihat pilihan otomatis di vbox: `KT_ABAIKAN_PILIHAN=1 bash kunjungan/buat-kunjungan-uji.sh --cek`.
Koneksi: `~/.my.cnf` bila ada, selain itu `-u akun` + password. Rincian hasil: `hasil/` (tidak masuk Git).
Belum diisi: resume medis (banyak tabel turunan) — dokumen resume belum muncul untuk pasien uji.

## QR TTE bawaan berisi INFO_DOC pada template RS

SIMGOS memilih template `<KODE PPK instansi>-<nama>.jrxml`. Template acuan SIMGOS (`7371325-*`) sudah memakai QR
`"ID : " + REQUEST_REPORT_ID + "\n" + INFO_DOC` (kolom INFO_DOC dari prosedur cetak bawaan). Template RS (`1306015-*`)
di server dev baru masih QR "hanya kode". Skrip ini menyamakan HANYA 2 hal per berkas: deklarasi field INFO_DOC (bagian
utama, bukan subDataset) dan ekspresi QR. Syarat per berkas: berkas biasa, QR persis `$P{REQUEST_REPORT_ID}`, prosedur
query UTAMA mengeluarkan INFO_DOC, template acuan memakai prosedur & QR INFO_DOC yang sama, hasil XML valid.
Database tidak diubah (hanya dibaca).

```bash
sudo bash template-qr/qr-info-doc.sh --cek        # rencana (tidak mengubah)
sudo bash template-qr/qr-info-doc.sh              # terapkan (YA); cadangan: /var/backups/simgos-qr/<waktu>
sudo bash template-qr/kembalikan-qr.sh /var/backups/simgos-qr/<waktu>
```
Diuji di vbox pada salinan: 56 template diubah, kasus tidak aman dilewati, ulang = 0 perubahan, pembatalan
mengembalikan sha256 persis, dan ke-56 hasil terkompilasi JasperReports 6.19.1. Sesudahnya daftarkan ulang template
KlaimTTE yang berubah (`daftar-template.php --cek`).

## QR TTE bawaan: data dokumen (INFO_DOC) dipertahankan + teks petunjuk verifikasi PDF

```bash
sudo bash template-qr/qr-teks.sh --cek     # rencana
sudo bash template-qr/qr-teks.sh           # terapkan (YA); cadangan: /var/backups/simgos-qr/teks-<waktu>
sudo bash template-qr/qr-teks.sh --teks "Baris 1|Baris 2|https://..."   # ganti teks (| = baris baru)
sudo bash template-qr/kembalikan-qr.sh /var/backups/simgos-qr/teks-<waktu>
```
Isi QR (template ber-INFO_DOC; template tanpa INFO_DOC: ID + teks saja; INFO_DOC kosong -> barisnya dilewati):
```
ID : <kode dokumen>
NAMA INSTANSI : … NAMA PASIEN : … NO. RM : … NOPEN : … NAMA DOKUMEN : … TANGGAL TTD : … TTD OLEH : …
Silahkan konfirmasi keabsahan dokumen ke Instansi dengan ID diatas
Dokumen ini ditandatangani secara elektronik.
Silakan cek keaslian berkas PDF di:
https://tte.komdigi.go.id/verifyPDF
```
Mengubah hanya ekspresi QR TTE di template RS (dikenali dari susunannya, tanpa penanda komentar); QR lain tidak
disentuh; database tidak dibaca/diubah. Diuji di vbox pada salinan: 62 diubah, idempoten, teks bisa diganti,
62 terkompilasi JasperReports 6.19.1, hasil isi teks QR diverifikasi lewat fill Jasper (INFO_DOC ada/kosong),
pembatalan dua tahap kembali persis.
