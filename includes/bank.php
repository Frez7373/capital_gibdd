<?php
declare(strict_types=1);
function bank_is_configured(array $config): bool {
    return !empty($config['bank']['api_url']) && !empty($config['bank']['api_key']) && !str_contains((string)$config['bank']['api_url'], 'АДРЕС-ВАШЕГО-БАНКА') && !str_contains((string)$config['bank']['api_key'], 'ВСТАВЬТЕ_');
}
/** Отправляет штраф в Capital-Standart. Секрет никогда не возвращается в интерфейс и не пишется в журнал. */
function bank_issue_fine(array $config, array $fine, array $vehicle): array {
    if (!bank_is_configured($config)) return ['ok'=>false, 'status'=>'not_configured', 'message'=>'Не настроен URL API или API-ключ банка.'];
    $payload = [
        'action' => 'issue_fine',
        'customer_email' => (string)$vehicle['owner_email'],
        'external_ref' => (string)$fine['external_ref'],
        'title' => (string)$fine['title'],
        'amount' => number_format((float)$fine['amount'], 2, '.', ''),
        'details' => (string)$fine['details'],
        'vehicle_plate' => (string)$vehicle['plate'],
        'due_date' => (string)$fine['due_date'],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = (string)$config['bank']['api_url'];
    $timeout = max(3, (int)($config['bank']['timeout'] ?? 12));
    $httpCode = 0; $responseBody = ''; $transportError = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-API-Key: ' . $config['bank']['api_key']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $result = curl_exec($ch);
        if ($result === false) $transportError = curl_error($ch);
        else $responseBody = (string)$result;
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST', 'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nX-API-Key: ".$config['bank']['api_key']."\r\n",
            'content'=>$body, 'timeout'=>$timeout, 'ignore_errors'=>true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) $transportError = 'Не удалось подключиться к API банка.';
        else $responseBody = (string)$result;
        foreach (($http_response_header ?? []) as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $httpCode = (int)$m[1];
    }
    $json = json_decode($responseBody, true);
    $apiError = is_array($json) ? (string)($json['error'] ?? '') : '';
    $apiMessage = is_array($json) ? (string)($json['message'] ?? '') : '';
    $explicitFailure = is_array($json) && (($json['success'] ?? null) === false || in_array(strtolower((string)($json['status'] ?? '')), ['error','failed','failure'], true));
    $ok = $transportError === '' && $httpCode >= 200 && $httpCode < 300 && !$explicitFailure && $apiError === '';
    $message = $ok ? 'Штраф передан в банк.' : ($transportError ?: ($apiError ?: ($apiMessage ?: ('Банк вернул HTTP ' . $httpCode . '.'))));
    return ['ok'=>$ok, 'status'=>$ok ? 'sent' : 'error', 'message'=>mb_substr($message, 0, 500), 'http_code'=>$httpCode, 'response'=>$responseBody];
}
function bank_log(PDO $pdo, int $fineId, array $result): void {
    $stmt = $pdo->prepare('INSERT INTO bank_api_logs (fine_id, outcome, http_code, response_excerpt, created_at) VALUES (?, ?, ?, ?, NOW())');
    $excerpt = mb_substr((string)($result['response'] ?? $result['message'] ?? ''), 0, 1500);
    $stmt->execute([$fineId, (string)($result['status'] ?? 'error'), (int)($result['http_code'] ?? 0), $excerpt]);
}
function dispatch_fine_to_bank(PDO $pdo, array $config, int $fineId): array {
    $stmt = $pdo->prepare('SELECT f.*, v.plate, v.owner_email FROM fines f JOIN vehicles v ON v.id=f.vehicle_id WHERE f.id=?');
    $stmt->execute([$fineId]); $row = $stmt->fetch();
    if (!$row) return ['ok'=>false,'status'=>'error','message'=>'Штраф не найден.'];
    $result = bank_issue_fine($config, $row, $row);
    $upd = $pdo->prepare('UPDATE fines SET bank_status=?, bank_last_error=?, bank_sent_at=IF(?, NOW(), bank_sent_at) WHERE id=?');
    $upd->execute([$result['status'], $result['ok'] ? null : $result['message'], $result['ok'] ? 1 : 0, $fineId]);
    bank_log($pdo, $fineId, $result);
    return $result;
}


