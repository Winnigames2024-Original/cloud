# Автообучение Искры: сам тянет случайные статьи из Википедии, дообучается по кругу.
# Запускается как фоновая служба (systemd) — работает без участия человека.
import os
import time
import subprocess
import requests

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, "data")
os.makedirs(DATA, exist_ok=True)
CORPUS = os.path.join(DATA, "auto.txt")
PY = os.path.join(HERE, "venv", "bin", "python")
API = "https://ru.wikipedia.org/w/api.php"

ARTICLES_PER_CYCLE = 25
STEPS_PER_CYCLE = "1500"     # сколько шагов обучать за один цикл
PAUSE = 5                    # пауза между циклами, сек
MAX_CORPUS_MB = 500          # не даём корпусу расти бесконечно


def fetch(n):
    # Википедия отдаёт полный текст только по одной статье за запрос,
    # поэтому делаем n отдельных запросов и набираем n полных статей.
    texts = []
    for _ in range(n):
        try:
            r = requests.get(API, timeout=40, headers={"User-Agent": "IskraBot/1.0"}, params={
                "action": "query", "format": "json", "generator": "random",
                "grnnamespace": 0, "grnlimit": 1, "prop": "extracts", "explaintext": 1,
            })
            for p in r.json().get("query", {}).get("pages", {}).values():
                ex = p.get("extract", "")
                if len(ex) > 200:
                    texts.append(ex)
        except Exception as e:
            print("ошибка запроса:", e, flush=True)
        time.sleep(0.3)
    return texts


def trim_corpus():
    if os.path.exists(CORPUS) and os.path.getsize(CORPUS) > MAX_CORPUS_MB * 1024 * 1024:
        with open(CORPUS, "r", encoding="utf-8", errors="ignore") as f:
            data = f.read()
        data = data[len(data) // 2:]            # выкидываем старую половину
        with open(CORPUS, "w", encoding="utf-8") as f:
            f.write(data)
        print("корпус подрезан до половины")


def cycle(i):
    texts = fetch(ARTICLES_PER_CYCLE)
    with open(CORPUS, "a", encoding="utf-8") as f:
        for t in texts:
            f.write(t + "\n\n")
    print(f"[цикл {i}] +{len(texts)} статей, корпус {os.path.getsize(CORPUS)//1024} КБ", flush=True)
    trim_corpus()
    subprocess.run([PY, "prepare.py"], cwd=HERE, check=True)
    env = dict(os.environ, ISKRA_STEPS=STEPS_PER_CYCLE)
    subprocess.run([PY, "train.py"], cwd=HERE, check=True, env=env)


def main():
    print("Автообучение Искры запущено. Источник: Википедия.", flush=True)
    i = 0
    while True:
        i += 1
        try:
            cycle(i)
        except Exception as e:
            print("ошибка цикла:", e, flush=True)
            time.sleep(30)
        time.sleep(PAUSE)


if __name__ == "__main__":
    main()
