#!/usr/bin/env bash
# Управление обучением Искры.
#   bash run.sh setup     — создать окружение (venv + torch + библиотеки)
#   bash run.sh prepare   — собрать tokens.bin из data/*.txt
#   bash run.sh train     — обучать (запускай в tmux!)
#   bash run.sh serve     — поднять сервер ответов на порту 2446
set -e
cd "$(dirname "$0")"

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
  *) echo "setup | prepare | train | serve" ;;
esac
