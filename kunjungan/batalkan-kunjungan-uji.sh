#!/usr/bin/env bash
# simgos-uji — batalkan SEMUA pendaftaran milik pasien uji (nama "UJI KLAIMTTE n" + NIK 9999…) di server DEV.
#   bash kunjungan/batalkan-kunjungan-uji.sh --cek   tampilkan yang akan dibatalkan (ROLLBACK)
#   bash kunjungan/batalkan-kunjungan-uji.sh         batalkan (status 0; tempat tidur dikosongkan oleh trigger)
# Data pasien uji dipertahankan (dipakai lagi oleh buat-kunjungan-uji.sh).
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$HERE/../skrip/pastikan-server-dev.sh"; source "$HERE/_koneksi.sh"
MODE=batal; [[ ${1:-} == --cek ]] && MODE=batal-cek
pastikan_server_dev
siapkan_koneksi ''
if [[ $MODE == batal ]]; then read -r -p '  Batalkan semua pendaftaran pasien uji? Ketik YA: ' j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }; fi
rc=0; jalankan_php "$MODE" '' '' || rc=$?
unset DBPASS; exit $rc
