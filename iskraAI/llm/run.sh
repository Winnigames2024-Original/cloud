#!/usr/bin/env bash
# Умный мозг (Qwen2.5) — использует то же venv, что и обучение.
#   bash run.sh setup   — доставить transformers/accelerate в общее venv
#   bash run.sh serve   — запустить вручную (в tmux)
#   bash run.sh daemon  — фоновая служба iskra-llm
#   bash run.sh stop    — остановить
#   bash run.sh logs    — смотреть лог
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/../train/venv"
cd "$HERE"

case "${1:-help}" in
  setup)
    [ -x "$VENV/bin/python" ] || { echo "Сначала: ../train/run.sh setup"; exit 1; }
    "$VENV/bin/pip" install "transformers>=4.45" accelerate safetensors sentencepiece
    echo "Готово."
    ;;
  serve)  "$VENV/bin/python" serve.py ;;
  daemon)
    cat > /etc/systemd/system/iskra-llm.service <<EOF
[Unit]
Description=Iskra smart brain (Qwen2.5)
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
    systemctl enable --now iskra-llm.service
    echo "Умный мозг запущен. Лог: bash run.sh logs"
    ;;
  stop)   systemctl disable --now iskra-llm.service 2>/dev/null || true; echo "Остановлено." ;;
  logs)   journalctl -u iskra-llm -n 40 -f ;;
  *) echo "setup | serve | daemon | stop | logs" ;;
esac
