<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool { return current_user() !== null; }
function has_role(array|string $roles): bool {
    $user = current_user(); if (!$user) return false;
    return in_array($user['role'], (array)$roles, true);
}
function require_login(): void {
    if (!is_logged_in()) { set_flash('warning', 'Войдите в аккаунт, чтобы открыть этот раздел.'); header('Location: login.php'); exit; }
}
function require_role(array|string $roles): void {
    require_login();
    if (!has_role($roles)) { http_response_code(403); render_error_page(403, 'Недостаточно прав', 'Этот раздел доступен только сотрудникам ГИБДД или администраторам.'); exit; }
}
function csrf_token(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function verify_csrf(): void {
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) { http_response_code(419); exit('Срок действия формы истёк. Обновите страницу и попробуйте ещё раз.'); }
}
function set_flash(string $type, string $message): void { $_SESSION['flash'] = ['type' => $type, 'message' => $message]; }
function take_flash(): ?array { $v = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $v; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function money(mixed $amount): string { return number_format((float)$amount, 2, ',', ' ') . ' ₽'; }
function role_label(string $role): string { return ['user'=>'Владелец','inspector'=>'Инспектор','officer'=>'Сотрудник ГИБДД','admin'=>'Администратор'][$role] ?? 'Пользователь'; }
function status_label(string $status): string {
    return ['active'=>'Активен','issued'=>'Выставлен','paid'=>'Оплачен','cancelled'=>'Отменён','pending'=>'Ожидает отправки','sent'=>'Передан в банк','error'=>'Ошибка отправки','not_configured'=>'Банк не настроен'][$status] ?? $status;
}
function render_error_page(int $code, string $title, string $message): void {
    $page_title = $title;
    include dirname(__DIR__) . '/includes/header.php';
    echo '<section class="panel empty-state"><div class="empty-icon">!</div><h1>'.e($code).' · '.e($title).'</h1><p>'.e($message).'</p><a class="btn btn-primary" href="index.php">На главную</a></section>';
    include dirname(__DIR__) . '/includes/footer.php';
}
function upload_vehicle_photo(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить фотографию.');
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Размер фотографии не должен превышать 5 МБ.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Некорректный файл загрузки.');
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($tmp);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Разрешены только изображения JPG, PNG или WEBP.');
    $dir = dirname(__DIR__) . '/uploads/vehicles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать каталог фотографий.');
    $name = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new RuntimeException('Не удалось сохранить фотографию.');
    return 'uploads/vehicles/' . $name;
}
