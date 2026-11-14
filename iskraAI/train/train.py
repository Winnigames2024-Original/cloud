# Обучение Искры. Сохраняет мозг модели в ckpt.pt (это и есть её «память»/база знаний).
# Повторный запуск продолжает обучение с последнего ckpt.pt — так модель дообучается.
import os
import time
import numpy as np
import torch
from model import GPT
from tokenizer import VOCAB_SIZE

# ── гиперпараметры (можно менять) ──
BLOCK = 256       # длина контекста
BATCH = 32        # размер батча
LR = 3e-4
STEPS = int(os.environ.get("ISKRA_STEPS", "5000"))  # шагов за один запуск (autolearn ставит меньше)
EVAL_EVERY = 250  # как часто проверять и сохранять

HERE = os.path.dirname(os.path.abspath(__file__))
CKPT = os.path.join(HERE, "ckpt.pt")
TOKENS = os.path.join(HERE, "tokens.bin")


def get_batch(data, device):
    ix = torch.randint(len(data) - BLOCK - 1, (BATCH,))
    x = torch.stack([torch.from_numpy(data[i:i + BLOCK].astype(np.int64)) for i in ix])
    y = torch.stack([torch.from_numpy(data[i + 1:i + 1 + BLOCK].astype(np.int64)) for i in ix])
    return x.to(device), y.to(device)


def main():
    if not os.path.exists(TOKENS):
        print("Нет tokens.bin — сначала запусти prepare.py")
        return
    device = "cuda" if torch.cuda.is_available() else "cpu"
    print("Устройство:", device)

    data = np.fromfile(TOKENS, dtype=np.uint16)
    n = int(len(data) * 0.9)
    train, val = data[:n], data[n:]
    print(f"Токенов: обучение {len(train):,}, проверка {len(val):,}")

    model = GPT(VOCAB_SIZE, block_size=BLOCK).to(device)
    if os.path.exists(CKPT):
        model.load_state_dict(torch.load(CKPT, map_location=device))
        print("Продолжаю обучение с", CKPT)
    params = sum(p.numel() for p in model.parameters()) / 1e6
    print(f"Параметров: {params:.1f}M")

    opt = torch.optim.AdamW(model.parameters(), lr=LR)
    t0 = time.time()
    model.train()
    for step in range(1, STEPS + 1):
        x, y = get_batch(train, device)
        _, loss = model(x, y)
        opt.zero_grad(set_to_none=True)
        loss.backward()
        opt.step()

        if step == 1 or step % EVAL_EVERY == 0:
            model.eval()
            with torch.no_grad():
                vx, vy = get_batch(val, device)
                _, vl = model(vx, vy)
            model.train()
            dt = time.time() - t0
            print(f"шаг {step}/{STEPS}  loss {loss.item():.3f}  val {vl.item():.3f}  {dt:.0f}с")
            torch.save(model.state_dict(), CKPT)

    torch.save(model.state_dict(), CKPT)
    print("Готово. Мозг сохранён ->", CKPT)


if __name__ == "__main__":
    main()