/** Calls the bank's authenticated partner API from the server only. */
function bank_partner_request(array $config, array $payload): array {
    if (!bank_is_configured($config)) {
        return ['ok'=>false,'uncertain'=>false,'message'=>'Не настроена интеграция с банком.'];
    }
    $url = (string)$config['bank']['api_url'];
    $timeout = max(3, (int)($config['bank']['timeout'] ?? 12));
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $httpCode = 0;
    $responseBody = '';
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . (string)$config['bank']['api_key'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $result = curl_exec($ch);
        if ($result === false) $transportError = curl_error($ch);
        else $responseBody = (string)$result;
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST',
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nX-API-Key: " . (string)$config['bank']['api_key'] . "\r\n",
            'content'=>$body,
            'timeout'=>$timeout,
            'ignore_errors'=>true,
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) $transportError = 'Не удалось подключиться к API банка.';
        else $responseBody = (string)$result;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $httpCode = (int)$m[1];
        }
    }

    $data = json_decode($responseBody, true);
    $validJson = is_array($data);
    $success = $validJson && (($data['success'] ?? null) === true || ($data['ok'] ?? null) === true);
    $ok = $transportError === '' && $httpCode >= 200 && $httpCode < 300 && $success;
    $message = $ok
        ? (string)($data['message'] ?? 'Запрос успешно выполнен.')
        : ($transportError ?: ($validJson
            ? (string)($data['message'] ?? $data['error'] ?? ('Банк вернул HTTP ' . $httpCode . '.'))
            : 'Банк вернул некорректный ответ.'));
    $uncertain = $transportError !== '' || $httpCode === 0 || $httpCode >= 500 || ($httpCode >= 200 && $httpCode < 300 && !$validJson);
    return [
        'ok'=>$ok,
        'uncertain'=>$uncertain,
        'message'=>mb_substr($message, 0, 500),
        'http_code'=>$httpCode,
        'data'=>$validJson ? $data : [],
    ];
}

/** Returns active bank accounts for the customer's verified email. */
function bank_list_accounts(array $config, string $email): array {
    $result = bank_partner_request($config, [
        'action'=>'list_accounts',
        'customer_email'=>$email,
    ]);
    if (!$result['ok']) return ['ok'=>false,'message'=>$result['message'],'accounts'=>[]];
    $accounts = $result['data']['accounts'] ?? null;
    if (!is_array($accounts)) return ['ok'=>false,'message'=>'Банк не вернул список счетов.','accounts'=>[]];
    return ['ok'=>true,'message'=>'','accounts'=>$accounts];
}

/** Initiates an idempotent buyer-to-seller transfer for an accepted vehicle purchase.
 * Supports both the current transfer contract and older Capital-Standart partner API.
 */
function bank_vehicle_purchase_transfer(array $config, array $purchase, array $sale): array {
    $amount = number_format((float)$purchase['amount'], 2, '.', '');
    $externalRef = (string)$purchase['bank_external_ref'];

    // Newer bank API: generic transfer action and standard account-number field names.
    $result = bank_partner_request($config, [
        'action'=>'transfer',
        'from_account_number'=>(string)$purchase['source_account_number'],
        'to_account_number'=>(string)$sale['seller_account_number'],
        'amount'=>$amount,
        'external_ref'=>$externalRef,
        'description'=>'Оплата автомобиля через АвтоКонтроль',
    ]);
    if ($result['ok']) return $result;

    // Fallback only when the bank explicitly says this action is unsupported.
    // Never retry a timeout, validation failure, low balance, or uncertain result as another action.
    $errorCode = strtolower((string)($result['data']['error'] ?? ''));
    if (!in_array($errorCode, ['unknown_action', 'unsupported_action', 'action_not_supported'], true)) {
        return $result;
    }

    // Legacy Capital-Standart API: preserve compatibility with existing installations.
    return bank_partner_request($config, [
        'action'=>'vehicle_purchase_transfer',
        'buyer_email'=>(string)$purchase['buyer_email'],
        'seller_email'=>(string)$purchase['seller_email'],
        'source_account_number'=>(string)$purchase['source_account_number'],
        'destination_account_number'=>(string)$sale['seller_account_number'],
        'amount'=>$amount,
        'external_ref'=>$externalRef,
    ]);
}
