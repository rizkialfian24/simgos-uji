#!/usr/bin/env bash
# Dipanggil oleh setiap skrip yang MENULIS data: berhenti bila server ini tidak ditandai sebagai server dev.
# Tandai server dev (sekali, oleh admin):  echo "dev $(hostname) $(date +%F)" | sudo tee /etc/simgos-dev
# JANGAN pernah membuat /etc/simgos-dev di server produksi.
pastikan_server_dev() {
  if [[ ! -f /etc/simgos-dev ]]; then
    echo "BERHENTI: /etc/simgos-dev tidak ada. Skrip ini membuat data uji dan hanya boleh dijalankan di server DEV." >&2
    echo "          Bila ini memang server dev: echo \"dev \$(hostname) \$(date +%F)\" | sudo tee /etc/simgos-dev" >&2
    exit 1
  fi
}
