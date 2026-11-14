<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';

$cid = preg_replace('~[^A-Za-z0-9-]~', '', $_GET['c'] ?? '');
$st = db()->prepare('SELECT title, visibility FROM chats WHERE chat_id=?');
$st->execute([$cid]);
$chat = $st->fetch();
$ok = $chat && $chat['visibility'] === 'public';

$msgs = [];
if ($ok) {
    $m = db()->prepare('SELECT role, content FROM messages WHERE chat_id=? ORDER BY id');
    $m->execute([$cid]);
    $msgs = $m->fetchAll();
}

function render_share(string $text): string {
    $out = ''; $buf = [];
    $flush = function () use (&$out, &$buf) {
        if ($buf) { $out .= '<div>' . nl2br(htmlspecialchars(implode("\n", $buf))) . '</div>'; $buf = []; }
    };
    foreach (explode("\n", $text) as $l) {
        if (strncmp($l, '[img]', 5) === 0) { $flush(); $out .= '<img class="genimg" src="' . htmlspecialchars(substr($l, 5)) . '">'; }
        elseif (strncmp($l, '[file]', 6) === 0) { $flush(); $p = explode('|', substr($l, 6)); $out .= '<a class="filechip" href="' . htmlspecialchars($p[1] ?? '#') . '">📎 ' . htmlspecialchars($p[0] ?? 'файл') . '</a>'; }
        else $buf[] = $l;
    }
    $flush();
    return $out;
}
?>
<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $ok ? htmlspecialchars($chat['title']) : 'Чат недоступен' ?> — Искра</title>
<link rel="stylesheet" href="/assets/app.css?v=3">
</head>
<body data-theme="dark">
<div class="share-wrap">
  <header class="share-head">
    <a href="/" class="logo"><span class="spark">◆</span> Искра</a>
    <?php if ($ok): ?><span class="share-title"><?= htmlspecialchars($chat['title']) ?></span><?php endif; ?>
  </header>
  <?php if (!$ok): ?>
    <div class="share-empty">Этот чат не существует или он приватный.</div>
  <?php else: ?>
    <div class="messages share-msgs">
      <?php foreach ($msgs as $m): ?>
        <div class="row <?= $m['role'] === 'user' ? 'user' : 'ai' ?>">
          <div class="bubble"><?= render_share($m['content']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="share-foot">Поделились через Искру · <a href="/register.php">создать свой чат</a></div>
  <?php endif; ?>
</div>
</body></html>
