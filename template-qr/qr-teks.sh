#!/usr/bin/env bash
# =====================================================================================================
# simgos-uji — QR TTE bawaan (template RS): isi yang ada (ID + data dokumen) dipertahankan, teks petunjuk verifikasi ditambahkan.
#
#   sudo bash template-qr/qr-teks.sh --cek          tampilkan rencana (tidak mengubah apa pun)
#   sudo bash template-qr/qr-teks.sh                terapkan (ketik YA); cadangan + manifest dibuat dulu
#   opsi: --teks "baris 1|baris 2|..."   ganti teks (| = baris baru; tanpa " dan \)
#         --tanpa-id                     jangan cantumkan "ID : <kode dokumen>" di baris pertama
#         --root DIR   folder reports (bawaan /var/www/html/production/webapps/webservice/reports)
#         --kode KODE  kode PPK template RS (bawaan 1306015)
#   Pembatalan: sudo bash template-qr/kembalikan-qr.sh <folder cadangan>
#
#   Database TIDAK diubah dan tidak dibaca. Yang diubah hanya ekspresi QR TTE (QR berisi kode dokumen atau INFO_DOC)
#   di berkas <KODE>-*.jrxml. Bisa dijalankan ulang dengan --teks lain untuk mengganti teks.
#   Sesudahnya: template KlaimTTE yang berkasnya berubah perlu didaftarkan ulang (daftar-template.php --cek).
# =====================================================================================================
set -Eeuo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$HERE/../skrip/pastikan-server-dev.sh"
ROOT=/var/www/html/production/webapps/webservice/reports KODE=1306015 MODE=terapkan PAKAI_ID=1
TEKS='Dokumen ini ditandatangani secara elektronik.|Silakan cek keaslian berkas PDF di:|https://tte.komdigi.go.id/verifyPDF'
while (($#)); do
  case $1 in
    --cek) MODE=cek ;; --teks) TEKS=${2:?}; shift ;; --tanpa-id) PAKAI_ID=0 ;;
    --root) ROOT=${2:?}; shift ;; --kode) KODE=${2:?}; shift ;;
    -h|--help) sed -n '/^# simgos-uji/,/^# =====/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opsi tidak dikenal: $1"; exit 2 ;;
  esac; shift
done
pastikan_server_dev
[[ -d $ROOT ]] || { echo "Folder reports tidak ada: $ROOT (pakai --root)"; exit 1; }
[[ $KODE =~ ^[0-9]{5,}$ ]] || { echo 'Kode tidak valid'; exit 2; }
[[ $MODE == cek || $EUID -eq 0 ]] || { echo 'Mode terapkan butuh sudo (berkas template milik pengguna web).'; exit 1; }
echo "simgos-uji — teks QR TTE pada template RS ($([[ $MODE == cek ]] && echo 'MODE CEK' || echo 'TERAPKAN'))  server: $(hostname)"

BK=''
run() { php -r 'echo json_encode(["mode"=>$argv[1],"root"=>$argv[2],"kode"=>$argv[3],"backup"=>$argv[4],"baris"=>explode("|",$argv[5]),"pakai_id"=>$argv[6]==="1"]);' \
          "$1" "$ROOT" "$KODE" "$BK" "$TEKS" "$PAKAI_ID" | php -d display_errors=stderr "$HERE/qr-teks.php"; }
run cek
if [[ $MODE == cek ]]; then echo 'MODE CEK: tidak ada berkas yang diubah.'; exit 0; fi
read -r -p "  Terapkan? Ketik YA: " j; [[ $j == YA ]] || { echo 'Dibatalkan.'; exit 1; }
BK=/var/backups/simgos-qr/teks-$(date +%Y%m%d-%H%M%S); mkdir -p "$BK"; chmod 700 "$BK"
run terapkan | tail -1
echo "Pembatalan: sudo bash $HERE/kembalikan-qr.sh $BK"
echo 'Berikutnya: php .../KlaimTTE/tools/daftar-template.php --cek (template yang berubah perlu didaftarkan ulang).'
