# shellcheck shell=bash
# Siapkan koneksi MySQL untuk kunjungan-uji.php. Mengisi DSN, DBUSER, DBPASS.
# Urutan: ~/.my.cnf milik pengguna (user/password/host/socket) -> bila tidak ada, tanya akun & password (tersembunyi).
siapkan_koneksi() {
  local akun=${1:-}
  local cnf_user cnf_pass cnf_host cnf_sock
  if [[ -z $akun && -r $HOME/.my.cnf ]]; then
    # Dibaca dengan parser INI PHP: my_print_defaults memotong password yang berisi karakter khusus.
    local f=()
    mapfile -d '' -t f < <(php -r '
      $c = @parse_ini_file(getenv("HOME")."/.my.cnf", true, INI_SCANNER_RAW) ?: [];
      $k = $c["client"] ?? [];
      $v = fn($x) => preg_replace("/^([\"\x27])(.*)\\1$/s", "$2", (string) ($k[$x] ?? ""));
      echo $v("user"), "\0", $v("password"), "\0", $v("host"), "\0", $v("socket"), "\0";')
    cnf_user=${f[0]:-}; cnf_pass=${f[1]:-}; cnf_host=${f[2]:-}; cnf_sock=${f[3]:-}
    if [[ -n $cnf_user && -n $cnf_pass ]]; then
      DBUSER=$cnf_user; DBPASS=$cnf_pass
      if [[ -n $cnf_host && $cnf_host != localhost ]]; then DSN="mysql:host=$cnf_host;charset=utf8mb4"
      else DSN="mysql:unix_socket=${cnf_sock:-/var/lib/mysql/mysql.sock};charset=utf8mb4"; fi
      echo "  koneksi MySQL : ~/.my.cnf (akun $DBUSER)"
      return 0
    fi
  fi
  DBUSER=${akun:-admin}
  [[ -t 0 ]] || { echo 'BERHENTI: butuh terminal untuk password MySQL (atau siapkan ~/.my.cnf)' >&2; exit 1; }
  read -r -s -p "Password MySQL untuk akun '$DBUSER': " DBPASS; echo
  local s; s=$(my_print_defaults client mysql mysqld 2>/dev/null | sed -n 's/^--socket=//p' | tail -1)
  for c in "$s" /var/lib/mysql/mysql.sock /var/run/mysqld/mysqld.sock; do [[ -n $c && -S $c ]] && { DSN="mysql:unix_socket=$c;charset=utf8mb4"; return 0; }; done
  DSN="mysql:host=127.0.0.1;charset=utf8mb4"
}
# JSON aman untuk STDIN php (nilai di-escape oleh php sendiri).
jalankan_php() {   # $1 mode, $2 oleh, $3 berkas hasil, $4 pakai tempat tidur (0/1), $5 set ('' = pasien 1-5, 'status' = 6-10)
  printf '%s\0%s\0%s\0%s\0%s\0%s\0%s\0%s\0' "$1" "$2" "$3" "$DSN" "$DBUSER" "$DBPASS" "${4:-0}" "${5:-}" | php -r '
    [$m,$o,$h,$d,$u,$p,$t,$s] = array_pad(explode("\0", stream_get_contents(STDIN)), 8, "");
    echo json_encode(["mode"=>$m,"oleh"=>$o,"hasil"=>$h,"dsn"=>$d,"user"=>$u,"pass"=>$p,"tempat_tidur"=>$t==="1","set"=>$s,"pasien"=>getenv("KT_PASIEN") ?: "6-10"]);' \
  | php -d display_errors=stderr "$HERE/kunjungan-uji.php"
}
