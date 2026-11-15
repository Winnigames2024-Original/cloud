# Локальная генерация видео для «Искры» — Wan2.1-T2V-1.3B (бесплатно, Apache 2.0).
# 480p, ~5 секунд. Асинхронно: /gen сразу отдаёт job, генерация идёт в фоне,
# статус опрашивается через /status. Модель грузится один раз, веса на CPU и
# стримятся на GPU только во время генерации (enable_model_cpu_offload).
import os, uuid, threading, requests
from flask import Flask, request, jsonify

MODEL = "Wan-AI/Wan2.1-T2V-1.3B-Diffusers"
QWEN  = "http://127.0.0.1:2448/generate"   # тот же умный мозг — для перевода промпта
OUT   = "/var/www/iskra/gen"
os.makedirs(OUT, exist_ok=True)

app = Flask(__name__)
_pipe = None
_jobs = {}                 # job_id -> {state: pending|done|error, url?, err?}
_lock = threading.Lock()   # одна генерация за раз — чтобы не ловить OOM на общей карте


def to_english(p: str) -> str:
    try:
        r = requests.post(QWEN, timeout=30, json={
            "prompt": "Переведи это описание видео на английский. Выведи только перевод, без кавычек и пояснений: " + p,
            "system": "Ты переводчик. Отвечай только переводом на английский.",
            "max_tokens": 120,
        })
        t = (r.json().get("text") or "").strip()
        return t or p
    except Exception:
        return p


def get_pipe():
    global _pipe
    if _pipe is None:
        import torch
        from diffusers import AutoencoderKLWan, WanPipeline
        print("Загружаю Wan2.1-T2V-1.3B...", flush=True)
        vae = AutoencoderKLWan.from_pretrained(MODEL, subfolder="vae", torch_dtype=torch.float32)
        _pipe = WanPipeline.from_pretrained(MODEL, vae=vae, torch_dtype=torch.bfloat16)
        _pipe.enable_model_cpu_offload()
        print("Видео готово.", flush=True)
    return _pipe


def _run(job: str, prompt: str):
    try:
        en = to_english(prompt)
        from diffusers.utils import export_to_video
        with _lock:
            pipe = get_pipe()
            frames = pipe(
                prompt=en,
                negative_prompt="low quality, blurry, distorted, worst quality, static, watermark",
                height=480, width=832, num_frames=81,
                guidance_scale=5.0, num_inference_steps=30,
            ).frames[0]
            name = uuid.uuid4().hex + ".mp4"
            export_to_video(frames, os.path.join(OUT, name), fps=16)
        _jobs[job] = {"state": "done", "url": "/gen/" + name}
    except Exception as e:
        _jobs[job] = {"state": "error", "err": str(e)[:300]}


@app.get("/health")
def health():
    return jsonify(ok=True, loaded=_pipe is not None, jobs=len(_jobs))


@app.post("/gen")
def gen():
    d = request.get_json(force=True) or {}
    prompt = (d.get("prompt") or "").strip()
    if not prompt:
        return jsonify(err="empty"), 400
    job = uuid.uuid4().hex
    _jobs[job] = {"state": "pending"}
    threading.Thread(target=_run, args=(job, prompt), daemon=True).start()
    return jsonify(job=job)


@app.get("/status")
def status():
    job = request.args.get("job", "")
    return jsonify(_jobs.get(job, {"state": "unknown"}))


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=2451, threaded=True)
