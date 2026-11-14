# Обучение Искры

Своя нейросеть с нуля: байтовый токенизатор, GPT-трансформер, цикл обучения.
Запускать на сервере с GPU (арендованная RTX 3090).

## Порядок

```bash
cd /opt/iskra-src/iskraAI/train

# 1. Окружение (один раз)
bash run.sh setup

# 2. Данные — положи .txt в data/ ЛИБО собери с сайтов:
#    . venv/bin/activate && python scrape.py https://сайт1 https://сайт2

# 3. Токенизация
bash run.sh prepare

# 4. Обучение (в tmux, чтобы не оборвалось!)
tmux new -s train
bash run.sh train          # loss должен падать; ckpt.pt сохраняется сам
# Ctrl+B, затем D — отцепиться. tmux attach -t train — вернуться.

# 5. Сервер ответов (тоже в tmux) — сайт сразу начнёт отвечать живой моделью
tmux new -s serve
bash run.sh serve          # слушает 127.0.0.1:2446
```

## Файлы
- `tokenizer.py` — байтовый токенизатор (UTF-8).
- `model.py` — GPT-трансформер.
- `prepare.py` — data/*.txt -> tokens.bin.
- `train.py` — обучение, сохраняет мозг в `ckpt.pt`.
- `serve.py` — отдаёт ответы на порт 2446 (туда ходит сайт).
- `scrape.py` — сбор текста с сайтов в data/.

## Память модели
`ckpt.pt` — это и есть «мозг» Искры: всё, что она выучила. Скачивай его к себе
после каждого обучения, он не хранится вечно на арендованном сервере:

```bash
scp root@СЕРВЕР:/opt/iskra-src/iskraAI/train/ckpt.pt .
```
