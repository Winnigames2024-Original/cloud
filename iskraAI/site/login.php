<?php
require_once __DIR__ . '/inc/auth.php';
if (current_user()) { header('Location: /'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $err = 'Сессия устарела, попробуйте ещё раз';
    } elseif (!rate_ok('login', 15, 15)) {
        $err = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $r = login_user($_POST['email'] ?? '', $_POST['pass'] ?? '');
        if (isset($r['err'])) $err = $r['err'];
        else { header('Location: /'); exit; }
    }
}
$title = 'Вход';
require_once __DIR__ . '/inc/header.php';
?>
<div class="card">
  <h1>Вход</h1>
  <p class="sub">Рады снова видеть.</p>
  <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label>E-mail</label>
    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    <label>Пароль</label>
    <input type="password" name="pass" required>
    <button type="submit">Войти</button>
  </form>
  <div class="alt">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></div>
</div>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
