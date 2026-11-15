#!/usr/bin/env bash
# Генерация видео (Wan2.1-T2V-1.3B) — использует venv обучения. Порт 2451.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/../train/venv"
cd "$HERE"
case "${1:-help}" in
  setup)
    [ -x "$VENV/bin/python" ] || { echo "Сначала: ../train/run.sh setup"; exit 1; }
    "$VENV/bin/pip" install -U "diffusers>=0.33" ftfy imageio imageio-ffmpeg
    echo "Готово."
    ;;
  serve)  "$VENV/bin/python" serve.py ;;
  daemon)
    cat > /etc/systemd/system/iskra-video.service <<EOF
[Unit]
Description=Iskra Video (Wan2.1, генерация видео)
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
    systemctl enable --now iskra-video.service
    echo "Видео запущено. Лог: bash run.sh logs"
    ;;
  stop)   systemctl disable --now iskra-video.service 2>/dev/null || true; echo "Остановлено." ;;
  logs)   journalctl -u iskra-video -n 40 -f ;;
  *) echo "setup | serve | daemon | stop | logs" ;;
esac
