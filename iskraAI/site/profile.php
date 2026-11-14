<?php
require_once __DIR__ . '/inc/auth.php';
$me = require_login();
$title = 'Профиль';
require_once __DIR__ . '/inc/header.php';
?>
<div class="card">
  <h1><?= htmlspecialchars($me['nick']) ?></h1>
  <p class="sub">Данные аккаунта</p>
  <div class="kv"><span>E-mail</span><span><?= htmlspecialchars($me['email']) ?></span></div>
  <div class="kv"><span>Регистрация</span><span><?= htmlspecialchars($me['created_at']) ?></span></div>
  <div class="kv"><span>Ключ API</span><span><code><?= htmlspecialchars($me['api_key']) ?></code></span></div>
  <p class="sub" style="margin-top:18px">Ключ нужен приложению для доступа к Искре. Никому его не показывайте.</p>
</div>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
