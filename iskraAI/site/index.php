<?php
require_once __DIR__ . '/inc/auth.php';
$me = current_user();

if (!$me):
    $title = 'Искра';
    require __DIR__ . '/inc/header.php'; ?>
    <div class="hero">
      <h1><span class="spark">◆</span> Искра</h1>
      <p>Нейросеть, обученная с нуля. Своя архитектура, свои данные, свой сервер.</p>
      <a class="btn-sm" href="/register.php">Начать</a>
    </div>
    <?php require __DIR__ . '/inc/footer.php';
    exit;
endif;

if (setting_get('maintenance', '0') === '1' && !is_admin($me)):
    $title = 'Технические работы';
    require __DIR__ . '/inc/header.php'; ?>
    <div class="hero">
      <h1>🛠 Технические работы</h1>
      <p><?= htmlspecialchars(setting_get('maintenance_msg', 'Идут технические работы. Скоро вернёмся.')) ?></p>
    </div>
    <?php require __DIR__ . '/inc/footer.php';
    exit;
endif;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Искра</title>
<link rel="stylesheet" href="/assets/app.css?v=11">
</head>
<body>
<?php if (!empty($_SESSION['imp_from'])): ?>
<div class="imp-banner">Вы вошли как <?= htmlspecialchars($me['nick']) ?> (режим проверки) ·
  <a href="#" onclick="fetch('/api/v1/index.php?action=admin_unimpersonate',{method:'POST'}).then(()=>location.href='/admin.php');return false;">Вернуться в свой аккаунт</a>
</div>
<?php endif; ?>
<div class="app" id="app">
  <aside class="sidebar" id="sidebar">
    <div class="sb-top"><button class="newchat" id="newchat">＋ Новый чат</button></div>
    <div class="chats" id="chatlist"></div>
    <div class="sb-user">
      <?php if (is_admin($me)): ?><a class="admin-btn" href="/admin.php">⚙ Админ-панель</a><?php endif; ?>
      <div class="who"><span class="dot">◆</span> <?= htmlspecialchars($me['nick']) ?></div>
      <div class="who-links">
        <a href="/profile.php">Профиль</a>
        <a href="/logout.php">Выйти</a>
      </div>
    </div>
  </aside>

  <main class="main">
    <header class="bar">
      <button class="burger" id="burger" aria-label="Меню">≡</button>
      <span class="bar-title">Искра</span>
      <button class="icon" id="acts" title="Действия ИИ">⚡</button>
      <button class="icon" id="files" title="Файлы этого чата">📁</button>
      <button class="icon" id="share" title="Поделиться чатом">↗</button>
      <button class="icon" id="theme" title="Тема">◐</button>
      <button class="icon" id="gear" title="Характер Искры в этом чате">⚙</button>
    </header>

    <div class="messages" id="messages">
      <div class="empty" id="empty">
        <div class="spark-big">◆</div>
        <p>Напиши что-нибудь — Искра пока учится, но уже слушает.</p>
      </div>
    </div>

    <div class="composer">
      <div class="attach-previews" id="previews"></div>
      <div class="composer-main">
        <textarea id="input" rows="1" placeholder="Сообщение Искре…"></textarea>
        <div class="composer-tools">
          <button class="tool" id="attach" title="Прикрепить файл или картинку">📎</button>
          <input type="file" id="file" multiple accept="image/*,.txt,.md,.csv,.log,.json,.pdf" hidden>
          <select id="mode" class="barsel" title="Режим">
            <option value="auto">АВТО</option>
            <option value="normal">Обычный</option>
            <option value="code">Экстра · код</option>
            <option value="fast">Быстрый</option>
          </select>
          <select id="model" class="barsel" title="Модель">
            <option value="smart">Искра Про</option>
            <option value="own">Искра · своя</option>
          </select>
          <span class="spacer"></span>
          <button id="send" class="sendbtn" aria-label="Отправить">↑</button>
        </div>
      </div>
    </div>
  </main>

  <aside class="rightbar" id="rightbar">
    <div class="rb-head"><span>Проводник</span>
      <button class="icon" id="rb-refresh" title="Обновить">⟳</button>
      <button class="icon" id="rb-close" title="Закрыть">×</button>
    </div>
    <div class="rb-files" id="rb-files"></div>
    <div class="rb-foot"><a id="rb-ext" href="#" target="_blank">Открыть отдельно ↗</a></div>
  </aside>
</div>

<div class="modal" id="settings" hidden>
  <div class="modal-box">
    <h3>Характер Искры — этот чат</h3>
    <label>Описание характера (необязательно)</label>
    <textarea id="s-persona" rows="3" placeholder="Например: добрая, отвечает коротко, любит шутить"></textarea>
    <label class="chk"><input type="checkbox" id="s-anger"> Может злиться</label>
    <div class="modal-actions">
      <button class="ghost" id="s-cancel">Отмена</button>
      <button id="s-save">Сохранить</button>
    </div>
  </div>
</div>

<div class="modal" id="viewer" hidden>
  <div class="modal-box modal-wide">
    <h3 id="v-name">файл</h3>
    <textarea id="v-content" rows="16" spellcheck="false"></textarea>
    <div class="modal-actions">
      <button class="ghost" id="v-del">Удалить</button>
      <button class="ghost" id="v-close">Закрыть</button>
      <button id="v-save">Сохранить</button>
    </div>
  </div>
</div>

<div class="modal" id="actions" hidden>
  <div class="modal-box">
    <h3>Действия Искры — этот чат</h3>
    <div id="acts-list" class="acts-list"></div>
    <div class="modal-actions"><button id="acts-close">Закрыть</button></div>
  </div>
</div>

<script src="/assets/app.js?v=10"></script>
</body>
</html>
