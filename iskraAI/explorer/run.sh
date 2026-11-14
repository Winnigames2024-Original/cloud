#!/usr/bin/env bash
# Эксплорер файлов чата — порт 23456. Использует venv обучения (там есть flask).
#   bash run.sh daemon  — фоновая служба iskra-explorer
#   bash run.sh stop    — остановить
#   bash run.sh logs    — лог
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/../train/venv"
cd "$HERE"

case "${1:-help}" in
  serve)  "$VENV/bin/python" serve.py ;;
  daemon)
    [ -x "$VENV/bin/python" ] || { echo "Сначала: ../train/run.sh setup"; exit 1; }
    mkdir -p /var/www/iskra/files
    cat > /etc/systemd/system/iskra-explorer.service <<EOF
[Unit]
Description=Iskra file explorer (порт 23456)
After=network-online.target
[Service]
WorkingDirectory=$HERE
ExecStart=$VENV/bin/python $HERE/serve.py
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now iskra-explorer.service
    echo "Эксплорер запущен на порту 23456. Лог: bash run.sh logs"
    ;;
  stop)   systemctl disable --now iskra-explorer.service 2>/dev/null || true; echo "Остановлено." ;;
  logs)   journalctl -u iskra-explorer -n 40 -f ;;
  *) echo "daemon | stop | logs | serve" ;;
esac
