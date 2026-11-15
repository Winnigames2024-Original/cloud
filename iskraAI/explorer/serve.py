# Эксплорер файлов чата. Порт 23456. Адрес: http://СЕРВЕР:23456/<uuid-чата>
# Просмотр, скачивание, создание текстовых файлов, загрузка и удаление.
import os
import html
from flask import Flask, send_from_directory, abort, request, redirect

BASE = "/var/www/iskra/files"
app = Flask(__name__)


def safe(uuid: str) -> str:
    return "".join(c for c in uuid if c.isalnum() or c in "-_")


def safe_name(name: str) -> str:
    name = os.path.basename(name or "")
    name = "".join(c for c in name if c.isalnum() or c in "._-")
    if not name or name[0] == ".":
        name = "file.txt"
    return name


PAGE = """<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Файлы чата</title><style>
body{{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0d1117;color:#e6edf3;max-width:720px;margin:36px auto;padding:0 16px}}
h2{{font-weight:600}} a{{color:#f0883e;text-decoration:none}} a:hover{{text-decoration:underline}}
table{{width:100%;border-collapse:collapse;margin-top:14px}}
td{{padding:11px 8px;border-bottom:1px solid #262d36;font-size:15px}}
td.sz{{color:#8b949e;text-align:right;white-space:nowrap}}
.empty{{color:#8b949e;padding:18px 0}}
.box{{background:#161b22;border:1px solid #262d36;border-radius:10px;padding:14px;margin-top:20px}}
.box h3{{margin:0 0 10px;font-size:14px;color:#8b949e;font-weight:600}}
input,textarea{{width:100%;box-sizing:border-box;padding:9px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;font:inherit;margin-bottom:9px}}
textarea{{min-height:120px;font-family:ui-monospace,Consolas,monospace}}
button{{padding:8px 16px;border-radius:8px;border:1px solid #30363d;background:#21262d;color:#e6edf3;cursor:pointer}}
button:hover{{border-color:#f0883e;color:#f0883e}}
.del{{color:#8b949e;font-size:13px}}
</style></head><body>
<h2>◆ Файлы чата</h2>
<div style="color:#8b949e;font-size:13px">{uuid}</div>
{body}
<div class="box"><h3>Создать файл</h3>
<form method="post" action="/{uuid}/create">
<input name="name" placeholder="имя файла, напр. script.py">
<textarea name="content" placeholder="содержимое файла…"></textarea>
<button type="submit">Создать</button></form></div>
<div class="box"><h3>Загрузить файл</h3>
<form method="post" action="/{uuid}/upload" enctype="multipart/form-data">
<input type="file" name="file">
<button type="submit">Загрузить</button></form></div>
</body></html>"""


def human(n):
    for u in ["Б", "КБ", "МБ"]:
        if n < 1024:
            return f"{n:.0f} {u}"
        n /= 1024
    return f"{n:.1f} ГБ"


def render(u):
    d = os.path.join(BASE, u)
    rows = ""
    if os.path.isdir(d):
        for name in sorted(os.listdir(d)):
            p = os.path.join(d, name)
            if os.path.isfile(p):
                en = html.escape(name)
                rows += (f'<tr><td><a href="/{u}/{en}">{en}</a></td>'
                         f'<td class="sz">{human(os.path.getsize(p))}</td>'
                         f'<td class="sz"><form method="post" action="/{u}/delete" style="margin:0" '
                         f'onsubmit="return confirm(\'Удалить {en}?\')">'
                         f'<input type="hidden" name="name" value="{en}">'
                         f'<button class="del" type="submit">×</button></form></td></tr>')
    body = f"<table>{rows}</table>" if rows else '<div class="empty">Пока нет файлов.</div>'
    return PAGE.format(uuid=html.escape(u), body=body)


@app.get("/<uuid>")
@app.get("/<uuid>/")
def listing(uuid):
    return render(safe(uuid))


@app.post("/<uuid>/create")
def create(uuid):
    u = safe(uuid)
    d = os.path.join(BASE, u)
    os.makedirs(d, exist_ok=True)
    name = safe_name(request.form.get("name", ""))
    with open(os.path.join(d, name), "w", encoding="utf-8") as f:
        f.write(request.form.get("content", ""))
    return redirect(f"/{u}")


@app.post("/<uuid>/upload")
def upload(uuid):
    u = safe(uuid)
    d = os.path.join(BASE, u)
    os.makedirs(d, exist_ok=True)
    f = request.files.get("file")
    if f and f.filename:
        f.save(os.path.join(d, safe_name(f.filename)))
    return redirect(f"/{u}")


@app.post("/<uuid>/delete")
def delete(uuid):
    u = safe(uuid)
    name = safe_name(request.form.get("name", ""))
    p = os.path.join(BASE, u, name)
    if os.path.isfile(p):
        os.remove(p)
    return redirect(f"/{u}")


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
