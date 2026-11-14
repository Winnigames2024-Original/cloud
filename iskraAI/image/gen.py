# Генератор картинок Искры — Kandinsky 2.2, локально на GPU. Порт 2447.
# Понимает русские промпты. Картинки сохраняются в /var/www/iskra/gen и отдаются сайтом.
import os
import uuid
import torch
from flask import Flask, request, jsonify
from diffusers import AutoPipelineForText2Image

OUT = "/var/www/iskra/gen"
os.makedirs(OUT, exist_ok=True)

device = "cuda" if torch.cuda.is_available() else "cpu"
dtype = torch.float16 if device == "cuda" else torch.float32
print("Загружаю Kandinsky 2.2 на", device, "— первый раз качается несколько ГБ...", flush=True)
pipe = AutoPipelineForText2Image.from_pretrained(
    "kandinsky-community/kandinsky-2-2-decoder", torch_dtype=dtype
).to(device)
print("Генератор картинок готов.", flush=True)

app = Flask(__name__)


@app.get("/health")
def health():
    return jsonify(ok=True)


@app.post("/generate")
def generate():
    d = request.get_json(force=True) or {}
    prompt = (d.get("prompt") or "").strip()
    if not prompt:
        return jsonify(err="empty"), 400
    try:
        img = pipe(prompt, num_inference_steps=50, height=768, width=768).images[0]
        name = uuid.uuid4().hex + ".png"
        img.save(os.path.join(OUT, name))
        return jsonify(url="/gen/" + name)
    except Exception as e:
        print("ошибка генерации:", e, flush=True)
        return jsonify(err=str(e)), 500


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=2447)
