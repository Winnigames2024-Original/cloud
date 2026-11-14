# Сбор текста с сайтов в data/. Использование:
#   python scrape.py https://сайт1 https://сайт2 ...
import sys
import os
import re
import requests
from bs4 import BeautifulSoup

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "data")
os.makedirs(OUT, exist_ok=True)


def clean(html):
    soup = BeautifulSoup(html, "html.parser")
    for t in soup(["script", "style", "nav", "footer", "header", "noscript"]):
        t.decompose()
    text = soup.get_text("\n")
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def main(urls):
    for i, u in enumerate(urls):
        try:
            r = requests.get(u, timeout=25, headers={"User-Agent": "IskraBot/1.0"})
            r.encoding = r.apparent_encoding
            txt = clean(r.text)
            path = os.path.join(OUT, f"web_{i:03d}.txt")
            with open(path, "w", encoding="utf-8") as f:
                f.write(txt)
            print(f"ок  {u}  -> {len(txt):,} символов")
        except Exception as e:
            print("пропуск", u, e)


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Использование: python scrape.py URL1 URL2 ...")
        sys.exit(1)
    main(sys.argv[1:])
