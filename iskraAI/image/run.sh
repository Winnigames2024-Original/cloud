#!/usr/bin/env bash
# Генератор картинок (Kandinsky 2.2) — использует то же venv, что и обучение.
#   bash run.sh setup   — доставить diffusers в общее venv
#   bash run.sh serve   — запустить вручную (в tmux)
#   bash run.sh daemon  — фоновая служба iskra-image
#   bash run.sh stop    — остановить службу
#   bash run.sh logs    — смотреть лог
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/../train/venv"
cd "$HERE"

case "${1:-help}" in
  setup)
    [ -x "$VENV/bin/python" ] || { echo "Сначала собери обучение: ../train/run.sh setup"; exit 1; }
    "$VENV/bin/pip" install diffusers transformers accelerate safetensors
    echo "diffusers установлен."
    ;;
  serve)  "$VENV/bin/python" gen.py ;;
  daemon)
    cat > /etc/systemd/system/iskra-image.service <<EOF
[Unit]
Description=Iskra image generator (Kandinsky 2.2)
After=network-online.target
[Service]
WorkingDirectory=$HERE
ExecStart=$VENV/bin/python $HERE/gen.py
Restart=always
RestartSec=10
[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now iskra-image.service
    echo "Служба генератора картинок запущена. Лог: bash run.sh logs"
    ;;
  stop)   systemctl disable --now iskra-image.service 2>/dev/null || true; echo "Остановлено." ;;
  logs)   journalctl -u iskra-image -n 40 -f ;;
  *) echo "setup | serve | daemon | stop | logs" ;;
esac
