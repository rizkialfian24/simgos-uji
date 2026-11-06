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
| `kunjungan/` | pembuat & pembatal kunjungan uji (sedang disusun) |
| `skrip/` | penjaga server dev, pemeriksa rahasia, hook pre-commit |
