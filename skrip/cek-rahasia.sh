#!/usr/bin/env bash
# =====================================================================================================
# KlaimTTE — pastikan tidak ada rahasia / data pasien yang ikut ke Git. Nilai yang ditemukan TIDAK dicetak.
#   bash skrip/cek-rahasia.sh [PATH...]        periksa folder/berkas (bawaan: seluruh repo)
#   bash skrip/cek-rahasia.sh --staged         periksa berkas yang akan di-commit (dipakai hook pre-commit)
# Kode keluar: 0 bersih, 1 ada temuan.
# =====================================================================================================
set -uo pipefail
HERE=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
files=()
if [[ ${1:-} == --staged ]]; then
  while IFS= read -r -d '' f; do [[ -f $HERE/$f ]] && files+=("$HERE/$f"); done < <(git -C "$HERE" diff --cached --name-only -z --diff-filter=ACMR)
else
  (($#)) || set -- "$HERE"
  while IFS= read -r -d '' f; do files+=("$f"); done < <(find "$@" -path '*/.git' -prune -o -type f -print0)
fi
temuan=0
lapor() { printf '  [RAHASIA?] %s: %s\n' "${1#$HERE/}" "$2"; temuan=$((temuan+1)); }
for f in "${files[@]}"; do
  b=$(basename "$f")
  case $b in
    deploy.env|*.my.cnf|.my.cnf|*.pem|*.key|*.p12|*.pfx|id_rsa*) lapor "$f" 'jenis berkas rahasia'; continue ;;
    SHA256SUMS|*.png|*.jpg|*.gif|*.ttf|*.jar) continue ;;
  esac
  grep -Iq . "$f" 2>/dev/null || continue           # lewati biner
  grep -q -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$f" && lapor "$f" 'private key'
  # nilai kredensial yang bukan placeholder (<...>, contoh, kosong, variabel)
  K='(password|passwd|passphrase|secret|token|api_?key)'
  if grep -nEi "(['\"]$K['\"][[:space:]]*(=>|:)|[\$A-Za-z_]*$K[[:space:]]*=)[[:space:]]*['\"][^'\"\$<{]{6,}['\"]" "$f" \
     | grep -viE "isi|contoh|ganti|example|xxx|placeholder|rahasia-uji|changeme|\*\*\*|redacted|disamarkan|your_|_here" | grep -q .; then
    lapor "$f" 'nilai kredensial'
  fi
  # NIK (16 digit) atau NIP (18 digit) berdiri sendiri — kecuali kode sumber SIMGOS asli & JS hasil build
  case $f in */paket/core/*|*.js) ;; *)
    grep -qE '(^|[^0-9A-Za-z_.])[0-9]{16}([0-9]{2})?([^0-9A-Za-z_]|$)' "$f" && lapor "$f" 'angka 16/18 digit (NIK/NIP?) — periksa manual' ;;
  esac
  grep -qiE 'jdbc:[a-z]+://[^ ]*password=' "$f" && lapor "$f" 'JDBC berisi password'
done
if (( temuan )); then
  echo "cek-rahasia: $temuan temuan dari ${#files[@]} berkas. Hapus/samarkan dulu, atau bila yakin aman: git commit --no-verify"
  exit 1
fi
echo "cek-rahasia: bersih (${#files[@]} berkas diperiksa)"
