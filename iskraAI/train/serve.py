# Сервер ответов Искры. Поднимает /generate на порту 2446 — туда ходит сайт (MODEL_URL).
import os
import torch
from flask import Flask, request, jsonify
from model import GPT
from tokenizer import VOCAB_SIZE, encode, decode

HERE = os.path.dirname(os.path.abspath(__file__))
CKPT = os.path.join(HERE, "ckpt.pt")
BLOCK = 256

device = "cuda" if torch.cuda.is_available() else "cpu"
model = GPT(VOCAB_SIZE, block_size=BLOCK).to(device)
HAS_MODEL = os.path.exists(CKPT)
if HAS_MODEL:
    model.load_state_dict(torch.load(CKPT, map_location=device))
model.eval()
print("Устройство:", device, "| модель:", "загружена" if HAS_MODEL else "ЕЩЁ НЕ ОБУЧЕНА")

app = Flask(__name__)


@app.get("/health")
def health():
    return jsonify(ok=True, model=HAS_MODEL)


@app.post("/generate")
def generate():
    d = request.get_json(force=True) or {}
    system = (d.get("system") or "").strip()
    prompt = (d.get("prompt") or "").strip()

    if not HAS_MODEL:
        return jsonify(text="Я ещё обучаюсь — мозг пока не готов. Скоро смогу отвечать по-настоящему.")

    head = (system + "\n") if system else ""
    text = head + "Пользователь: " + prompt + "\nИскра: "
    ids = torch.tensor([encode(text)[-BLOCK:]], dtype=torch.long, device=device)
    plen = ids.size(1)
    out = model.generate(ids, max_new_tokens=200, temperature=0.9, top_k=40)[0].tolist()
    gen = decode(out[plen:])
    # обрезаем, если модель начала выдумывать следующую реплику пользователя
    gen = gen.split("Пользователь:")[0].strip()
    return jsonify(text=gen or "…")


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=2446)
