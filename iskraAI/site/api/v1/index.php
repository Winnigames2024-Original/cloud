<?php
require_once __DIR__ . '/../../inc/auth.php';
header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['err' => 'auth']); exit; }

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function build_system(array $s): string {
    $base = setting_get('system_prompt', '');
    $t = ($base !== '' ? $base . ' ' : '') . "Тебя зовут Искра. Тебя создал Winnigames2024. Ты отвечаешь по-русски.";
    $mode = $s['mode'] ?? 'auto';
    if ($mode === 'code')      $t .= " Ты опытный программист: давай рабочий код и краткие пояснения.";
    elseif ($mode === 'fast')  $t .= " Отвечай максимально кратко и по делу.";
    if (!empty($s['persona'])) $t .= " Твой характер: " . $s['persona'] . ".";
    $t .= empty($s['allow_anger'])
        ? " Оставайся спокойной и доброжелательной."
        : " Тебе разрешена грубая, резкая и неформальная речь, в том числе крепкие словечки, если собеседник не против.";
    return $t;
}

// Надёжный ответ на вопросы о личности — не зависит от модели.
function identity_answer(string $q): ?string {
    if (preg_match('~(кто\s+ты\b|ты\s+кто\b|кто\s+тебя\s+созда|кто\s+твой\s+созда|кто\s+тебя\s+сдела|как\s+тебя\s+зовут|что\s+ты\s+такое|твой\s+создатель)~ui', $q)) {
        return "Я — Искра, искусственный интеллект. Меня создал Winnigames2024.";
    }
    return null;
}

// Ответ только от своей модели с нуля (режим «Искра своя»).
function own_reply(string $prompt, string $system, int $max = 300): string {
    $ch = curl_init(MODEL_URL . '/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'system' => $system, 'max_tokens' => $max], JSON_UNESCAPED_UNICODE),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (isset($d['text'])) return $d['text']; }
    return "Я ещё обучаюсь и пока отвечаю плохо — я твоя модель с нуля.";
}

// Поиск в интернете: Искра сама лезет в Википедию за значением слова / фактом.
function kb_fetch_json(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['User-Agent: IskraBot/1.0'],
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    return json_decode($r, true);
}

function kb_lookup(string $q): ?string {
    if (!preg_match('~(?:что\s+такое|что\s+значит|кто\s+так(?:ой|ая|ое)|определение|значение\s+слова)\s+(.+)~ui', $q, $m)) {
        return null;
    }
    $term = trim($m[1], " \t\n?.!,\"'«»");
    if (mb_strlen($term) < 2) return null;

    $os = kb_fetch_json('https://ru.wikipedia.org/w/api.php?action=opensearch&limit=1&format=json&search=' . rawurlencode($term));
    $title = $os[1][0] ?? null;
    if (!$title) return null;

    $ex = kb_fetch_json('https://ru.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&redirects=1&format=json&titles=' . rawurlencode($title));
    foreach (($ex['query']['pages'] ?? []) as $p) {
        $text = trim($p['extract'] ?? '');
        if ($text !== '') {
            if (mb_strlen($text) > 600) $text = mb_substr($text, 0, 600) . '…';
            return $text . "\n\n(нашла в Википедии)";
        }
    }
    return null;
}

// Распознаёт просьбу нарисовать и достаёт промпт после глагола.
function draw_prompt(string $q): ?string {
    // явные «нарисуй …» / «draw …»
    if (preg_match('~^\s*(?:нарисуй|нарисовать|draw)\s+(.+)~ui', $q, $m)) {
        $p = trim($m[1], " \t\n:,.\"'«»"); return mb_strlen($p) >= 2 ? $p : null;
    }
    // «сделай/создай/сгенерируй картинку|фото|… [про] …»
    if (preg_match('~(?:сделай|сделать|создай|сгенерируй|сгенерировать|generate)\s+(?:мне\s+)?(?:картинк\w+|изображени\w+|рисунок|фото|арт|пикч\w+)\s*(?:с\s+|про\s+|где\s+)?(.*)~ui', $q, $m)) {
        $p = trim($m[1], " \t\n:,.\"'«»"); return $p !== '' ? $p : 'красивая картинка';
    }
    return null;
}

// Создание файла по запросу: «создай файл …», «сохрани в файл …»
function file_request(string $q): ?array {
    if (!preg_match('~(созда|сдела|сохран|запиш|сгенерируй)\w*\s+файл~ui', $q)) return null;
    $name = 'file.txt';
    if (preg_match('~([\w\-]+\.[A-Za-z0-9]{1,6})~u', $q, $m)) $name = $m[1];
    return ['name' => $name];
}

function log_action(PDO $db, int $uid, string $chat, string $kind, string $detail): void {
    $db->prepare('INSERT INTO actions (user_id, chat_id, kind, detail) VALUES (?,?,?,?)')
       ->execute([$uid, $chat, $kind, mb_substr($detail, 0, 500)]);
}

function chat_history(PDO $db, int $uid, string $chat, int $beforeId, int $limit = 8): string {
    $st = $db->prepare('SELECT role, content FROM messages WHERE user_id=? AND chat_id=? AND id<? ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $uid, PDO::PARAM_INT);
    $st->bindValue(2, $chat);
    $st->bindValue(3, $beforeId, PDO::PARAM_INT);
    $st->bindValue(4, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = array_reverse($st->fetchAll());
    $lines = [];
    foreach ($rows as $r) {
        $who = ($r['role'] === 'ai') ? 'Искра' : 'Пользователь';
        $c = preg_replace('~\[(img|video|audio|file)\]\S*~', '[вложение]', (string)$r['content']);
        $c = mb_substr(trim($c), 0, 400);
        if ($c !== '') $lines[] = $who . ': ' . $c;
    }
    return implode("\n", $lines);
}

function own_chat(PDO $db, int $uid, string $chat): bool {
    $st = $db->prepare('SELECT 1 FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$chat, $uid]);
    return (bool)$st->fetch();
}

function del_file_request(string $q): ?string {
    if (preg_match('~удали\w*\s+файл\s+([\w\-.]+)~ui', $q, $m)) return $m[1];
    return null;
}

function make_file(string $chat, string $name, string $content): ?string {
    $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
    $dir = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . '/files/' . $cid;
    @mkdir($dir, 0775, true);
    $name = preg_replace('~[^A-Za-z0-9._\-]~', '_', $name);
    if ($name === '' || $name[0] === '.') $name = 'file.txt';
    if (file_put_contents($dir . '/' . $name, $content) === false) return null;
    return $name;
}

function tts_speak(string $text): ?string {
    if (!defined('TTS_URL') || TTS_URL === '') return null;
    $ch = curl_init(TTS_URL . '/speak');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (!empty($d['url'])) return $d['url']; }
    return null;
}

function video_submit(string $prompt, int $seconds = 5): ?string {
    if (!defined('VIDEO_URL') || VIDEO_URL === '') return null;
    $ch = curl_init(VIDEO_URL . '/gen');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'seconds' => $seconds], JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (!empty($d['job'])) return $d['job']; }
    return null;
}

function video_status_fetch(string $job): array {
    if (!defined('VIDEO_URL') || VIDEO_URL === '') return ['state' => 'unknown'];
    $ch = curl_init(VIDEO_URL . '/status?job=' . rawurlencode($job));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $r = curl_exec($ch); curl_close($ch);
    $d = json_decode($r ?: '', true);
    return is_array($d) ? $d : ['state' => 'unknown'];
}

function img_edit(string $path, string $prompt): ?string {
    if (!defined('IMAGE_URL') || IMAGE_URL === '') return null;
    $ch = curl_init(IMAGE_URL . '/edit');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['path' => $path, 'prompt' => $prompt], JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (!empty($d['url'])) return $d['url']; }
    return null;
}

