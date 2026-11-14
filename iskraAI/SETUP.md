# Искра — как всё подключить

Всё живёт на одном сервере (арендованная GPU-машина, 185.182.108.16).
Папка проекта на сервере: `/opt/iskra-src/iskraAI`.

---

## 0. База: сайт и автообновление (один раз)

```bash
curl -fsSL https://raw.githubusercontent.com/Winnigames2024-Original/cloud/main/iskraAI/iskra.sh -o /usr/local/bin/iskra.sh
bash /usr/local/bin/iskra.sh install
```

Поднимает сайт, базу, HTTPS и таймер: **каждые 5 минут сайт сам обновляется** из репозитория.
Проверка: `systemctl status iskra-update.timer` → `active (waiting)`.

Как подключать ключи ниже: все пишутся в `/var/www/iskra/inc/config.php`.
Этот файл автообновление НЕ трогает — ключи в безопасности.

---

## 1. Обучение и мозг (своя модель)

```bash
cd /opt/iskra-src/iskraAI/train
bash run.sh setup       # окружение (один раз)
bash run.sh daemon      # фоновые службы: обучение + мозг
bash run.sh logs        # смотреть
```

Модель учится сама на Википедии. Свои тексты — положи `.txt` в `train/data/`, потом
`systemctl restart iskra-learn`.

> Важно: своя модель с нуля — маленькая, тексты пишет плохо. Для НОРМАЛЬНОГО
> текста подключи Gemini (пункт 4) — тогда чат и посты пишет он, а не она.

---

## 2. Генерация картинок (локально, Kandinsky 2.2)

```bash
cd /opt/iskra-src/iskraAI/image
bash run.sh setup       # доставит diffusers (один раз)
bash run.sh daemon      # фоновая служба генератора
```

Адрес генератора в конфиг (один раз):
```bash
C=/var/www/iskra/inc/config.php
grep -q IMAGE_URL $C || echo "define('IMAGE_URL', 'http://127.0.0.1:2447');" >> $C
systemctl reload php*-fpm
```

Использование: в чате «нарисуй кота в космосе».

---

## 3. Поиск в интернете (Google Custom Search)

Где взять: ключ на https://console.cloud.google.com/apis/credentials (тип «Ключ API»),
включить API на https://console.cloud.google.com/apis/library/customsearch.googleapis.com,
ID поисковика на https://programmablesearchengine.google.com/ .

```bash
C=/var/www/iskra/inc/config.php
sed -i "s|define('GOOGLE_CSE_CX'.*|define('GOOGLE_CSE_CX', 'ТВОЙ_CX');|" $C || echo "define('GOOGLE_CSE_CX', 'ТВОЙ_CX');" >> $C
sed -i "s|define('GOOGLE_API_KEY'.*|define('GOOGLE_API_KEY', 'ТВОЙ_КЛЮЧ');|" $C || echo "define('GOOGLE_API_KEY', 'ТВОЙ_КЛЮЧ');" >> $C
systemctl reload php*-fpm
```

Использование: вопрос в чате («какая столица Франции?»).

---

## 4. Умные тексты и посты (Qwen локально)

Умный мозг на твоей 3090 — пишет нормальные тексты в чат и посты. Без внешних сервисов.

```bash
cd /opt/iskra-src/iskraAI/llm
bash run.sh setup       # доставит transformers (один раз)
bash run.sh daemon      # фоновая служба умного мозга (порт 2448)
bash run.sh logs        # первый запуск качает ~6 ГБ — жди "Умный мозг готов"
```

Адрес в конфиг (один раз):
```bash
C=/var/www/iskra/inc/config.php
grep -q QWEN_URL $C || echo "define('QWEN_URL', 'http://127.0.0.1:2448');" >> $C
systemctl reload php*-fpm
```

Как только служба поднялась — чат и посты пишет Qwen (умно), а не модель-малыш.
Своя модель с нуля при этом продолжает учиться отдельно.

> Память: Qwen 3B + картинки + обучение помещаются в 24 ГБ. Если захочешь Qwen покрупнее
> (7B — умнее), поменяй MODEL в `llm/serve.py` на `Qwen/Qwen2.5-7B-Instruct`, но тогда,
> возможно, придётся остановить генератор картинок, чтобы хватило видеопамяти.

### (необязательно) Gemini вместо Qwen
Если захочешь облачный мозг вместо локального — ключ на https://aistudio.google.com/apikey :
```bash
C=/var/www/iskra/inc/config.php
grep -q GEMINI_KEY $C && sed -i "s|define('GEMINI_KEY'.*|define('GEMINI_KEY', 'КЛЮЧ');|" $C || echo "define('GEMINI_KEY', 'КЛЮЧ');" >> $C
systemctl reload php*-fpm
```
Если задан и Gemini, и Qwen — приоритет у Gemini.

---

## 5. Постинг и бот в ВК

Токен сообщества: в группе `Управление → Работа с API → Создать ключ`,
права: **Сообщения, Стена, Фотографии, Управление сообществом**.
ID группы: число из `vk.com/clubХХХХХ`.

```bash
C=/var/www/iskra/inc/config.php
grep -q VK_TOKEN $C || echo "define('VK_TOKEN', 'ТОКЕН');"   >> $C
grep -q VK_GROUP $C || echo "define('VK_GROUP', 'ID_ГРУППЫ');" >> $C
grep -q OWNER_EMAIL $C || echo "define('OWNER_EMAIL', 'твой@email');" >> $C
# если строки уже есть — заменить:
sed -i "s|define('VK_TOKEN'.*|define('VK_TOKEN', 'ТОКЕН');|" $C
sed -i "s|define('VK_GROUP'.*|define('VK_GROUP', 'ID_ГРУППЫ');|" $C
sed -i "s|define('OWNER_EMAIL'.*|define('OWNER_EMAIL', 'твой@email');|" $C
systemctl reload php*-fpm
```

`OWNER_EMAIL` — чтобы постить в ВК мог только ты, а не любой посетитель сайта.

Использование: «нарисуй кота и выложи в вк» → Искра рисует картинку,
Gemini пишет подпись, пост уходит на стену группы.

---

## Управление и деньги

- Остановить всё (и перестать платить за GPU): `cd train && bash run.sh stop`,
  `cd image && bash run.sh stop`, затем выключить сервер в кабинете Intelion.
- Запустить снова: `bash run.sh daemon` в обеих папках.
- Пока службы работают — ~61 ₽/час. Не нужно — выключай.
- Мозг модели: `train/ckpt.pt` — скачивай к себе, чтобы не потерять при выключении.
