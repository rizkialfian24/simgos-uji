#!/usr/bin/env bash
# simgos-uji — kembalikan template yang diubah qr-info-doc.sh dari folder cadangannya.
#   sudo bash template-qr/kembalikan-qr.sh /var/backups/simgos-qr/<waktu> [--root DIR] [--paksa]
# Berkas yang sudah diubah lagi sesudahnya (sha256 tidak sama dengan catatan) dilewati kecuali --paksa.
set -Eeuo pipefail
BK=${1:?Pakai: kembalikan-qr.sh <folder cadangan>}; shift
ROOT=/var/www/html/production/webapps/webservice/reports PAKSA=0
while (($#)); do case $1 in --root) ROOT=${2:?}; shift ;; --paksa) PAKSA=1 ;; *) echo "Opsi tidak dikenal: $1"; exit 2 ;; esac; shift; done
[[ -f $BK/MANIFEST.tsv ]] || { echo "Manifest tidak ada: $BK/MANIFEST.tsv"; exit 1; }
n=0 l=0
while IFS=$'\t' read -r rel before after; do
  [[ $rel == \#* || -z $rel ]] && continue
  dst=$ROOT/$rel; src=$BK/$rel
  now=$(sha256sum < "$dst" | cut -d' ' -f1)
  if [[ $now == "$before" ]]; then echo "  sudah asli  $rel"; continue; fi
  if [[ $now != "$after" && $PAKSA == 0 ]]; then echo "  LEWATI      $rel (diubah lagi sesudah qr-info-doc; --paksa untuk menimpa)"; l=$((l+1)); continue; fi
  cat "$src" > "$dst"                       # tulis isi ke berkas yang sama: pemilik/izin/label tetap
  [[ $(sha256sum < "$dst" | cut -d' ' -f1) == "$before" ]] && { echo "  dikembalikan $rel"; n=$((n+1)); } || echo "  GAGAL       $rel"
done < "$BK/MANIFEST.tsv"
echo "Selesai: $n dikembalikan, $l dilewati."
