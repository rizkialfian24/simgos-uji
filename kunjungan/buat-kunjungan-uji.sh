#!/usr/bin/env bash
# =====================================================================================================
# simgos-uji — buat 5 kunjungan uji KlaimTTE (BPJS + SEP dummy) di server SIMGOS DEV, hari ini.
#
#   bash kunjungan/buat-kunjungan-uji.sh --cek     jalankan semua langkah lalu ROLLBACK (tidak ada data tersimpan)
#   bash kunjungan/buat-kunjungan-uji.sh           buat sungguhan (meminta konfirmasi "YA")
#   opsi: --oleh LOGIN  akun petugas pendaftaran (bawaan: petugas yang paling sering mendaftarkan pasien)
#         -u AKUN       akun MySQL (bawaan: ~/.my.cnf, atau 'admin' dengan password ditanya)
#         --ya          tanpa konfirmasi
#         --dengan-tempat-tidur  pasien rawat inap menempati tempat tidur kosong (status tempat tidur di master berubah).
#                       Bawaan: TANPA tempat tidur, data master (ruangan, tarif, dokter, tempat tidur) tidak disentuh sama sekali.
#
#   1 Rawat jalan Poli Penyakit Dalam (I10)   2 Rawat jalan Poli Bedah (K35.8)   3 Rawat jalan Poli Anak (J06.9)
#   4 IGD (A09)                               5 IGD -> rawat inap penyakit dalam, masih dirawat (J18.9)
#   Ruangan, dokter, tarif: yang SUDAH ADA di server ini (dipilih otomatis bila pilihan vbox tidak ada); tidak diubah.
#   Pasien: "UJI KLAIMTTE 1..5", NIK 9999…, No. BPJS 9999…, SEP 9999R…; dipakai ulang bila sudah ada.
#   --status: buat pasien 6-10, satu per status berkas KlaimTTE (lihat README):
#     6 MASIH DILAYANI  7 RM BELUM FINAL  8 TAGIHAN BELUM FINAL  9 FINAL + BERKAS BELUM LENGKAP  10 FINAL + BERKAS LENGKAP
#   Batalkan semua kunjungan uji: bash kunjungan/batalkan-kunjungan-uji.sh
# =====================================================================================================
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=../skrip/pastikan-server-dev.sh
source "$HERE/../skrip/pastikan-server-dev.sh"; source "$HERE/_koneksi.sh"
MODE=buat OLEH='' AKUN='' YA=0 TT=0 SET=''
while (($#)); do
  case $1 in
    --cek) MODE=cek ;; --dengan-tempat-tidur) TT=1 ;; --status) SET=status ;; --oleh) OLEH=${2:?}; shift ;; -u) AKUN=${2:?}; shift ;; --ya) YA=1 ;;
    -h|--help) sed -n '/^# simgos-uji/,/^# =====/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opsi tidak dikenal: $1"; exit 2 ;;
  esac; shift
done
pastikan_server_dev
echo "simgos-uji — 5 kunjungan uji KlaimTTE $([[ $SET == status ]] && echo '6-10 per status' || echo '1-5') ($([[ $MODE == cek ]] && echo 'MODE CEK: tidak menyimpan' || echo 'BUAT'))  server: $(hostname)"
siapkan_koneksi "$AKUN"
if [[ $MODE == buat && $YA == 0 ]]; then
  read -r -p '  Buat 5 pendaftaran uji sungguhan di server ini? Ketik YA: ' j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }
fi
mkdir -p "$HERE/../hasil"; HASIL="$HERE/../hasil/kunjungan-uji-$(date +%Y%m%d-%H%M%S).json"
[[ $MODE == cek ]] && HASIL=''
rc=0; jalankan_php "$MODE" "$OLEH" "$HASIL" "$TT" "$SET" || rc=$?
unset DBPASS
exit $rc