function gen_image(string $prompt): ?string {
    if (!defined('IMAGE_URL') || IMAGE_URL === '') return null;
    $ch = curl_init(IMAGE_URL . '/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (!empty($d['url'])) return $d['url']; }
    return null;
}

function looks_like_question(string $q): bool {
    if (mb_strpos($q, '?') !== false) return true;
    return (bool)preg_match('~^\s*(как|почему|что|кто|где|когда|зачем|сколько|какой|какая|какие|можно\s+ли)\b~ui', $q);
}

// Поиск по всему интернету через официальный Google Custom Search API.
function web_search(string $q): ?string {
    if (!defined('GOOGLE_API_KEY') || GOOGLE_API_KEY === '' ||
        !defined('GOOGLE_CSE_CX')  || GOOGLE_CSE_CX  === '') return null;
    $url = 'https://www.googleapis.com/customsearch/v1?hl=ru&num=3'
         . '&key=' . rawurlencode(GOOGLE_API_KEY)
         . '&cx='  . rawurlencode(GOOGLE_CSE_CX)
         . '&q='   . rawurlencode($q);
    $d = kb_fetch_json($url);
    $items = $d['items'] ?? [];
    if (!$items) return null;
    $out = "Вот что нашлось в интернете:\n\n";
    foreach (array_slice($items, 0, 3) as $it) {
        $out .= "• " . ($it['title'] ?? '') . "\n"
             .  ($it['snippet'] ?? '') . "\n"
             .  ($it['link'] ?? '') . "\n\n";
    }
    return trim($out);
}

// Умный текст через Gemini (официальный Google AI). Пишет посты и ответы.
function gemini_reply(string $prompt, string $system): ?string {
    if (!defined('GEMINI_KEY') || GEMINI_KEY === '') return null;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode(GEMINI_KEY);
    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) { error_log("gemini $code: $r"); return null; }
    $d = json_decode($r, true);
    return $d['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

function vk_request(string $q): bool {
    return (bool)preg_match('~\b(вк|вконтакте|vk)\b~ui', $q)
        && (bool)preg_match('~(выложи|запости|опубликуй|постни|пост)~ui', $q);
}

function vk_call(string $method, array $params) {
    $params['access_token'] = VK_TOKEN;
    $params['v'] = '5.199';
    $ch = curl_init('https://api.vk.com/method/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query($params)]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true);
}

function vk_post(string $text, ?string $imagePath): ?string {
    if (!defined('VK_TOKEN') || VK_TOKEN === '' || !defined('VK_GROUP') || VK_GROUP === '') return null;
    $gid = (int)VK_GROUP;
    $attach = '';
    if ($imagePath && is_file($imagePath)) {
        $up = vk_call('photos.getWallUploadServer', ['group_id' => $gid]);
        $upurl = $up['response']['upload_url'] ?? null;
        if ($upurl) {
            $ch = curl_init($upurl);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => ['photo' => new CURLFile($imagePath, 'image/png', 'img.png')]]);
            $res = json_decode(curl_exec($ch), true); curl_close($ch);
            if (!empty($res['photo'])) {
                $save = vk_call('photos.saveWallPhoto', ['group_id' => $gid,
                    'server' => $res['server'], 'photo' => $res['photo'], 'hash' => $res['hash']]);
                $p = $save['response'][0] ?? null;
                if ($p) $attach = 'photo' . $p['owner_id'] . '_' . $p['id'];
            }
        }
    }
    $post = vk_call('wall.post', ['owner_id' => -$gid, 'from_group' => 1,
        'message' => $text, 'attachments' => $attach]);
    $pid = $post['response']['post_id'] ?? null;
    return $pid ? ('https://vk.com/wall-' . $gid . '_' . $pid) : null;
}

// Умный мозг Qwen локально.
function qwen_reply(string $prompt, string $system, int $max = 512): ?string {
    if (!defined('QWEN_URL') || QWEN_URL === '') return null;
    $ch = curl_init(QWEN_URL . '/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'system' => $system, 'max_tokens' => $max], JSON_UNESCAPED_UNICODE),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (!empty($d['text'])) return $d['text']; }
    return null;
}

function ai_reply(string $prompt, string $system, int $max = 512): string {
    $g = gemini_reply($prompt, $system);
    if ($g !== null) return $g;
    $q = qwen_reply($prompt, $system, $max);
    if ($q !== null) return $q;
    $ch = curl_init(MODEL_URL . '/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'system' => $system], JSON_UNESCAPED_UNICODE),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) { $d = json_decode($r, true); if (isset($d['text'])) return $d['text']; }
    return "Я ещё обучаюсь и пока отвечаю заглушкой — но настройки характера для этого чата уже сохранены "
         . "и применятся, как только подключим мою модель.";
}

$db = db();
$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($action) {

  case 'chats':
    $st = $db->prepare('SELECT chat_id, title FROM chats WHERE user_id=? ORDER BY updated_at DESC LIMIT 200');
    $st->execute([$me['id']]);
    j(['chats' => $st->fetchAll()]);

  case 'upload':
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { http_response_code(400); j(['err' => 'upload']); }
    $f = $_FILES['file'];
    if ($f['size'] > 8 * 1024 * 1024) { http_response_code(400); j(['err' => 'Файл больше 8 МБ']); }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $okImg = ['jpg','jpeg','png','gif','webp'];
    $okDoc = ['txt','md','csv','log','json','pdf'];
    if (!in_array($ext, array_merge($okImg, $okDoc), true)) { http_response_code(400); j(['err' => 'Такой тип файла нельзя']); }
    $dir = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . '/uploads/u' . $me['id'];
    @mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { http_response_code(500); j(['err' => 'save']); }
    j(['url' => '/uploads/u' . $me['id'] . '/' . $name, 'name' => $f['name'], 'img' => in_array($ext, $okImg, true)]);

  case 'visibility':
    $vis = ($in['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
    $db->prepare('UPDATE chats SET visibility=? WHERE chat_id=? AND user_id=?')
       ->execute([$vis, $in['chat'] ?? '', $me['id']]);
    j(['ok' => true, 'visibility' => $vis]);

  case 'vis_get':
    $st = $db->prepare('SELECT visibility FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$_GET['chat'] ?? '', $me['id']]);
    j(['visibility' => ($st->fetch()['visibility'] ?? 'private')]);

  case 'history':
    $st = $db->prepare('SELECT id, role, content FROM messages WHERE user_id=? AND chat_id=? ORDER BY id');
    $st->execute([$me['id'], $_GET['chat'] ?? '']);
    j(['messages' => $st->fetchAll()]);

  case 'actions':
    $st = $db->prepare('SELECT kind, detail, created_at FROM actions WHERE user_id=? AND chat_id=? ORDER BY id DESC LIMIT 100');
    $st->execute([$me['id'], $_GET['chat'] ?? '']);
    j(['actions' => $st->fetchAll()]);

  case 'files_list':
    $chat = $_GET['chat'] ?? '';
    if (!own_chat($db, $me['id'], $chat)) j(['files' => []]);
    $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
    $dir = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . '/files/' . $cid;
    $out = [];
    if (is_dir($dir)) foreach (scandir($dir) as $f) {
        if ($f[0] === '.') continue;
        $p = "$dir/$f";
        if (is_file($p)) $out[] = ['name' => $f, 'size' => filesize($p),
            'img' => (bool)preg_match('~\.(png|jpe?g|gif|webp)$~i', $f), 'url' => '/files/' . $cid . '/' . $f];
    }
    j(['files' => $out]);

  case 'file_get':
    $chat = $_GET['chat'] ?? ''; $name = basename($_GET['name'] ?? '');
    if (!own_chat($db, $me['id'], $chat)) { http_response_code(403); j(['err' => 'no']); }
    $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
    $p = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . "/files/$cid/$name";
    if (!is_file($p)) { http_response_code(404); j(['err' => '404']); }
    j(['name' => $name, 'content' => mb_substr(file_get_contents($p), 0, 100000)]);

  case 'file_put':
    $chat = trim($in['chat'] ?? ''); $name = basename($in['name'] ?? '');
    if (!own_chat($db, $me['id'], $chat)) { http_response_code(403); j(['err' => 'no']); }
    if ($name === '') { http_response_code(400); j(['err' => 'name']); }
    $saved = make_file($chat, $name, (string)($in['content'] ?? ''));
    j(['ok' => (bool)$saved, 'name' => $saved]);

  case 'file_del':
    $chat = trim($in['chat'] ?? ''); $name = basename($in['name'] ?? '');
    if (!own_chat($db, $me['id'], $chat)) { http_response_code(403); j(['err' => 'no']); }
    $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
    $p = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . "/files/$cid/$name";
    if (is_file($p)) @unlink($p);
    j(['ok' => true]);

  case 'video_status':
    j(video_status_fetch($_GET['job'] ?? ''));

  case 'video_save':
    $id  = (int)($in['id'] ?? 0);
    $url = trim($in['url'] ?? '');
    if ($id < 1 || !preg_match('~^/gen/[a-f0-9]+\.mp4$~', $url)) { http_response_code(400); j(['err' => 'bad']); }
    $db->prepare('UPDATE messages SET content=? WHERE id=? AND user_id=? AND role="ai"')
       ->execute(['[video]' . $url, $id, $me['id']]);
    j(['ok' => true]);

  case 'msg_edit':
    $id = (int)($in['id'] ?? 0);
    $text = trim($in['text'] ?? '');
    if ($id < 1 || $text === '') { http_response_code(400); j(['err' => 'bad']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
    $db->prepare('UPDATE messages SET content=? WHERE id=? AND user_id=? AND role="user"')
       ->execute([$text, $id, $me['id']]);
    j(['ok' => true]);

  case 'msg_delete':
    $db->prepare('DELETE FROM messages WHERE id=? AND user_id=?')
       ->execute([(int)($in['id'] ?? 0), $me['id']]);
    j(['ok' => true]);

  case 'chat_rename':
    $chat  = trim($in['chat'] ?? '');
    $title = mb_substr(trim($in['title'] ?? ''), 0, 120);
    if ($chat === '' || $title === '') { http_response_code(400); j(['err' => 'bad']); }
    $db->prepare('UPDATE chats SET title=? WHERE chat_id=? AND user_id=?')
       ->execute([$title, $chat, $me['id']]);
    j(['ok' => true]);

  case 'settings_get':
    $st = $db->prepare('SELECT allow_anger, persona, mode, model FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$_GET['chat'] ?? '', $me['id']]);
    j($st->fetch() ?: ['allow_anger' => 0, 'persona' => '', 'mode' => 'auto', 'model' => 'smart']);

  case 'settings_set':
    $chat = trim($in['chat'] ?? '');
    if ($chat === '') { http_response_code(400); j(['err' => 'nochat']); }
    $persona = mb_substr(trim($in['persona'] ?? ''), 0, 500);
    $anger   = !empty($in['allow_anger']) ? 1 : 0;
    $mode    = in_array($in['mode'] ?? 'auto', ['auto','normal','code','fast','gen','voice','editimg','video','subs','ideas'], true) ? $in['mode'] : 'auto';
    $model   = in_array($in['model'] ?? 'smart', ['smart','own'], true) ? $in['model'] : 'smart';
    $db->prepare('INSERT INTO chats (chat_id, user_id, title, allow_anger, persona, mode, model) VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE allow_anger=VALUES(allow_anger), persona=VALUES(persona), mode=VALUES(mode), model=VALUES(model)')
       ->execute([$chat, $me['id'], 'Новый чат', $anger, $persona, $mode, $model]);
    j(['ok' => true]);

  case 'send':
    if (setting_get('maintenance', '0') === '1' && !is_admin($me)) {
        j(['reply' => setting_get('maintenance_msg', 'Идут технические работы. Скоро вернёмся.')]);
    }
    $chat = trim($in['chat'] ?? '');
    $text = trim($in['text'] ?? '');
    if ($chat === '' || $text === '') { http_response_code(400); j(['err' => 'empty']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);

    $st = $db->prepare('SELECT allow_anger, persona, mode, model FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$chat, $me['id']]);
    $set = $st->fetch();
    if (!$set) {
        $db->prepare('INSERT INTO chats (chat_id, user_id, title) VALUES (?,?,?)')
           ->execute([$chat, $me['id'], mb_substr($text, 0, 60)]);
        $set = ['allow_anger' => 0, 'persona' => '', 'mode' => 'auto', 'model' => 'smart'];
    }
    // вложения: показываем в сообщении, текстовые файлы подкладываем модели
    $files = is_array($in['files'] ?? null) ? $in['files'] : [];
    $display = $text;
    $modelExtra = '';
    foreach ($files as $fl) {
        $url = $fl['url'] ?? ''; if ($url === '') continue;
        $nm = str_replace(['|', "\n"], ' ', $fl['name'] ?? 'файл');
        $display .= "\n" . (!empty($fl['img']) ? '[img]' . $url : '[file]' . $nm . '|' . $url);
        if (empty($fl['img']) && preg_match('~\.(txt|md|csv|log|json)$~i', $url)) {
            $path = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . $url;
            if (is_file($path) && filesize($path) < 100000) {
                $modelExtra .= "\n\nСодержимое файла " . $nm . ":\n" . mb_substr(file_get_contents($path), 0, 8000);
            }
        }
    }

    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"user",?)')
       ->execute([$me['id'], $chat, $display]);
    $umid = (int)$db->lastInsertId();

    // если чат перехвачен админом — ИИ молчит, сообщение ждёт ответа админа
    $tk = $db->prepare('SELECT 1 FROM takeovers WHERE chat_id=? AND until > NOW()');
    $tk->execute([$chat]);
    if ($tk->fetch()) {
        $db->prepare('UPDATE chats SET updated_at=NOW() WHERE chat_id=?')->execute([$chat]);
        j(['umid' => $umid, 'reply' => null, 'takeover' => true]);
    }

    $mode  = $set['mode'] ?? 'auto';
    $model = $set['model'] ?? 'smart';
    $max   = ($mode === 'fast') ? 160 : 600;

    $dp = draw_prompt($text);
    $wantVK = vk_request($text) && is_super($me);
    $idA = identity_answer($text);

    $fr = file_request($text);
    $delf = del_file_request($text);
    $videoJob = null;

    if ($mode === 'voice') {
        $url = tts_speak($text);
        if ($url) log_action($db, $me['id'], $chat, 'voice', mb_substr($text, 0, 60));
        $reply = $url ? '[audio]' . $url : 'Озвучка не запущена.';
    } elseif ($mode === 'editimg') {
        $img = null;
        foreach ($files as $fl) { if (!empty($fl['img'])) { $img = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . $fl['url']; break; } }
        if (!$img) { $reply = 'Прикрепи фото (📎), которое нужно изменить, и напиши, что поменять.'; }
        else { $url = img_edit($img, $text); if ($url) log_action($db, $me['id'], $chat, 'image', 'правка: ' . $text);
               $reply = $url ? '[img]' . $url : 'Не получилось изменить фото.'; }
    } elseif ($mode === 'video') {
        $secs = (int)($in['seconds'] ?? 5);
        $secs = max(5, min(300, $secs));
        $videoJob = video_submit($text, $secs);
        if ($videoJob) { log_action($db, $me['id'], $chat, 'video', mb_substr($text, 0, 60) . ' (' . $secs . 'с)');
            $clips = (int)ceil($secs / 5);
            $reply = $clips > 1
                ? '⏳ Собираю длинное видео ~' . $secs . ' сек (' . $clips . ' клипов по очереди). Это долго — десятки минут или часы. Можно уйти со страницы, результат подставится сам.'
                : '⏳ Генерирую видео (~5 сек). Несколько минут (первый ролик дольше — качается модель).';
            $reply .= "\n[vjob]" . $videoJob; }
        else $reply = 'Генерация видео не запущена. Проверь сервис video (Wan2.1) и VIDEO_URL в конфиге.';
    } elseif ($mode === 'subs') {
        $reply = 'Субтитры делаются к видео: нужно загрузить видео, распознать речь и наложить текст. Это отдельная настройка — сделаю вместе с видео.';
    } elseif ($mode === 'ideas') {
        $topic = $text !== '' ? $text : 'разное';
        $reply = ai_reply('Придумай идеи по теме/платформе: ' . $topic,
            'Ты генератор идей. Выдай список из 8 конкретных, разных и креативных идей строго по теме пользователя. Каждая идея — одна строка: короткое название и в скобках пояснение в несколько слов. Нумеруй 1–8. Только на русском, без вступления и воды.', 800);
        log_action($db, $me['id'], $chat, 'ideas', mb_substr($topic, 0, 60));
    } elseif ($idA !== null) {
        $reply = $idA;
    } elseif ($delf !== null) {
        $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
        $p = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . "/files/$cid/" . basename($delf);
        if (is_file($p)) { @unlink($p); log_action($db, $me['id'], $chat, 'file', 'удалён ' . $delf);
            $reply = "Удалила файл **" . $delf . "**."; }
        else $reply = "Файла **" . $delf . "** в этом чате нет.";
    } elseif ($fr !== null) {
        $content = ai_reply(
            'Сгенерируй содержимое файла "' . $fr['name'] . '" по запросу: ' . $text .
            '. Выведи ТОЛЬКО содержимое файла, без пояснений и без markdown-обрамления.',
            'Ты генерируешь содержимое файлов (код, тексты, данные).', 1024);
        $saved = make_file($chat, $fr['name'], $content);
        $cid = preg_replace('~[^A-Za-z0-9\-_]~', '', $chat);
        if ($saved) {
            log_action($db, $me['id'], $chat, 'file', $saved);
            $reply = "Создала файл **" . $saved . "**.\n[file]" . $saved . "|/files/" . $cid . "/" . $saved
                   . "\n\nВсе файлы этого чата: http://185.182.108.16:23456/" . $cid;
        } else {
            $reply = "Не удалось создать файл.";
        }
    } elseif ($dp !== null && $wantVK) {
        $url = gen_image($dp);
        $caption = ai_reply('Короткий живой пост для ВКонтакте к картинке по теме: ' . $dp,
            'Ты пишешь короткие посты для соцсети ВКонтакте на русском, с уместными эмодзи.', $max);
        $link = $url ? vk_post($caption, ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/iskra') . $url) : null;
        if ($url) log_action($db, $me['id'], $chat, 'image', $dp);
        if ($link) log_action($db, $me['id'], $chat, 'vk', $link);
        $reply = $url ? '[img]' . $url : 'Не нарисовалось.';
        $reply .= $link ? "\n\n" . $caption . "\n\nОпубликовано в ВК: " . $link
                        : "\n\n(в ВК выложить не вышло — проверь токен и ID группы)";
    } elseif ($wantVK) {
        $caption = ai_reply('Напиши пост для ВКонтакте: ' . $text,
            'Ты пишешь живые посты для ВКонтакте на русском.', $max);
        $link = vk_post($caption, null);
        if ($link) log_action($db, $me['id'], $chat, 'vk', $link);
        $reply = $link ? ($caption . "\n\nОпубликовано в ВК: " . $link)
                       : "Не вышло выложить в ВК — проверь токен и ID группы.";
    } elseif ($dp !== null) {
        $url = gen_image($dp);
        if ($url) log_action($db, $me['id'], $chat, 'image', $dp);
        $reply = $url ? '[img]' . $url : 'Не получилось нарисовать — генератор картинок ещё не запущен.';
    } elseif ($mode === 'gen') {
        // режим генерации: любое сообщение — промпт для картинки
        $url = gen_image($text);
        if ($url) log_action($db, $me['id'], $chat, 'image', $text);
        $reply = $url ? '[img]' . $url : 'Генератор картинок не запущен.';
    } else {
        $reply = kb_lookup($text);
        if ($reply !== null) log_action($db, $me['id'], $chat, 'wiki', $text);
        if ($reply === null && looks_like_question($text)) {
            $reply = web_search($text);
            if ($reply !== null) log_action($db, $me['id'], $chat, 'web', $text);
        }
        if ($reply === null) {
            $hist = chat_history($db, $me['id'], $chat, $umid, 8);
            $prompt = ($hist !== '' ? "Предыдущий разговор:\n" . $hist . "\n\n" : '')
                    . "Пользователь: " . $text . $modelExtra;
            $reply = ($model === 'own')
                ? own_reply($prompt, build_system($set), $max)
                : ai_reply($prompt, build_system($set), $max);
        }
    }
    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"ai",?)')
       ->execute([$me['id'], $chat, $reply]);
    $mid = (int)$db->lastInsertId();
    $db->prepare('UPDATE chats SET updated_at=NOW() WHERE chat_id=?')->execute([$chat]);
    j(['umid' => $umid, 'reply' => $reply, 'id' => $mid, 'video_job' => $videoJob]);

  case 'delete':
    $db->prepare('DELETE FROM chats WHERE chat_id=? AND user_id=?')->execute([$in['chat'] ?? '', $me['id']]);
    $db->prepare('DELETE FROM messages WHERE chat_id=? AND user_id=?')->execute([$in['chat'] ?? '', $me['id']]);
    j(['ok' => true]);

  // ───────── АДМИНКА ─────────
  case 'admin_users':
    require_admin();
    $st = $db->query('SELECT id, email, nick, role, banned, created_at, last_login FROM users ORDER BY id');
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['super'] = is_super($r) ? 1 : 0; }
    j(['users' => $rows, 'me' => $me['id'], 'imp' => !empty($_SESSION['imp_from'])]);

  case 'admin_message':
    require_admin();
    $uid = (int)($in['uid'] ?? 0);
    $text = trim($in['text'] ?? '');
    if ($uid < 1 || $text === '') { http_response_code(400); j(['err' => 'нужен пользователь и текст']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
    $st = $db->prepare('SELECT id FROM users WHERE id=?'); $st->execute([$uid]);
    if (!$st->fetch()) { http_response_code(404); j(['err' => 'нет пользователя']); }
    $st = $db->prepare("SELECT chat_id FROM chats WHERE user_id=? AND title='Сообщение от Искры' ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    $cid = $st->fetchColumn();
    if (!$cid) {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $cid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        $db->prepare("INSERT INTO chats (chat_id, user_id, title) VALUES (?,?,'Сообщение от Искры')")->execute([$cid, $uid]);
    }
    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"ai",?)')->execute([$uid, $cid, $text]);
    $db->prepare('UPDATE chats SET updated_at=NOW() WHERE chat_id=?')->execute([$cid]);
    log_action($db, $me['id'], $cid, 'admin_msg', 'пользователю #' . $uid);
    j(['ok' => true]);

  case 'admin_takeover':
    require_admin();
    $cid = trim($in['chat'] ?? '');
    if ($cid === '') { http_response_code(400); j(['err' => 'нет чата']); }
    $db->prepare('INSERT INTO takeovers (chat_id, admin_id, until) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 SECOND))
                  ON DUPLICATE KEY UPDATE admin_id=VALUES(admin_id), until=VALUES(until)')
       ->execute([$cid, $me['id']]);
    $st = $db->prepare('SELECT id, role, content FROM messages WHERE chat_id=? ORDER BY id LIMIT 500');
    $st->execute([$cid]);
    j(['messages' => $st->fetchAll()]);

  case 'admin_takeover_stop':
    require_admin();
    $db->prepare('DELETE FROM takeovers WHERE chat_id=?')->execute([trim($in['chat'] ?? '')]);
    j(['ok' => true]);

  case 'admin_msg_edit':
    require_admin();
    $id = (int)($in['id'] ?? 0); $text = trim($in['text'] ?? '');
    if ($id < 1 || $text === '') { http_response_code(400); j(['err' => 'bad']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
    $db->prepare('UPDATE messages SET content=? WHERE id=?')->execute([$text, $id]);
    j(['ok' => true]);

  case 'admin_msg_delete':
    require_admin();
    $id = (int)($in['id'] ?? 0);
    if ($id < 1) { http_response_code(400); j(['err' => 'bad']); }
    $db->prepare('DELETE FROM messages WHERE id=?')->execute([$id]);
    j(['ok' => true]);

  case 'messages_since':
    $cid = $_GET['chat'] ?? ($in['chat'] ?? '');
    $after = (int)($_GET['after'] ?? $in['after'] ?? 0);
    $st = $db->prepare('SELECT 1 FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$cid, $me['id']]);
    if (!$st->fetch()) { j(['messages' => []]); }
    $st = $db->prepare('SELECT id, role, content FROM messages WHERE chat_id=? AND id>? ORDER BY id');
    $st->execute([$cid, $after]);
    j(['messages' => $st->fetchAll()]);

  case 'admin_user_chats':
    require_admin();
    $uid = (int)($in['uid'] ?? $_GET['uid'] ?? 0);
    $st = $db->prepare('SELECT chat_id, title, updated_at FROM chats WHERE user_id=? ORDER BY updated_at DESC LIMIT 300');
    $st->execute([$uid]);
    j(['chats' => $st->fetchAll()]);

  case 'admin_chat_messages':
    require_admin();
    $cid = $_GET['chat'] ?? ($in['chat'] ?? '');
    $st = $db->prepare('SELECT id, role, content FROM messages WHERE chat_id=? ORDER BY id LIMIT 500');
    $st->execute([$cid]);
    j(['messages' => $st->fetchAll()]);

  case 'admin_chat_send':
    require_admin();
    $cid = trim($in['chat'] ?? '');
    $text = trim($in['text'] ?? '');
    if ($cid === '' || $text === '') { http_response_code(400); j(['err' => 'нужен чат и текст']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);
    $st = $db->prepare('SELECT user_id FROM chats WHERE chat_id=?'); $st->execute([$cid]);
    $owner = $st->fetchColumn();
    if (!$owner) { http_response_code(404); j(['err' => 'нет такого чата']); }
    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"ai",?)')->execute([$owner, $cid, $text]);
    $db->prepare('UPDATE chats SET updated_at=NOW() WHERE chat_id=?')->execute([$cid]);
    log_action($db, $me['id'], $cid, 'admin_msg', 'в чат пользователя #' . $owner);
    j(['ok' => true]);

  case 'admin_role':
    require_admin();
    $uid = (int)($in['uid'] ?? 0); $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'user';
    $st = $db->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $t = $st->fetch();
    if (!$t) { http_response_code(404); j(['err' => 'нет пользователя']); }
    if (is_super($t)) { http_response_code(403); j(['err' => 'нельзя менять главного админа']); }
    $db->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $uid]);
    j(['ok' => true]);

  case 'admin_ban':
    require_admin();
    $uid = (int)($in['uid'] ?? 0); $ban = !empty($in['ban']) ? 1 : 0;
    $st = $db->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $t = $st->fetch();
    if (!$t) { http_response_code(404); j(['err' => 'нет пользователя']); }
    if (is_super($t)) { http_response_code(403); j(['err' => 'главного админа нельзя забанить']); }
    $db->prepare('UPDATE users SET banned=? WHERE id=?')->execute([$ban, $uid]);
    j(['ok' => true]);

  case 'admin_del':
    require_admin();
    $uid = (int)($in['uid'] ?? 0);
    $st = $db->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $t = $st->fetch();
    if (!$t) { http_response_code(404); j(['err' => 'нет пользователя']); }
    if (is_super($t)) { http_response_code(403); j(['err' => 'главного админа нельзя удалить']); }
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    j(['ok' => true]);

  case 'admin_impersonate':
    require_admin();
    $uid = (int)($in['uid'] ?? 0);
    $st = $db->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$uid]); $t = $st->fetch();
    if (!$t) { http_response_code(404); j(['err' => 'нет пользователя']); }
    if (is_super($t)) { http_response_code(403); j(['err' => 'нельзя войти под главного админа']); }
    $_SESSION['imp_from'] = $me['id'];
    $_SESSION['uid'] = $uid;
    j(['ok' => true]);

  case 'admin_unimpersonate':
    if (!empty($_SESSION['imp_from'])) { $_SESSION['uid'] = $_SESSION['imp_from']; unset($_SESSION['imp_from']); }
    j(['ok' => true]);

  case 'admin_maint_get':
    require_admin();
    j(['on' => setting_get('maintenance', '0'), 'msg' => setting_get('maintenance_msg', 'Идут технические работы. Скоро вернёмся.')]);

  case 'admin_maint_set':
    require_admin();
    setting_set('maintenance', !empty($in['on']) ? '1' : '0');
    if (isset($in['msg'])) setting_set('maintenance_msg', mb_substr((string)$in['msg'], 0, 500));
    j(['ok' => true]);

  case 'admin_sys_get':
    require_admin();
    j(['prompt' => setting_get('system_prompt', '')]);

  case 'admin_sys_set':
    require_admin();
    setting_set('system_prompt', mb_substr((string)($in['prompt'] ?? ''), 0, 2000));
    j(['ok' => true]);

  case 'admin_antilag':
    require_admin();
    exec('sudo /usr/local/bin/iskra-antilag.sh >/dev/null 2>&1 &');
    j(['ok' => true]);

  case 'admin_reboot':
    if (!is_super($me)) { http_response_code(403); j(['err' => 'только главный админ']); }
    exec('sudo /sbin/reboot >/dev/null 2>&1 &');
    j(['ok' => true]);

  case 'admin_shutdown':
    if (!is_super($me)) { http_response_code(403); j(['err' => 'только главный админ']); }
    exec('sudo /sbin/poweroff >/dev/null 2>&1 &');
    j(['ok' => true]);

  default:
    http_response_code(404); j(['err' => 'unknown action']);
}
