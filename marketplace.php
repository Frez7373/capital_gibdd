<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bank.php';
require_login();

$u = current_user();
$page_title = 'Продажа автомобилей';
$errors = [];
$isCustomer = ($u['role'] ?? '') === 'user';

function marketplace_status_label(string $status): string {
    return [
        'pending'=>'Ожидает принятия',
        'processing'=>'Проверяется оплата',
        'payment_failed'=>'Оплата не прошла — можно повторить',
        'completed_cash'=>'Завершено · наличные',
        'completed_bank'=>'Завершено · перевод',
        'declined'=>'Отклонено продавцом',
        'cancelled'=>'Отменено',
        'active'=>'В продаже',
        'sold'=>'Продан',
        'withdrawn'=>'Снято с продажи',
    ][$status] ?? $status;
}

function marketplace_complete_purchase(PDO $pdo, int $purchaseId, string $completedStatus, ?string $bankReference = null): void {
    if (!in_array($completedStatus, ['completed_cash','completed_bank'], true)) {
        throw new InvalidArgumentException('Недопустимый статус завершения сделки.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT p.*,s.status AS sale_status,s.seller_account_number,
                                      v.owner_user_id AS current_owner_user_id,v.owner_email AS current_owner_email,
                                      buyer.full_name AS buyer_name
                               FROM vehicle_purchases p
                               JOIN vehicle_sales s ON s.id=p.sale_id
                               JOIN vehicles v ON v.id=p.vehicle_id
                               JOIN users buyer ON buyer.id=p.buyer_user_id
                               WHERE p.id=? FOR UPDATE");
        $stmt->execute([$purchaseId]);
        $p = $stmt->fetch();
        if (!$p) throw new RuntimeException('Заявка на покупку не найдена.');
        if ($p['status'] === 'completed_cash' || $p['status'] === 'completed_bank') {
            $pdo->commit();
            return;
        }
        if ($completedStatus === 'completed_bank' && $p['status'] !== 'processing') {
            throw new RuntimeException('Статус сделки изменился. Обновите страницу.');
        }
        if ($completedStatus === 'completed_cash' && $p['status'] !== 'pending') {
            throw new RuntimeException('Эту заявку нельзя принять: её статус уже изменён.');
        }
        if ($p['sale_status'] !== ($completedStatus === 'completed_cash' ? 'active' : 'processing')) {
            throw new RuntimeException('Объявление уже недоступно или обрабатывается другая покупка.');
        }
        $currentOwnerMatches = (int)$p['current_owner_user_id'] === (int)$p['seller_user_id']
            || strcasecmp((string)$p['current_owner_email'], (string)$p['seller_email']) === 0;
        if (!$currentOwnerMatches) throw new RuntimeException('Владелец автомобиля уже изменился.');

        $stmt = $pdo->prepare("UPDATE vehicles SET owner_user_id=?,owner_name=?,owner_email=?,updated_at=NOW()
                               WHERE id=? AND (owner_user_id=? OR LOWER(owner_email)=LOWER(?))");
        $stmt->execute([
            (int)$p['buyer_user_id'],
            (string)$p['buyer_name'],
            (string)$p['buyer_email'],
            (int)$p['vehicle_id'],
            (int)$p['seller_user_id'],
            (string)$p['seller_email'],
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Не удалось сменить владельца: автомобиль уже изменён.');
        $pdo->prepare("UPDATE vehicle_sales SET status='sold',sold_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([(int)$p['sale_id']]);
        $pdo->prepare("UPDATE vehicle_purchases SET status=?,bank_transaction_reference=?,bank_last_error=NULL,accepted_at=COALESCE(accepted_at,NOW()),completed_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([$completedStatus, $bankReference, $purchaseId]);
        $description = $completedStatus === 'completed_cash'
            ? 'Собственник изменён после подтверждения продавцом получения наличных.'
            : 'Собственник изменён после успешного перевода с банковского счёта покупателя.';
        $pdo->prepare("INSERT INTO vehicle_history(vehicle_id,event_type,title,description,event_date,created_by,created_at)
                       VALUES(?,'ownership_transfer','Смена собственника',?,CURDATE(),?,NOW())")
            ->execute([(int)$p['vehicle_id'], $description, (int)$p['seller_user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if (!$isCustomer && $_SERVER['REQUEST_METHOD'] === 'POST') {
    set_flash('danger', 'Покупка и продажа автомобилей доступны только обычным пользователям.');
    redirect('marketplace.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$isCustomer) throw new RuntimeException('Покупка и продажа автомобилей доступны только владельцам аккаунтов.');

        if ($action === 'create_listing') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $priceRaw = trim(str_replace([' ', ','], ['', '.'], (string)($_POST['price'] ?? '')));
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $priceRaw)) throw new InvalidArgumentException('Введите корректную цену, например 750000 или 750000.50.');
            $price = number_format((float)$priceRaw, 2, '.', '');
            if ((float)$price <= 0) throw new InvalidArgumentException('Цена должна быть больше нуля.');
            $allowCash = isset($_POST['allow_cash']) ? 1 : 0;
            $allowBank = isset($_POST['allow_bank']) ? 1 : 0;
            if (!$allowCash && !$allowBank) throw new InvalidArgumentException('Выберите хотя бы один способ оплаты.');
            $sellerAccount = null;
            if ($allowBank) {
                $sellerAccount = strtoupper(trim((string)($_POST['seller_account_number'] ?? '')));
                $bankAccounts = bank_list_accounts($config, (string)$u['email']);
                if (!$bankAccounts['ok']) throw new RuntimeException('Не удалось проверить счёт продавца в банке: ' . $bankAccounts['message']);
                $found = false;
                foreach ($bankAccounts['accounts'] as $account) {
                    if (strcasecmp((string)($account['account_number'] ?? ''), $sellerAccount) === 0) { $found = true; break; }
                }
                if (!$found) throw new InvalidArgumentException('Выберите активный банковский счёт, принадлежащий продавцу.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id=? FOR UPDATE');
            $stmt->execute([$vehicleId]);
            $vehicle = $stmt->fetch();
            if (!$vehicle || $vehicle['status'] !== 'active') throw new RuntimeException('Автомобиль не найден или не может быть продан.');
            $ownsVehicle = ((int)($vehicle['owner_user_id'] ?? 0) === (int)$u['id'])
                || strcasecmp((string)$vehicle['owner_email'], (string)$u['email']) === 0;
            if (!$ownsVehicle) throw new RuntimeException('Можно выставлять на продажу только свой автомобиль.');
            $stmt = $pdo->prepare("SELECT id FROM vehicle_sales WHERE vehicle_id=? AND status IN ('active','processing') LIMIT 1 FOR UPDATE");
            $stmt->execute([$vehicleId]);
            if ($stmt->fetchColumn()) throw new RuntimeException('Этот автомобиль уже выставлен на продажу или по нему обрабатывается покупка.');
            $pdo->prepare("INSERT INTO vehicle_sales(vehicle_id,seller_user_id,seller_email,price,allow_cash,allow_bank,seller_account_number,status,created_at,updated_at)
                           VALUES(?,?,?,?,?,?,?,'active',NOW(),NOW())")
                ->execute([$vehicleId, (int)$u['id'], (string)$u['email'], $price, $allowCash, $allowBank, $sellerAccount]);
            $pdo->commit();
            set_flash('success', 'Автомобиль выставлен на продажу.');
            redirect('marketplace.php');
        }

        if ($action === 'request_purchase') {
            $saleId = (int)($_POST['sale_id'] ?? 0);
            $method = (string)($_POST['payment_method'] ?? '');
            if (!in_array($method, ['cash','bank'], true)) throw new InvalidArgumentException('Выберите способ оплаты.');
            $sourceAccount = null;
            if ($method === 'bank') {
                $sourceAccount = strtoupper(trim((string)($_POST['source_account_number'] ?? '')));
                $buyerAccounts = bank_list_accounts($config, (string)$u['email']);
                if (!$buyerAccounts['ok']) throw new RuntimeException('Не удалось загрузить ваши счета из банка: ' . $buyerAccounts['message']);
                $found = false;
                foreach ($buyerAccounts['accounts'] as $account) {
                    if (strcasecmp((string)($account['account_number'] ?? ''), $sourceAccount) === 0) { $found = true; break; }
                }
                if (!$found) throw new InvalidArgumentException('Выберите активный банковский счёт, принадлежащий покупателю.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT s.*,v.owner_user_id AS vehicle_owner_id,v.owner_email AS vehicle_owner_email,v.status AS vehicle_status
                                   FROM vehicle_sales s JOIN vehicles v ON v.id=s.vehicle_id
                                   WHERE s.id=? FOR UPDATE");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale || $sale['status'] !== 'active' || $sale['vehicle_status'] !== 'active') throw new RuntimeException('Это объявление уже недоступно.');
            if ((int)$sale['seller_user_id'] === (int)$u['id']) throw new RuntimeException('Нельзя купить собственный автомобиль.');
            if ($method === 'cash' && !(int)$sale['allow_cash']) throw new RuntimeException('Продавец не принимает наличные.');
            if ($method === 'bank' && !(int)$sale['allow_bank']) throw new RuntimeException('Продавец не принимает оплату по счёту.');
            if ((int)$sale['vehicle_owner_id'] !== (int)$sale['seller_user_id'] && strcasecmp((string)$sale['vehicle_owner_email'], (string)$sale['seller_email']) !== 0) {
                throw new RuntimeException('Владелец автомобиля изменился, объявление недоступно.');
            }
            $stmt = $pdo->prepare("SELECT id FROM vehicle_purchases WHERE sale_id=? AND buyer_user_id=? AND status IN ('pending','processing','payment_failed','completed_cash','completed_bank') LIMIT 1");
            $stmt->execute([$saleId, (int)$u['id']]);
            if ($stmt->fetchColumn()) throw new RuntimeException('У вас уже есть заявка или завершённая покупка по этому объявлению.');
            $externalRef = 'GIBDD-CAR-' . strtoupper(bin2hex(random_bytes(12)));
            $pdo->prepare("INSERT INTO vehicle_purchases(sale_id,vehicle_id,seller_user_id,buyer_user_id,seller_email,buyer_email,payment_method,source_account_number,amount,bank_external_ref,status,created_at,updated_at)
                           VALUES(?,?,?,?,?,?,?,?,?,?, 'pending',NOW(),NOW())")
                ->execute([$saleId,(int)$sale['vehicle_id'],(int)$sale['seller_user_id'],(int)$u['id'],(string)$sale['seller_email'],(string)$u['email'],$method,$sourceAccount,$sale['price'],$externalRef]);
            $pdo->commit();
            set_flash('success', 'Заявка отправлена продавцу. Передача автомобиля и банковский перевод выполнятся только после принятия заявки.');
            redirect('marketplace.php');
        }

        if ($action === 'accept_purchase') {
            $purchaseId = (int)($_POST['purchase_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT p.*,s.status AS sale_status,s.seller_account_number
                                   FROM vehicle_purchases p JOIN vehicle_sales s ON s.id=p.sale_id WHERE p.id=?");
            $stmt->execute([$purchaseId]);
            $purchase = $stmt->fetch();
            if (!$purchase || (int)$purchase['seller_user_id'] !== (int)$u['id']) throw new RuntimeException('Заявка не найдена или принадлежит другому продавцу.');

            if ($purchase['payment_method'] === 'cash') {
                if (($_POST['cash_received'] ?? '') !== '1') throw new InvalidArgumentException('Подтвердите, что наличные действительно получены.');
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT p.status,s.status AS sale_status FROM vehicle_purchases p JOIN vehicle_sales s ON s.id=p.sale_id WHERE p.id=? FOR UPDATE");
                    $stmt->execute([$purchaseId]);
                    $state = $stmt->fetch();
                    if (!$state || $state['status'] !== 'pending' || $state['sale_status'] !== 'active') throw new RuntimeException('Заявку уже обработали или объявление недоступно.');
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                marketplace_complete_purchase($pdo, $purchaseId, 'completed_cash');
                set_flash('success', 'Наличные подтверждены. Автомобиль переоформлен на покупателя.');
                redirect('marketplace.php');
            }

            if ($purchase['payment_method'] !== 'bank') throw new RuntimeException('Неизвестный способ оплаты.');
            // Reserve the listing before calling the bank. Keep the same external reference on every retry.
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT p.*,s.status AS sale_status,s.seller_account_number
                                       FROM vehicle_purchases p JOIN vehicle_sales s ON s.id=p.sale_id WHERE p.id=? FOR UPDATE");
                $stmt->execute([$purchaseId]);
                $purchase = $stmt->fetch();
                if (!$purchase || (int)$purchase['seller_user_id'] !== (int)$u['id']) throw new RuntimeException('Заявка не найдена.');
                if (!in_array($purchase['status'], ['pending','payment_failed','processing'], true)) throw new RuntimeException('Эта заявка уже завершена или отклонена.');
                if (!in_array($purchase['sale_status'], ['active','processing'], true)) throw new RuntimeException('Объявление уже недоступно.');
                if ($purchase['sale_status'] === 'processing') {
                    $stmt = $pdo->prepare("SELECT id FROM vehicle_purchases WHERE sale_id=? AND status='processing' LIMIT 1");
                    $stmt->execute([(int)$purchase['sale_id']]);
                    if ((int)$stmt->fetchColumn() !== $purchaseId) throw new RuntimeException('По объявлению уже обрабатывается другая покупка.');
                }
                $pdo->prepare("UPDATE vehicle_purchases SET status='processing',bank_last_error=NULL,accepted_at=COALESCE(accepted_at,NOW()),updated_at=NOW() WHERE id=?")
                    ->execute([$purchaseId]);
                $pdo->prepare("UPDATE vehicle_sales SET status='processing',updated_at=NOW() WHERE id=?")
                    ->execute([(int)$purchase['sale_id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $result = bank_vehicle_purchase_transfer($config, $purchase, $purchase);
            if (!$result['ok']) {
                if (empty($result['uncertain'])) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE vehicle_purchases SET status='payment_failed',bank_last_error=?,updated_at=NOW() WHERE id=? AND status='processing'")
                            ->execute([$result['message'], $purchaseId]);
                        $pdo->prepare("UPDATE vehicle_sales SET status='active',updated_at=NOW() WHERE id=? AND status='processing'")
                            ->execute([(int)$purchase['sale_id']]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                    set_flash('danger', 'Банк отклонил перевод: ' . $result['message'] . ' Можно исправить причину и повторить принятие заявки.');
                } else {
                    // The request may have reached the bank. Leave the listing reserved; retry uses the same idempotency key.
                    $pdo->prepare("UPDATE vehicle_purchases SET bank_last_error=?,updated_at=NOW() WHERE id=? AND status='processing'")
                        ->execute([$result['message'], $purchaseId]);
                    set_flash('warning', 'Банк не подтвердил результат. Объявление временно зарезервировано. Нажмите повторно для безопасной проверки; повторный запрос не спишет деньги дважды.');
                }
                redirect('marketplace.php');
            }

            marketplace_complete_purchase($pdo, $purchaseId, 'completed_bank', (string)($result['data']['transaction_reference'] ?? ''));
            set_flash('success', 'Банк подтвердил перевод. Деньги перечислены продавцу, автомобиль переоформлен на покупателя.');
            redirect('marketplace.php');
        }

        if ($action === 'reject_purchase') {
            $purchaseId = (int)($_POST['purchase_id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT p.*,s.status AS sale_status FROM vehicle_purchases p JOIN vehicle_sales s ON s.id=p.sale_id WHERE p.id=? FOR UPDATE');
                $stmt->execute([$purchaseId]);
                $purchase = $stmt->fetch();
                if (!$purchase || (int)$purchase['seller_user_id'] !== (int)$u['id']) throw new RuntimeException('Заявка не найдена.');
                if (!in_array($purchase['status'], ['pending','payment_failed'], true) || $purchase['sale_status'] !== 'active') {
                    throw new RuntimeException('Эту заявку нельзя отклонить: оплата уже обрабатывается или сделка завершена.');
                }
                $pdo->prepare("UPDATE vehicle_purchases SET status='declined',updated_at=NOW() WHERE id=?")->execute([$purchaseId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            set_flash('success', 'Заявка отклонена.');
            redirect('marketplace.php');
        }

        if ($action === 'withdraw_listing') {
            $saleId = (int)($_POST['sale_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE vehicle_sales SET status='withdrawn',updated_at=NOW() WHERE id=? AND seller_user_id=? AND status='active'");
            $stmt->execute([$saleId, (int)$u['id']]);
            if (!$stmt->rowCount()) throw new RuntimeException('Объявление не найдено, уже снято или по нему обрабатывается покупка.');
            set_flash('success', 'Объявление снято с продажи.');
            redirect('marketplace.php');
        }

        throw new InvalidArgumentException('Неизвестное действие.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Не удалось обработать действие. Проверьте настройки базы данных и повторите попытку.';
    }
}

$bankAccounts = ['ok'=>false,'accounts'=>[],'message'=>''];
if ($isCustomer && bank_is_configured($config)) {
    $bankAccounts = bank_list_accounts($config, (string)$u['email']);
}
$ownedVehicles = [];
if ($isCustomer) {
    $stmt = $pdo->prepare("SELECT v.id,v.plate,v.make,v.model,v.year
                           FROM vehicles v
                           WHERE v.status='active' AND (v.owner_user_id=? OR LOWER(v.owner_email)=LOWER(?))
                             AND NOT EXISTS (SELECT 1 FROM vehicle_sales s WHERE s.vehicle_id=v.id AND s.status IN ('active','processing'))
                           ORDER BY v.make,v.model");
    $stmt->execute([(int)$u['id'], (string)$u['email']]);
    $ownedVehicles = $stmt->fetchAll();
}
$listings = $pdo->query("SELECT s.*,v.plate,v.make,v.model,v.year,v.color,
                                seller.full_name AS seller_name,
                                (SELECT p.file_path FROM vehicle_photos p WHERE p.vehicle_id=v.id ORDER BY p.created_at DESC LIMIT 1) AS photo
                         FROM vehicle_sales s JOIN vehicles v ON v.id=s.vehicle_id
                         JOIN users seller ON seller.id=s.seller_user_id
                         WHERE s.status='active' AND v.status='active'
                         ORDER BY s.created_at DESC LIMIT 100")->fetchAll();
$mySales = [];
$incoming = [];
$myPurchases = [];
if ($isCustomer) {
    $stmt = $pdo->prepare("SELECT s.*,v.plate,v.make,v.model,v.year
                           FROM vehicle_sales s JOIN vehicles v ON v.id=s.vehicle_id
                           WHERE s.seller_user_id=? AND s.status IN ('active','processing','sold','withdrawn')
                           ORDER BY s.created_at DESC LIMIT 50");
    $stmt->execute([(int)$u['id']]);
    $mySales = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT p.*,v.plate,v.make,v.model,v.year,buyer.full_name AS buyer_name
                           FROM vehicle_purchases p JOIN vehicles v ON v.id=p.vehicle_id
                           JOIN users buyer ON buyer.id=p.buyer_user_id
                           WHERE p.seller_user_id=? ORDER BY p.created_at DESC LIMIT 100");
    $stmt->execute([(int)$u['id']]);
    $incoming = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT p.*,v.plate,v.make,v.model,v.year,seller.full_name AS seller_name
                           FROM vehicle_purchases p JOIN vehicles v ON v.id=p.vehicle_id
                           JOIN users seller ON seller.id=p.seller_user_id
                           WHERE p.buyer_user_id=? ORDER BY p.created_at DESC LIMIT 100");
    $stmt->execute([(int)$u['id']]);
    $myPurchases = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-intro"><span class="eyebrow">АвтоКонтроль · сделки между пользователями</span><h1>Продажа автомобилей</h1><p>Покупайте и продавайте транспорт. Передача собственника завершается только после принятия заявки продавцом.</p></section>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<?php if (!$isCustomer): ?><div class="notice">Покупка и продажа доступны обычным пользовательским аккаунтам. Сотрудники ГИБДД продолжают вести реестр в служебных разделах.</div><?php else: ?>
<?php if (!bank_is_configured($config)): ?><div class="notice" style="margin-bottom:18px">Банковская оплата отключена: укажите URL API и общий партнёрский ключ в настройках. Оплата наличными продолжит работать.</div>
<?php elseif (!$bankAccounts['ok']): ?><div class="notice" style="margin-bottom:18px">Банк сейчас недоступен: <?= e($bankAccounts['message']) ?>. Можно использовать наличные; банковский способ появится, когда API ответит.</div><?php endif; ?>

<section class="panel" style="margin-bottom:24px"><div class="panel-header"><h2>Выставить свой автомобиль</h2><p>Выберите цену и способы оплаты</p></div><div class="panel-body">
<?php if (!$ownedVehicles): ?><p class="muted tiny">Нет доступных автомобилей для выставления на продажу. Автомобиль должен быть зарегистрирован на ваш аккаунт и не должен уже участвовать в другой сделке.</p>
<?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_listing"><div class="form-grid">
<div class="field"><label for="vehicle_id">Автомобиль</label><select id="vehicle_id" name="vehicle_id" required><option value="">Выберите свой автомобиль</option><?php foreach($ownedVehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['make'].' '.$v['model'].' '.$v['year'].' · '.$v['plate']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="price">Цена, ₽</label><input id="price" name="price" type="number" min="0.01" step="0.01" required placeholder="750000.00"></div>
<div class="field"><label>Способы оплаты</label><label class="check-row"><input type="checkbox" name="allow_cash" value="1" checked> Наличными</label><label class="check-row"><input type="checkbox" id="allow_bank" name="allow_bank" value="1" <?= $bankAccounts['ok'] && $bankAccounts['accounts'] ? '' : 'disabled' ?>> Переводом со счёта в «Капитал-Стандарт»</label><?php if(!$bankAccounts['ok']): ?><small>Банк не подтвердил доступность счетов.</small><?php endif; ?></div>
<div class="field" id="seller-account-field"><label for="seller_account_number">Счёт для получения оплаты</label><select id="seller_account_number" name="seller_account_number" <?= $bankAccounts['ok'] && $bankAccounts['accounts'] ? '' : 'disabled' ?>><option value="">Выберите свой счёт</option><?php foreach($bankAccounts['accounts'] as $a): ?><option value="<?= e($a['account_number']) ?>"><?= e(($a['name']??'Счёт').' · '.$a['account_number'].' · '.($a['balance']??'').' ₽') ?></option><?php endforeach; ?></select><small>Реквизиты проверяются банком; покупатель увидит только название продавца и цену.</small></div>
</div><div class="form-actions"><button class="btn btn-primary" type="submit">Опубликовать объявление →</button></div></form><?php endif; ?>
</div></section>

<section class="section-head"><div><span class="eyebrow">Активные объявления</span><h2 style="margin-top:8px">Автомобили в продаже</h2></div><span class="muted tiny"><?= count($listings) ?> объявлений</span></section>
<?php if (!$listings): ?><section class="panel empty-state"><div class="empty-icon">▣</div><h2>Пока нет объявлений</h2><p>Когда владельцы выставят автомобили на продажу, они появятся здесь.</p></section>
<?php else: ?><div class="market-grid"><?php foreach($listings as $listing): ?><article class="panel market-card">
<div class="market-photo"><?php if($listing['photo']): ?><img src="<?= e($listing['photo']) ?>" alt="<?= e($listing['make'].' '.$listing['model']) ?>"><?php else: ?><div class="photo-placeholder"><span>▧</span>Фото не добавлено</div><?php endif; ?></div>
<div class="market-card-body"><div class="market-card-heading"><div><h2><?= e($listing['make'].' '.$listing['model']) ?></h2><p class="muted tiny"><?= e($listing['year']) ?> · <?= e($listing['color'] ?: 'Цвет не указан') ?></p></div><strong class="market-price"><?= money($listing['price']) ?></strong></div>
<div class="market-plate"><span class="license-plate plate-sm"><?= e($listing['plate']) ?></span><span class="muted tiny">Продавец: <?= e($listing['seller_name']) ?></span></div>
<div class="market-methods"><?php if((int)$listing['allow_cash']): ?><span class="badge badge-green">Наличные</span><?php endif; ?><?php if((int)$listing['allow_bank']): ?><span class="badge badge-blue">Банковский счёт</span><?php endif; ?><a class="btn btn-ghost btn-small" href="vehicle.php?id=<?= (int)$listing['vehicle_id'] ?>">Карточка авто</a></div>
<?php if($isCustomer && (int)$listing['seller_user_id'] !== (int)$u['id']): ?><form method="post" class="market-buy-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request_purchase"><input type="hidden" name="sale_id" value="<?= (int)$listing['id'] ?>">
<div class="field"><label>Способ оплаты</label><select name="payment_method" class="market-payment-method" required><?php if((int)$listing['allow_cash']): ?><option value="cash">Наличными — передать продавцу лично</option><?php endif; ?><?php if((int)$listing['allow_bank'] && $bankAccounts['ok'] && $bankAccounts['accounts']): ?><option value="bank">Со счёта — перевод после принятия</option><?php endif; ?></select></div>
<div class="field market-source-account" hidden><label>С какого счёта оплатить</label><select name="source_account_number"><option value="">Выберите счёт</option><?php foreach($bankAccounts['accounts'] as $a): ?><option value="<?= e($a['account_number']) ?>"><?= e(($a['name']??'Счёт').' · '.$a['account_number'].' · '.($a['balance']??'').' ₽') ?></option><?php endforeach; ?></select></div>
<button class="btn btn-primary" type="submit">Отправить заявку →</button></form>
<?php elseif($isCustomer): ?><p class="muted tiny">Это ваше объявление.</p><?php endif; ?>
</div></article><?php endforeach; ?></div><?php endif; ?>

<section class="panel" style="margin-top:24px"><div class="panel-header"><h2>Мои объявления</h2><p>Статус продажи и управление активными предложениями</p></div><?php if(!$mySales): ?><div class="panel-body"><p class="muted tiny">Вы ещё не выставляли автомобили на продажу.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Автомобиль</th><th>Цена</th><th>Оплата</th><th>Статус</th><th>Действие</th></tr></thead><tbody><?php foreach($mySales as $sale): ?><tr><td><strong><?= e($sale['make'].' '.$sale['model']) ?></strong><br><span class="muted tiny"><?= e($sale['plate']) ?></span></td><td><?= money($sale['price']) ?></td><td><?= (int)$sale['allow_cash']?'Наличные':'' ?><?= (int)$sale['allow_cash'] && (int)$sale['allow_bank']?' / ':'' ?><?= (int)$sale['allow_bank']?'Банк':'' ?></td><td><?= e(marketplace_status_label($sale['status'])) ?></td><td><?php if($sale['status']==='active'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="withdraw_listing"><input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>"><button class="btn btn-ghost btn-small" type="submit">Снять с продажи</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel" style="margin-top:24px"><div class="panel-header"><h2>Заявки покупателей</h2><p>Принятие наличной сделки подтверждает, что вы уже получили деньги</p></div><?php if(!$incoming): ?><div class="panel-body"><p class="muted tiny">Входящих заявок пока нет.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Автомобиль</th><th>Покупатель</th><th>Цена</th><th>Способ</th><th>Статус / ошибка</th><th>Действие</th></tr></thead><tbody><?php foreach($incoming as $p): ?><tr><td><?= e(date('d.m.Y H:i',strtotime($p['created_at']))) ?></td><td><?= e($p['make'].' '.$p['model'].' · '.$p['plate']) ?></td><td><?= e($p['buyer_name']) ?></td><td><?= money($p['amount']) ?></td><td><?= $p['payment_method']==='cash'?'Наличные':'Банковский перевод' ?></td><td><?= e(marketplace_status_label($p['status'])) ?><?php if($p['bank_last_error']): ?><br><span class="muted tiny"><?= e($p['bank_last_error']) ?></span><?php endif; ?></td><td><?php if($p['payment_method']==='cash' && $p['status']==='pending'): ?><form method="post" class="market-action-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="accept_purchase"><input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>"><label class="check-row"><input type="checkbox" name="cash_received" value="1" required> Наличные получены</label><button class="btn btn-primary btn-small" type="submit">Принять и передать</button></form><?php elseif($p['payment_method']==='bank' && in_array($p['status'],['pending','payment_failed','processing'],true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="accept_purchase"><input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-primary btn-small" type="submit"><?= $p['status']==='processing'?'Повторно проверить оплату':'Принять и перевести' ?></button></form><?php if(in_array($p['status'],['pending','payment_failed'],true)): ?><form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reject_purchase"><input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-small" type="submit">Отклонить</button></form><?php endif; ?><?php elseif($p['status']==='pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reject_purchase"><input type="hidden" name="purchase_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ghost btn-small" type="submit">Отклонить</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel" style="margin-top:24px"><div class="panel-header"><h2>Мои покупки</h2><p>Все заявки, которые вы отправляли продавцам</p></div><?php if(!$myPurchases): ?><div class="panel-body"><p class="muted tiny">Вы пока не отправляли заявок на покупку.</p></div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Автомобиль</th><th>Продавец</th><th>Сумма</th><th>Оплата</th><th>Статус</th></tr></thead><tbody><?php foreach($myPurchases as $p): ?><tr><td><?= e(date('d.m.Y H:i',strtotime($p['created_at']))) ?></td><td><?= e($p['make'].' '.$p['model'].' · '.$p['plate']) ?></td><td><?= e($p['seller_name']) ?></td><td><?= money($p['amount']) ?></td><td><?= $p['payment_method']==='cash'?'Наличные':'Банк' ?></td><td><?= e(marketplace_status_label($p['status'])) ?><?php if($p['bank_transaction_reference']): ?><br><span class="muted tiny">Операция: <?= e($p['bank_transaction_reference']) ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const bankCheck = document.getElementById('allow_bank');
  const sellerAccount = document.getElementById('seller_account_number');
  const sellerField = document.getElementById('seller-account-field');
  function sellerAccountState() {
    if (!bankCheck || !sellerAccount || !sellerField) return;
    sellerField.hidden = !bankCheck.checked;
    sellerAccount.required = bankCheck.checked;
  }
  if (bankCheck) { bankCheck.addEventListener('change', sellerAccountState); sellerAccountState(); }
  document.querySelectorAll('.market-buy-form').forEach(form => {
    const method = form.querySelector('.market-payment-method');
    const accountWrap = form.querySelector('.market-source-account');
    const accountSelect = accountWrap ? accountWrap.querySelector('select') : null;
    function updatePaymentFields() {
      const isBank = method && method.value === 'bank';
      if (accountWrap) accountWrap.hidden = !isBank;
      if (accountSelect) accountSelect.required = !!isBank;
    }
    if (method) { method.addEventListener('change', updatePaymentFields); updatePaymentFields(); }
  });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
