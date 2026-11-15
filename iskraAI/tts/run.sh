#!/usr/bin/env bash
# Озвучка (Silero TTS) — использует venv обучения. Порт 2449.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/../train/venv"
cd "$HERE"
case "${1:-help}" in
  setup)
    [ -x "$VENV/bin/python" ] || { echo "Сначала: ../train/run.sh setup"; exit 1; }
    "$VENV/bin/pip" install omegaconf
    echo "Готово."
    ;;
  serve)  "$VENV/bin/python" serve.py ;;
  daemon)
    cat > /etc/systemd/system/iskra-tts.service <<EOF
[Unit]
Description=Iskra TTS (Silero, озвучка)
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
    systemctl enable --now iskra-tts.service
    echo "Озвучка запущена. Лог: bash run.sh logs"
    ;;
  stop)   systemctl disable --now iskra-tts.service 2>/dev/null || true; echo "Остановлено." ;;
  logs)   journalctl -u iskra-tts -n 40 -f ;;
  *) echo "setup | serve | daemon | stop | logs" ;;
esac
