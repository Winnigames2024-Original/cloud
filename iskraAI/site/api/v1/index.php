<?php
require_once __DIR__ . '/../../inc/auth.php';
header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['err' => 'auth']); exit; }

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

function build_system(array $s): string {
    $t = "Тебя зовут Искра. Ты отвечаешь по-русски, дружелюбно и по делу.";
    if (!empty($s['persona'])) $t .= " Твой характер: " . $s['persona'] . ".";
    $t .= empty($s['allow_anger'])
        ? " Никогда не злись и не груби — оставайся спокойной и доброжелательной."
        : " Тебе позволено злиться и отвечать резко, если собеседник этого заслуживает.";
    return $t;
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

function ai_reply(string $prompt, string $system): string {
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

  case 'history':
    $st = $db->prepare('SELECT id, role, content FROM messages WHERE user_id=? AND chat_id=? ORDER BY id');
    $st->execute([$me['id'], $_GET['chat'] ?? '']);
    j(['messages' => $st->fetchAll()]);

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
    $st = $db->prepare('SELECT allow_anger, persona FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$_GET['chat'] ?? '', $me['id']]);
    j($st->fetch() ?: ['allow_anger' => 0, 'persona' => '']);

  case 'settings_set':
    $chat = trim($in['chat'] ?? '');
    if ($chat === '') { http_response_code(400); j(['err' => 'nochat']); }
    $persona = mb_substr(trim($in['persona'] ?? ''), 0, 500);
    $anger   = !empty($in['allow_anger']) ? 1 : 0;
    $db->prepare('INSERT INTO chats (chat_id, user_id, title, allow_anger, persona) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE allow_anger=VALUES(allow_anger), persona=VALUES(persona)')
       ->execute([$chat, $me['id'], 'Новый чат', $anger, $persona]);
    j(['ok' => true]);

  case 'send':
    $chat = trim($in['chat'] ?? '');
    $text = trim($in['text'] ?? '');
    if ($chat === '' || $text === '') { http_response_code(400); j(['err' => 'empty']); }
    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 4000);

    $st = $db->prepare('SELECT allow_anger, persona FROM chats WHERE chat_id=? AND user_id=?');
    $st->execute([$chat, $me['id']]);
    $set = $st->fetch();
    if (!$set) {
        $db->prepare('INSERT INTO chats (chat_id, user_id, title) VALUES (?,?,?)')
           ->execute([$chat, $me['id'], mb_substr($text, 0, 60)]);
        $set = ['allow_anger' => 0, 'persona' => ''];
    }
    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"user",?)')
       ->execute([$me['id'], $chat, $text]);
    $umid = (int)$db->lastInsertId();
    // 1) Википедия (определения), 2) Google (вопросы), 3) своя модель
    $reply = kb_lookup($text);
    if ($reply === null && looks_like_question($text)) $reply = web_search($text);
    if ($reply === null) $reply = ai_reply($text, build_system($set));
    $db->prepare('INSERT INTO messages (user_id, chat_id, role, content) VALUES (?,?,"ai",?)')
       ->execute([$me['id'], $chat, $reply]);
    $mid = (int)$db->lastInsertId();
    $db->prepare('UPDATE chats SET updated_at=NOW() WHERE chat_id=?')->execute([$chat]);
    j(['umid' => $umid, 'reply' => $reply, 'id' => $mid]);

  case 'delete':
    $db->prepare('DELETE FROM chats WHERE chat_id=? AND user_id=?')->execute([$in['chat'] ?? '', $me['id']]);
    $db->prepare('DELETE FROM messages WHERE chat_id=? AND user_id=?')->execute([$in['chat'] ?? '', $me['id']]);
    j(['ok' => true]);

  default:
    http_response_code(404); j(['err' => 'unknown action']);
}
