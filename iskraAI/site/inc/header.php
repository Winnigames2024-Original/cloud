<?php require_once __DIR__ . '/auth.php'; $me = current_user(); ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= isset($title) ? htmlspecialchars($title) . ' — ' : '' ?><?= SITE_NAME ?></title>
<link rel="stylesheet" href="/assets/style.css?v=1">
</head>
<body>
<header class="top">
  <a class="logo" href="/"><span class="spark">◆</span> <?= SITE_NAME ?></a>
  <nav>
    <?php if ($me): ?>
      <span class="nick"><?= htmlspecialchars($me['nick']) ?></span>
      <a href="/profile.php">Профиль</a>
      <a href="/logout.php">Выйти</a>
    <?php else: ?>
      <a href="/login.php">Вход</a>
      <a class="btn-sm" href="/register.php">Регистрация</a>
    <?php endif; ?>
  </nav>
</header>
<main>
