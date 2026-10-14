<?php
$page_title = $page_title ?? 'Панель управления';
$active_user = current_user();
$flash = take_flash();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b1220">
  <title><?= e($page_title) ?> — <?= e($config['site_name']) ?></title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<div class="app-shell">
<header class="topbar">
  <a class="brand" href="index.php" aria-label="АвтоКонтроль — главная">
    <span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14.5 5.8 9a2 2 0 0 1 1.9-1.4h8.6A2 2 0 0 1 18.2 9l1.8 5.5V19h-2v-2H6v2H4zm2.5-2h11l-1.1-3.2H7.6zM7 14.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4m10 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4"/></svg></span>
    <span><strong>АвтоКонтроль</strong><small>ГИБДД · КРАСНОГРАД</small></span>
  </a>
  <nav class="main-nav">
    <a href="index.php" class="<?= basename($_SERVER['PHP_SELF'])==='index.php'?'active':'' ?>">Поиск авто</a>
    <?php if ($active_user): ?><a href="my_account.php">Мой кабинет</a><?php if (has_role('user')): ?><a href="marketplace.php" class="<?= basename($_SERVER['PHP_SELF'])==='marketplace.php'?'active':'' ?>">Продажа авто</a><?php endif; ?><?php endif; ?>
    <?php if (has_role(['officer','admin'])): ?><a href="register_vehicle.php">Регистрация ТС</a><a href="issue_fine.php">Оформить штраф</a><?php endif; ?>
    <?php if (has_role(['inspector','officer','admin'])): ?><a href="fines.php">Реестр штрафов</a><?php endif; ?>
    <?php if (has_role('admin')): ?><a href="admin_users.php">Пользователи</a><a href="admin_integration.php">Интеграция API</a><?php endif; ?>
  </nav>
  <div class="top-actions">
    <?php if ($active_user): ?><div class="user-pill"><span class="avatar-mini"><?= e(mb_strtoupper(mb_substr((string)$active_user['full_name'],0,1))) ?></span><span class="user-pill-name"><?= e($active_user['full_name']) ?><small><?= e(role_label($active_user['role'])) ?></small></span></div><a class="btn btn-ghost btn-small" href="logout.php">Выйти</a>
    <?php else: ?><a class="btn btn-ghost btn-small" href="login.php">Войти</a><a class="btn btn-primary btn-small" href="register.php">Регистрация</a><?php endif; ?>
  </div>
</header>
<main class="page-content">
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><span><?= e($flash['message']) ?></span><button type="button" class="alert-close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
