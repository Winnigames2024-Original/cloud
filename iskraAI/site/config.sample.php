<?php
// Образец конфига. iskra.sh при установке создаёт настоящий inc/config.php
// с паролем БД. Этот файл — просто шаблон, в работе не используется.
define('SITE_NAME', 'Искра');
define('SITE_URL',  'https://iskra.winnigames2024.ru');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'iskra');
define('DB_USER', 'iskra');
define('DB_PASS', 'СГЕНЕРИРУЕТСЯ_ПРИ_УСТАНОВКЕ');
define('SMTPBZ_KEY', '');
define('MAIL_FROM', 'noreply@winnigames2024.ru');
define('MAIL_NAME', 'Искра');
define('MODEL_URL', 'http://127.0.0.1:2446');
define('GOOGLE_CSE_CX', '');   // ID поисковика Programmable Search
define('GOOGLE_API_KEY', '');  // ключ API вида AIza...
define('IMAGE_URL', 'http://127.0.0.1:2447');  // локальный генератор картинок
define('QWEN_URL', 'http://127.0.0.1:2448');  // локальный умный мозг (Qwen) — пишет тексты и посты
define('GEMINI_KEY', '');   // (необязательно) Gemini вместо Qwen, AIza... с aistudio.google.com
define('VK_TOKEN', '');      // токен сообщества ВК
define('VK_GROUP', '');      // ID группы ВК (число)
define('OWNER_EMAIL', '');   // чей аккаунт может постить в ВК (твой e-mail)
