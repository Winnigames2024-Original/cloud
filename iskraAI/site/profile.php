<?php
require_once __DIR__ . '/inc/auth.php';
$me = require_login();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $err = 'Сессия устарела, обновите страницу';
    } elseif (($_POST['do'] ?? '') === 'nick') {
        $nick = trim($_POST['nick'] ?? '');
        if (mb_strlen($nick) < 2 || mb_strlen($nick) > 32) $err = 'Имя — от 2 до 32 символов';
        else { db()->prepare('UPDATE users SET nick=? WHERE id=?')->execute([$nick, $me['id']]); $msg = 'Имя изменено'; }
    } elseif (($_POST['do'] ?? '') === 'pass') {
        $cur = $_POST['cur'] ?? ''; $new = $_POST['new'] ?? '';
        if (!password_verify($cur, $me['pass_hash'])) $err = 'Текущий пароль неверный';
        elseif (mb_strlen($new) < 8) $err = 'Новый пароль — минимум 8 символов';
        else { db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]); $msg = 'Пароль изменён'; }
    }
    // перечитать пользователя
    $st = db()->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$me['id']]); $me = $st->fetch();
}

$title = 'Настройки аккаунта';
require_once __DIR__ . '/inc/header.php';
?>
<div class="card">
  <h1>Настройки аккаунта</h1>
  <?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="kv"><span>E-mail</span><span><?= htmlspecialchars($me['email']) ?></span></div>
  <div class="kv"><span>Регистрация</span><span><?= htmlspecialchars($me['created_at']) ?></span></div>
  <div class="kv"><span>Ключ API</span><span><code><?= htmlspecialchars($me['api_key']) ?></code></span></div>

  <form method="post" style="margin-top:22px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="do" value="nick">
    <label>Имя</label>
    <input type="text" name="nick" value="<?= htmlspecialchars($me['nick']) ?>" maxlength="32" required>
    <button type="submit">Сменить имя</button>
  </form>

  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="do" value="pass">
    <label>Текущий пароль</label>
    <input type="password" name="cur" required>
    <label>Новый пароль</label>
    <input type="password" name="new" minlength="8" required>
    <button type="submit">Сменить пароль</button>
  </form>

  <?php if (is_admin($me)): ?>
    <div class="alt" style="margin-top:20px"><a href="/admin.php">→ Админка</a></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
