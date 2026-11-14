# Готовит данные для обучения: читает все .txt из data/, превращает в tokens.bin
import os
import sys
import numpy as np
from tokenizer import encode, EOT

HERE = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.join(HERE, "data")
OUT = os.path.join(HERE, "tokens.bin")


def main():
    files = [f for f in sorted(os.listdir(DATA_DIR)) if f.endswith(".txt")]
    if not files:
        print("В", DATA_DIR, "нет .txt файлов.")
        print("Положи туда тексты (или запусти scrape.py для сбора с сайтов), потом повтори.")
        sys.exit(1)

    ids = []
    total_chars = 0
    for f in files:
        with open(os.path.join(DATA_DIR, f), encoding="utf-8", errors="ignore") as fh:
            text = fh.read()
        total_chars += len(text)
        ids.extend(encode(text))
        ids.append(EOT)
        print(f"  + {f}: {len(text):,} символов")

    arr = np.array(ids, dtype=np.uint16)
    arr.tofile(OUT)
    print(f"Итого: {total_chars:,} символов -> {len(arr):,} токенов -> {OUT}")


if __name__ == "__main__":
    main()
