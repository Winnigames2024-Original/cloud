# Токенизатор Искры — байтовый (UTF-8), с нуля, без сторонних библиотек.
# Каждый байт текста = один токен. Русский, эмодзи, любой язык — работает как есть.
EOT = 256                 # спец-токен «конец текста»
VOCAB_SIZE = 257          # 256 байт + EOT

def encode(s: str):
    return list(s.encode("utf-8"))

def decode(ids):
    b = bytes([i for i in ids if 0 <= i < 256])
    return b.decode("utf-8", errors="replace")
