#!/usr/bin/env bash
# =====================================================================================================
# simgos-uji — buat 5 kunjungan uji KlaimTTE (BPJS + SEP dummy) di server SIMGOS DEV, hari ini.
#
#   bash kunjungan/buat-kunjungan-uji.sh --cek     jalankan semua langkah lalu ROLLBACK (tidak ada data tersimpan)
#   bash kunjungan/buat-kunjungan-uji.sh           buat sungguhan (meminta konfirmasi "YA")
#   opsi: --oleh LOGIN  akun petugas pendaftaran (bawaan: petugas yang paling sering mendaftarkan pasien)
#         -u AKUN       akun MySQL (bawaan: ~/.my.cnf, atau 'admin' dengan password ditanya)
#         --ya          tanpa konfirmasi
#
#   1 Rawat jalan Poli Penyakit Dalam (I10)   2 Rawat jalan Poli Bedah (K35.8)   3 Rawat jalan Poli Anak (J06.9)
#   4 IGD (A09)                               5 IGD -> RI Penyakit Dalam Kelas III, masih dirawat (J18.9)
#   Pasien: "UJI KLAIMTTE 1..5", NIK 9999…, No. BPJS 9999…, SEP 9999R…; dipakai ulang bila sudah ada.
#   Batalkan semua kunjungan uji: bash kunjungan/batalkan-kunjungan-uji.sh
# =====================================================================================================
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=../skrip/pastikan-server-dev.sh
source "$HERE/../skrip/pastikan-server-dev.sh"; source "$HERE/_koneksi.sh"
MODE=buat OLEH='' AKUN='' YA=0
while (($#)); do
  case $1 in
    --cek) MODE=cek ;; --oleh) OLEH=${2:?}; shift ;; -u) AKUN=${2:?}; shift ;; --ya) YA=1 ;;
    -h|--help) sed -n '/^# simgos-uji/,/^# =====/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opsi tidak dikenal: $1"; exit 2 ;;
  esac; shift
done
pastikan_server_dev
echo "simgos-uji — 5 kunjungan uji KlaimTTE ($([[ $MODE == cek ]] && echo 'MODE CEK: tidak menyimpan' || echo 'BUAT'))  server: $(hostname)"
siapkan_koneksi "$AKUN"
if [[ $MODE == buat && $YA == 0 ]]; then
  read -r -p '  Buat 5 pendaftaran uji sungguhan di server ini? Ketik YA: ' j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }
fi
mkdir -p "$HERE/../hasil"; HASIL="$HERE/../hasil/kunjungan-uji-$(date +%Y%m%d-%H%M%S).json"
[[ $MODE == cek ]] && HASIL=''
rc=0; jalankan_php "$MODE" "$OLEH" "$HASIL" || rc=$?
unset DBPASS
exit $rc
