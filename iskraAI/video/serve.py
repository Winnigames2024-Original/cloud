# Локальная генерация видео для «Искры» — Wan2.1-T2V-1.3B (бесплатно, Apache 2.0).
# Короткий клип ~5 сек (81 кадр, 16 fps). Для длинного видео генерим много клипов
# по очереди и склеиваем в один mp4 через ffmpeg. Асинхронно: /gen → job, /status.
import os, uuid, threading, subprocess, requests
from flask import Flask, request, jsonify

MODEL = "Wan-AI/Wan2.1-T2V-1.3B-Diffusers"
QWEN  = "http://127.0.0.1:2448/generate"
OUT   = "/var/www/iskra/gen"
NEG   = "low quality, blurry, distorted, worst quality, static, watermark"
os.makedirs(OUT, exist_ok=True)

app = Flask(__name__)
_pipe = None
_jobs = {}                 # job -> {state, url?, err?, done?, total?}
_lock = threading.Lock()   # одна генерация за раз — на общей карте иначе OOM


def to_english(p: str) -> str:
    try:
        r = requests.post(QWEN, timeout=30, json={
            "prompt": "Переведи это описание видео на английский. Только перевод, без кавычек: " + p,
            "system": "Ты переводчик. Отвечай только переводом на английский.",
            "max_tokens": 120,
        })
        return (r.json().get("text") or "").strip() or p
    except Exception:
        return p


def scene_prompts(theme: str, n: int):
    """n коротких визуальных сцен (на английском) по теме — для длинного видео."""
    if n <= 1:
        return [to_english(theme)]
    try:
        r = requests.post(QWEN, timeout=90, json={
            "prompt": f"Разбей тему на {n} коротких киносцен. Тема: {theme}. "
                      f"Каждая сцена — одна строка на английском: место, действие, камера. "
                      f"Ровно {n} строк, без номеров и лишних слов.",
            "system": "Ты сценарист. Отвечай только списком английских строк, по сцене на строку.",
            "max_tokens": 50 * n,
        })
        t = (r.json().get("text") or "").strip()
        lines = [l.strip(" -*0123456789.\t") for l in t.splitlines() if l.strip()]
        lines = [l for l in lines if len(l) > 3]
        if lines:
            while len(lines) < n:
                lines.append(lines[len(lines) % len(lines)])
            return lines[:n]
    except Exception:
        pass
    return [to_english(theme)] * n


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


def _one_clip(pipe, en: str, path: str):
    from diffusers.utils import export_to_video
    frames = pipe(prompt=en, negative_prompt=NEG, height=480, width=832,
                  num_frames=81, guidance_scale=5.0, num_inference_steps=25).frames[0]
    export_to_video(frames, path, fps=16)


def _run(job: str, prompt: str, seconds: int):
    try:
        import imageio_ffmpeg
        clips = max(1, min(60, round(seconds / 5)))   # 5 сек на клип, максимум 60 (5 минут)
        _jobs[job] = {"state": "pending", "done": 0, "total": clips}
        with _lock:
            pipe = get_pipe()
            scenes = scene_prompts(prompt, min(clips, 12))
            parts = []
            for i in range(clips):
                p = os.path.join(OUT, f"_part_{job}_{i}.mp4")
                _one_clip(pipe, scenes[i % len(scenes)], p)
                parts.append(p)
                _jobs[job] = {"state": "pending", "done": i + 1, "total": clips}
            name = uuid.uuid4().hex + ".mp4"
            final = os.path.join(OUT, name)
            if len(parts) == 1:
                os.replace(parts[0], final)
            else:
                listf = os.path.join(OUT, f"_list_{job}.txt")
                with open(listf, "w") as f:
                    for p in parts:
                        f.write("file '%s'\n" % p)
                ff = imageio_ffmpeg.get_ffmpeg_exe()
                subprocess.run([ff, "-y", "-f", "concat", "-safe", "0", "-i", listf,
                                "-c:v", "libx264", "-pix_fmt", "yuv420p", final],
                               check=True, capture_output=True)
                for p in parts + [listf]:
                    try: os.remove(p)
                    except Exception: pass
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
    try:
        seconds = int(d.get("seconds") or 5)
    except Exception:
        seconds = 5
    seconds = max(5, min(300, seconds))
    job = uuid.uuid4().hex
    _jobs[job] = {"state": "pending", "done": 0, "total": max(1, min(60, round(seconds / 5)))}
    threading.Thread(target=_run, args=(job, prompt, seconds), daemon=True).start()
    return jsonify(job=job)


@app.get("/status")
def status():
    return jsonify(_jobs.get(request.args.get("job", ""), {"state": "unknown"}))


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=2451, threaded=True)
