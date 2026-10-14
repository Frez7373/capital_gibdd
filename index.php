<?php
require __DIR__ . '/includes/bootstrap.php';
$page_title = 'Поиск автомобиля';
$q = trim((string)($_GET['q'] ?? ''));
$results = []; $searched = false;
if ($q !== '') {
    $searched = true;
    $normalized = mb_strtoupper(preg_replace('/\s+/u', '', $q));
    $stmt = $pdo->prepare('SELECT id, plate, vin, make, model, year, color, status FROM vehicles WHERE REPLACE(UPPER(plate), " ", "") LIKE ? OR UPPER(vin) LIKE ? OR make LIKE ? OR model LIKE ? ORDER BY id DESC LIMIT 25');
    $like = '%' . $normalized . '%'; $words = '%' . $q . '%';
    $stmt->execute([$like, $like, $words, $words]); $results = $stmt->fetchAll();
}
$vehicleCount = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$fineCount = (int)$pdo->query("SELECT COUNT(*) FROM fines WHERE status='issued'")->fetchColumn();
$historyCount = (int)$pdo->query('SELECT COUNT(*) FROM vehicle_history')->fetchColumn();
$recent = $pdo->query('SELECT id, plate, make, model, year, status FROM vehicles ORDER BY created_at DESC LIMIT 5')->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div class="hero-copy"><span class="eyebrow">Единый транспортный реестр</span><h1>История автомобиля.<br><span>Вся информация в одном месте.</span></h1><p class="lead">Проверяйте регистрационные данные, историю владения и отметки автомобиля. Сотрудники ГИБДД могут регистрировать транспорт, оформлять штрафы и передавать начисления в банк «Капитал-Стандарт».</p><div class="hero-actions"><a class="btn btn-primary" href="#search">⌕ &nbsp; Проверить автомобиль</a><?php if (has_role(['officer','admin'])): ?><a class="btn btn-ghost" href="register_vehicle.php">＋ Зарегистрировать ТС</a><?php endif; ?></div></div>
  <div class="panel search-panel" id="search"><span class="eyebrow">Быстрая проверка</span><h2>Найдите автомобиль</h2><p>Введите госномер, VIN, марку или модель.</p><div class="search-tabs"><span>ГОСНОМЕР</span><span>VIN</span><span>МАРКА / МОДЕЛЬ</span></div><form class="search-form" method="get" action="index.php"><div class="field"><label for="q">Данные автомобиля</label><input id="q" name="q" value="<?= e($q) ?>" placeholder="Например, А123ВС200" autocomplete="off" required></div><button class="btn btn-primary" type="submit">Найти в базе <span>→</span></button></form></div>
</section>
<?php if ($searched): ?>
<section class="panel" style="margin-bottom:25px"><div class="panel-header"><h2>Результаты поиска</h2><p>Запрос: «<?= e($q) ?>» · найдено: <?= count($results) ?></p></div><?php if ($results): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Госномер</th><th>Автомобиль</th><th>Год</th><th>Цвет</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach ($results as $v): ?><tr><td><span class="license-plate plate-sm"><?= e($v['plate']) ?></span></td><td><strong><?= e($v['make'].' '.$v['model']) ?></strong><br><span class="muted tiny">VIN <?= e($v['vin']) ?></span></td><td><?= e($v['year']) ?></td><td><?= e($v['color'] ?: '—') ?></td><td><span class="badge <?= $v['status']==='active'?'badge-green':'badge-yellow' ?>"><?= e(status_label($v['status'])) ?></span></td><td><a class="btn btn-ghost btn-small" href="vehicle.php?id=<?= (int)$v['id'] ?>">Карточка →</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><div class="empty-icon">⌕</div><h2>Ничего не найдено</h2><p>Проверьте написание госномера или VIN. Поиск не показывает личные данные владельцев.</p></div><?php endif; ?></section>
<?php else: ?>
<div class="stats-grid"><div class="stat-card"><div class="stat-icon">▣</div><div><strong><?= number_format($vehicleCount,0,',',' ') ?></strong><span>Автомобилей в реестре</span></div></div><div class="stat-card"><div class="stat-icon">◷</div><div><strong><?= number_format($historyCount,0,',',' ') ?></strong><span>Записей в истории</span></div></div><div class="stat-card"><div class="stat-icon">₽</div><div><strong><?= number_format($fineCount,0,',',' ') ?></strong><span>Активных штрафов</span></div></div></div>
<section class="section-head"><div><span class="eyebrow">Последние обновления</span><h2 style="margin-top:8px">Недавно зарегистрированы</h2></div><span class="muted tiny">Данные обновляются сотрудниками ГИБДД</span></section>
<section class="panel" style="margin-bottom:30px"><?php if ($recent): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Госномер</th><th>Автомобиль</th><th>Год выпуска</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach ($recent as $v): ?><tr><td><span class="license-plate plate-sm"><?= e($v['plate']) ?></span></td><td><strong><?= e($v['make'].' '.$v['model']) ?></strong></td><td><?= e($v['year']) ?></td><td><span class="badge <?= $v['status']==='active'?'badge-green':'badge-yellow' ?>"><?= e(status_label($v['status'])) ?></span></td><td><a class="btn btn-ghost btn-small" href="vehicle.php?id=<?= (int)$v['id'] ?>">Открыть →</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><div class="empty-icon">▣</div><h2>Реестр пока пуст</h2><p>После добавления первого транспортного средства здесь появятся последние записи.</p><?php if (has_role(['officer','admin'])): ?><a href="register_vehicle.php" class="btn btn-primary">Зарегистрировать ТС</a><?php endif; ?></div><?php endif; ?></section>
<section class="section-head"><div><span class="eyebrow">Возможности платформы</span><h2 style="margin-top:8px">Для водителей и сотрудников</h2></div></section><div class="feature-grid"><article class="feature-card"><div class="feature-icon">⌕</div><h3>История автомобиля</h3><p>Регистрационные события, фотографии, изменение собственника и заметки инспектора в хронологическом порядке.</p></article><article class="feature-card"><div class="feature-icon">▤</div><h3>Управление штрафами</h3><p>Сотрудники могут оформить постановление, а начисление будет отправлено владельцу через API банка.</p></article><article class="feature-card"><div class="feature-icon">⌘</div><h3>Ролевая система</h3><p>Отдельные права для владельцев, инспекторов, сотрудников ГИБДД и администраторов реестра.</p></article></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
