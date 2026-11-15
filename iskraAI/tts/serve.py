# Озвучка Искры — Silero TTS (русский), локально на CPU. Порт 2449.
import os
import uuid
import torch
from flask import Flask, request, jsonify

OUT = "/var/www/iskra/gen"
os.makedirs(OUT, exist_ok=True)
torch.set_num_threads(4)

print("Загружаю Silero TTS (русский)...", flush=True)
model, _ = torch.hub.load(repo_or_dir='snakers4/silero-models', model='silero_tts',
                          language='ru', speaker='v4_ru', trust_repo=True)
model.to('cpu')
print("Озвучка готова.", flush=True)

app = Flask(__name__)


@app.get("/health")
def health():
    return jsonify(ok=True)


@app.post("/speak")
def speak():
    d = request.get_json(force=True) or {}
    text = (d.get("text") or "").strip()[:1000]
    speaker = d.get("speaker") or "baya"   # baya, kseniya, xenia, aidar, eugene
    if not text:
        return jsonify(err="empty"), 400
    try:
        out = os.path.join(OUT, uuid.uuid4().hex + ".wav")
        model.save_wav(text=text, speaker=speaker, sample_rate=48000, audio_path=out)
        return jsonify(url="/gen/" + os.path.basename(out))
    except Exception as e:
        print("ошибка озвучки:", e, flush=True)
        return jsonify(err=str(e)), 500


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=2449)
