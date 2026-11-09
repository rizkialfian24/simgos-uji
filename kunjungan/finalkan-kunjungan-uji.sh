#!/usr/bin/env bash
# simgos-uji — jadikan pasien uji "acc semua" (FINAL + BERKAS LENGKAP) di server DEV.
#   bash kunjungan/finalkan-kunjungan-uji.sh --cek            coba lalu ROLLBACK
#   bash kunjungan/finalkan-kunjungan-uji.sh                  pasien 6-10 (ketik YA)
#   bash kunjungan/finalkan-kunjungan-uji.sh --pasien 1-5     nomor lain (mis. 7, atau 1-10)
# Kunjungan aktif diselesaikan, Final RM, tagihan dikunci & final, kelengkapan Berkas Klaim = Lengkap.
# Rawat inap yang masih dirawat dilewati (pulangkan lewat menu Pasien Pulang). Data master & struktur tidak diubah.
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$HERE/../skrip/pastikan-server-dev.sh"; source "$HERE/_koneksi.sh"
MODE=final PASIEN=6-10
while (($#)); do case $1 in --cek) MODE=final-cek ;; --pasien) PASIEN=${2:?}; shift ;; *) echo "Opsi tidak dikenal: $1"; exit 2 ;; esac; shift; done
[[ $PASIEN =~ ^[0-9]{1,2}(-[0-9]{1,2})?$ ]] || { echo 'format --pasien: 6-10 atau 7'; exit 2; }
pastikan_server_dev
echo "simgos-uji — pasien uji $PASIEN jadi FINAL + BERKAS LENGKAP ($([[ $MODE == final-cek ]] && echo 'MODE CEK' || echo 'TERAPKAN'))  server: $(hostname)"
siapkan_koneksi ''
if [[ $MODE == final ]]; then read -r -p '  Lanjutkan? Ketik YA: ' j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }; fi
rc=0; KT_PASIEN=$PASIEN jalankan_php "$MODE" '' '' || rc=$?
unset DBPASS; exit $rc
