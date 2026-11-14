#!/usr/bin/env bash
# Управление Искрой.
#   bash run.sh setup    — окружение (venv + torch + библиотеки)
#   bash run.sh prepare  — собрать tokens.bin из data/*.txt
#   bash run.sh train    — обучать один раз вручную (в tmux)
#   bash run.sh serve    — сервер ответов на 2446 вручную (в tmux)
#   bash run.sh daemon   — поставить ФОНОВЫЕ службы: мозг + автообучение
#   bash run.sh stop     — остановить фоновые службы
#   bash run.sh logs     — смотреть, что происходит
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

case "${1:-help}" in
  setup)
    python3 -m venv venv
    . venv/bin/activate
    pip install -U pip
    pip install torch --index-url https://download.pytorch.org/whl/cu128
    pip install -r requirements.txt
    echo "Окружение готово."
    ;;
  prepare) . venv/bin/activate; python prepare.py ;;
  train)   . venv/bin/activate; python train.py ;;
  serve)   . venv/bin/activate; python serve.py ;;

  daemon)
    [ -x "$HERE/venv/bin/python" ] || { echo "Сначала: bash run.sh setup"; exit 1; }

    cat > /etc/systemd/system/iskra-serve.service <<EOF
[Unit]
Description=Iskra brain (ответы на 2446)
After=network-online.target
[Service]
WorkingDirectory=$HERE
ExecStart=$HERE/venv/bin/python $HERE/serve.py
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

    cat > /etc/systemd/system/iskra-learn.service <<EOF
[Unit]
Description=Iskra autolearn (сам учится на Википедии)
After=network-online.target
[Service]
WorkingDirectory=$HERE
ExecStart=$HERE/venv/bin/python $HERE/autolearn.py
Restart=always
RestartSec=15
[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable --now iskra-serve.service iskra-learn.service
    echo "Фоновые службы запущены: мозг и автообучение работают сами."
    echo "Смотреть: bash run.sh logs"
    ;;

  stop)
    systemctl disable --now iskra-learn.service iskra-serve.service 2>/dev/null || true
    echo "Остановлено."
    ;;

  logs)
    journalctl -u iskra-learn -u iskra-serve -n 40 -f
    ;;

  *) echo "setup | prepare | train | serve | daemon | stop | logs" ;;
esac
