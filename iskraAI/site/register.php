<?php
require_once __DIR__ . '/inc/auth.php';
if (current_user()) { header('Location: /'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $err = 'Сессия устарела, попробуйте ещё раз';
    } elseif (!rate_ok('register', 5, 60)) {
        $err = 'Слишком много попыток. Подождите час.';
    } else {
        $r = register_user($_POST['email'] ?? '', $_POST['pass'] ?? '', $_POST['nick'] ?? '');
        if (isset($r['err'])) {
            $err = $r['err'];
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $r['uid'];
            header('Location: /'); exit;
        }
    }
}
$title = 'Регистрация';
require_once __DIR__ . '/inc/header.php';
?>
<div class="card">
  <h1>Регистрация</h1>
  <p class="sub">Аккаунт нужен, чтобы Искра помнила ваши диалоги.</p>
  <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <label>Имя</label>
    <input type="text" name="nick" required maxlength="32" value="<?= htmlspecialchars($_POST['nick'] ?? '') ?>">
    <label>E-mail</label>
    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    <label>Пароль</label>
    <input type="password" name="pass" required minlength="8">
    <button type="submit">Создать аккаунт</button>
  </form>
  <div class="alt">Уже есть аккаунт? <a href="/login.php">Войти</a></div>
</div>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
