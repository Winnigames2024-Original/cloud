# Генератор картинок Искры — Stable Diffusion XL, локально на GPU. Порт 2447.
# Русский промпт переводится на английский через Qwen (он уже запущен рядом).
import os
import uuid
import requests
import torch
from flask import Flask, request, jsonify
from diffusers import StableDiffusionXLPipeline

OUT = "/var/www/iskra/gen"
os.makedirs(OUT, exist_ok=True)
QWEN = "http://127.0.0.1:2448/generate"

device = "cuda" if torch.cuda.is_available() else "cpu"
dtype = torch.float16 if device == "cuda" else torch.float32

print("Загружаю Stable Diffusion XL — первый раз качается ~7 ГБ...", flush=True)
pipe = StableDiffusionXLPipeline.from_pretrained(
    "stabilityai/stable-diffusion-xl-base-1.0",
    torch_dtype=dtype, use_safetensors=True, variant=("fp16" if device == "cuda" else None),
)
if device == "cuda":
    pipe.enable_model_cpu_offload()   # экономит видеопамять — помещается рядом с Qwen
else:
    pipe = pipe.to(device)
print("Генератор картинок готов.", flush=True)


def to_english(p: str) -> str:
    try:
        r = requests.post(QWEN, timeout=30, json={
            "prompt": "Переведи это описание картинки на английский. Выведи только перевод, без кавычек и пояснений: " + p,
            "system": "Ты переводчик. Отвечай только переводом на английский.",
            "max_tokens": 120,
        })
        t = (r.json().get("text") or "").strip()
        return t or p
    except Exception:
        return p


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
    en = to_english(prompt)
    try:
        img = pipe(
            prompt=en,
            negative_prompt="low quality, blurry, distorted, deformed, ugly, watermark, text",
            num_inference_steps=30, guidance_scale=7.0, height=1024, width=1024,
        ).images[0]
        name = uuid.uuid4().hex + ".png"
        img.save(os.path.join(OUT, name))
        return jsonify(url="/gen/" + name)
    except Exception as e:
        print("ошибка генерации:", e, flush=True)
        return jsonify(err=str(e)), 500


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=2447)
