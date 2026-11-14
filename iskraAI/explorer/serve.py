# Эксплорер файлов чата. Порт 23456. Адрес: http://СЕРВЕР:23456/<uuid-чата>
import os
import html
from flask import Flask, send_from_directory, abort

BASE = "/var/www/iskra/files"
app = Flask(__name__)


def safe(uuid: str) -> str:
    return "".join(c for c in uuid if c.isalnum() or c in "-_")


PAGE = """<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Файлы чата</title><style>
body{{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0d1117;color:#e6edf3;max-width:720px;margin:36px auto;padding:0 16px}}
h2{{font-weight:600}} a{{color:#f0883e;text-decoration:none}} a:hover{{text-decoration:underline}}
table{{width:100%;border-collapse:collapse;margin-top:14px}}
td{{padding:11px 8px;border-bottom:1px solid #262d36;font-size:15px}}
td.sz{{color:#8b949e;text-align:right;white-space:nowrap}}
.empty{{color:#8b949e;padding:30px 0}}
</style></head><body>
<h2>◆ Файлы чата</h2>
<div style="color:#8b949e;font-size:13px">{uuid}</div>
{body}
</body></html>"""


def human(n):
    for u in ["Б", "КБ", "МБ"]:
        if n < 1024:
            return f"{n:.0f} {u}"
        n /= 1024
    return f"{n:.1f} ГБ"


@app.get("/<uuid>")
@app.get("/<uuid>/")
def listing(uuid):
    u = safe(uuid)
    d = os.path.join(BASE, u)
    if not os.path.isdir(d):
        return PAGE.format(uuid=html.escape(u), body='<div class="empty">Папка пуста или не существует.</div>'), 404
    rows = ""
    for name in sorted(os.listdir(d)):
        p = os.path.join(d, name)
        if os.path.isfile(p):
            rows += (f'<tr><td><a href="/{u}/{html.escape(name)}">{html.escape(name)}</a></td>'
                     f'<td class="sz">{human(os.path.getsize(p))}</td></tr>')
    body = f"<table>{rows}</table>" if rows else '<div class="empty">Пока нет файлов.</div>'
    return PAGE.format(uuid=html.escape(u), body=body)


@app.get("/<uuid>/<path:name>")
def download(uuid, name):
    u = safe(uuid)
    d = os.path.join(BASE, u)
    if "/" in name or ".." in name or not os.path.isfile(os.path.join(d, name)):
        abort(404)
    return send_from_directory(d, name, as_attachment=True)


@app.get("/")
def root():
    return "Эксплорер Искры. Открой /<uuid-чата>.", 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=23456)
