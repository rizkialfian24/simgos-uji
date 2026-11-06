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
