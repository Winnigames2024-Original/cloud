# Умный мозг Искры — Qwen2.5 локально на GPU. Порт 2448.
# Пишет тексты в чат и посты. По-русски. Своя модель с нуля остаётся отдельно.
import torch
from flask import Flask, request, jsonify
from transformers import AutoModelForCausalLM, AutoTokenizer

MODEL = "Qwen/Qwen2.5-3B-Instruct"   # влезает рядом с обучением и картинками
device = "cuda" if torch.cuda.is_available() else "cpu"
dtype = torch.float16 if device == "cuda" else torch.float32

print("Загружаю", MODEL, "на", device, "— первый раз качается ~6 ГБ...", flush=True)
tok = AutoTokenizer.from_pretrained(MODEL)
model = AutoModelForCausalLM.from_pretrained(MODEL, torch_dtype=dtype).to(device)
model.eval()
print("Умный мозг готов.", flush=True)

app = Flask(__name__)


@app.get("/health")
def health():
    return jsonify(ok=True)


@app.post("/generate")
def generate():
    d = request.get_json(force=True) or {}
    system = (d.get("system") or "Ты — Искра, дружелюбный помощник. Отвечай по-русски.").strip()
    prompt = (d.get("prompt") or "").strip()
    msgs = [{"role": "system", "content": system}, {"role": "user", "content": prompt}]
    text = tok.apply_chat_template(msgs, tokenize=False, add_generation_prompt=True)
    inputs = tok(text, return_tensors="pt").to(model.device)
    with torch.no_grad():
        out = model.generate(**inputs, max_new_tokens=512, do_sample=True,
                             temperature=0.7, top_p=0.9, pad_token_id=tok.eos_token_id)
    gen = tok.decode(out[0][inputs.input_ids.shape[1]:], skip_special_tokens=True)
    return jsonify(text=gen.strip() or "…")


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=2448)
