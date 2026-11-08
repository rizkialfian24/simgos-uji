#!/usr/bin/env bash
# =====================================================================================================
# simgos-uji — QR TTE bawaan pada template RS berisi teks INFO_DOC (bawaan SIMGOS), seperti template acuan.
#
#   sudo bash template-qr/qr-info-doc.sh --cek          tampilkan rencana (tidak mengubah apa pun)
#   sudo bash template-qr/qr-info-doc.sh                terapkan (ketik YA); cadangan + manifest dibuat dulu
#   opsi: --root DIR    folder reports (bawaan /var/www/html/production/webapps/webservice/reports)
#         -u AKUN       akun MySQL untuk MEMBACA daftar prosedur (bawaan admin; password ditanya)
#         --kode KODE   kode PPK template RS (bawaan: dari aplikasi.instansi)   --acuan 7371325
#   Pembatalan: sudo bash template-qr/kembalikan-qr.sh <folder cadangan>
#
#   Database TIDAK diubah (hanya SELECT ke information_schema & aplikasi.instansi). Yang diubah hanya berkas
#   <KODE>-*.jrxml yang QR-nya "hanya kode dokumen" dan prosedurnya sudah mengeluarkan INFO_DOC.
#   Sesudahnya: template KlaimTTE yang berkasnya berubah perlu didaftarkan ulang (daftar-template.php --cek).
# =====================================================================================================
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$HERE/../skrip/pastikan-server-dev.sh"
ROOT=/var/www/html/production/webapps/webservice/reports AKUN=admin KODE='' ACUAN=7371325 MODE=terapkan
while (($#)); do
  case $1 in
    --cek) MODE=cek ;; --root) ROOT=${2:?}; shift ;; -u) AKUN=${2:?}; shift ;; --kode) KODE=${2:?}; shift ;; --acuan) ACUAN=${2:?}; shift ;;
    -h|--help) sed -n '/^# simgos-uji/,/^# =====/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opsi tidak dikenal: $1"; exit 2 ;;
  esac; shift
done
pastikan_server_dev
[[ -d $ROOT ]] || { echo "Folder reports tidak ada: $ROOT (pakai --root)"; exit 1; }
[[ $MODE == cek || $EUID -eq 0 ]] || { echo 'Mode terapkan butuh sudo (berkas template milik pengguna web).'; exit 1; }
[[ $ACUAN =~ ^[0-9]{5,}$ && ( -z $KODE || $KODE =~ ^[0-9]{5,}$ ) ]] || { echo 'Kode tidak valid'; exit 2; }

echo "simgos-uji — QR INFO_DOC pada template RS ($([[ $MODE == cek ]] && echo 'MODE CEK' || echo 'TERAPKAN'))  server: $(hostname)"
read -r -s -p "Password MySQL untuk akun '$AKUN' (hanya membaca): " PW; echo
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword="%s"\n' "$AKUN" "${PW//\"/\\\"}" > "$CNF"; unset PW
M=(mysql --defaults-extra-file="$CNF" -N -B)
if [[ -z $KODE ]]; then KODE=$("${M[@]}" -e "SELECT p.KODE FROM aplikasi.instansi i JOIN master.ppk p ON p.ID=i.PPK LIMIT 1"); fi
[[ $KODE =~ ^[0-9]{5,}$ ]] || { echo "Kode PPK instansi tidak terbaca: '$KODE' (pakai --kode)"; exit 1; }
[[ $KODE != "$ACUAN" ]] || { echo 'Kode RS sama dengan kode acuan; tidak ada yang perlu diubah.'; exit 0; }
PROC=$("${M[@]}" -e "SELECT CONCAT(ROUTINE_SCHEMA,'.',ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_TYPE='PROCEDURE' AND ROUTINE_DEFINITION LIKE '%INFO_DOC%'")
echo "  folder reports : $ROOT"
echo "  template RS    : $KODE-*.jrxml   acuan: $ACUAN-*.jrxml   prosedur ber-INFO_DOC: $(wc -l <<< "$PROC")"

BK=''
if [[ $MODE == terapkan ]]; then
  BK=/var/backups/simgos-qr/$(date +%Y%m%d-%H%M%S); mkdir -p "$BK"; chmod 700 "$BK"
fi
run() { php -r 'echo json_encode(["mode"=>$argv[1],"root"=>$argv[2],"kode"=>$argv[3],"acuan"=>$argv[4],"backup"=>$argv[5],"prosedur"=>array_values(array_filter(explode("\n",$argv[6])))]);' \
          "$1" "$ROOT" "$KODE" "$ACUAN" "$BK" "$PROC" | php -d display_errors=stderr "$HERE/qr-info-doc.php"; }
if [[ $MODE == cek ]]; then run cek; echo 'MODE CEK: tidak ada berkas yang diubah.'; exit 0; fi
run cek
read -r -p "  Terapkan perubahan di atas? Ketik YA: " j; [[ $j == YA ]] || { rmdir "$BK"; echo 'Dibatalkan.'; exit 1; }
run terapkan
echo "Pembatalan: sudo bash $HERE/kembalikan-qr.sh $BK"
echo 'Berikutnya: php .../KlaimTTE/tools/daftar-template.php --cek (template yang berubah perlu didaftarkan ulang).'
