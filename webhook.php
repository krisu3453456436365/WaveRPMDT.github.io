<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Webhook-Secret');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function normalizeWebhookItem(array $item): array {
    $name = trim((string)($item['name'] ?? $item['full_name'] ?? $item['player_name'] ?? $item['imie_nazwisko'] ?? ''));
    $ssn = trim((string)($item['ssn'] ?? $item['social_security_number'] ?? $item['identifier'] ?? $item['pesel'] ?? ''));
    $dob = trim((string)($item['dob'] ?? $item['date_of_birth'] ?? $item['birth_date'] ?? $item['data_urodzenia'] ?? ''));
    $address = trim((string)($item['address'] ?? $item['home_address'] ?? $item['street'] ?? $item['adres'] ?? ''));
    $status = trim((string)($item['status'] ?? ($item['warrant'] ?? false ? 'warrant' : 'clear')));
    $note = trim((string)($item['note'] ?? $item['notes'] ?? $item['summary'] ?? ''));

    $normalized = $item;
    $normalized['name'] = $name !== '' ? $name : 'Nieznane';
    $normalized['ssn'] = $ssn !== '' ? $ssn : 'Brak';
    $normalized['dob'] = $dob !== '' ? $dob : 'Brak danych';
    $normalized['address'] = $address !== '' ? $address : 'Adres nieznany';
    $normalized['status'] = $status !== '' ? $status : 'clear';
    $normalized['note'] = $note !== '' ? $note : 'Brak danych';
    $normalized['updated_at'] = $item['updated_at'] ?? $item['timestamp'] ?? $item['generated_at'] ?? date('c');
    $normalized['is_wanted'] = $normalized['status'] === 'warrant';
    $normalized['has_id_card'] = $normalized['ssn'] !== 'Brak' && $normalized['name'] !== 'Nieznane';

    return $normalized;
}

$baseDir = __DIR__;
$dataDir = $baseDir . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$storeFile = $dataDir . '/mdt_webhook.json';
$expectedSecret = getenv('MDT_WEBHOOK_SECRET') ?: 'waverpmdt-secret-2026';

$raw = file_get_contents('php://input');
$decoded = json_decode($raw, true);
$receivedSecret = null;
if (is_array($decoded) && isset($decoded['secret'])) {
    $receivedSecret = (string)$decoded['secret'];
}
if ($receivedSecret === null) {
    $receivedSecret = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
}

if ($expectedSecret !== '' && $receivedSecret !== '' && $receivedSecret !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook secret']);
    exit;
}

$payload = is_array($decoded) && isset($decoded['payload']) ? $decoded['payload'] : $decoded;
$event = is_array($decoded) ? ($decoded['event'] ?? 'snapshot') : 'snapshot';

$items = [];
if (is_array($payload)) {
    if (isset($payload['records']) && is_array($payload['records'])) {
        $items = $payload['records'];
    } elseif (isset($payload['data']) && is_array($payload['data'])) {
        $items = $payload['data'];
    } else {
        $items = [$payload];
    }
}

$normalized = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $normalized[] = normalizeWebhookItem($item);
}

if (!empty($normalized)) {
    $records = $normalized;
    file_put_contents($storeFile, json_encode([
        'event' => $event,
        'updated_at' => date('c'),
        'records' => $records,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode([
    'status' => 'ok',
    'event' => $event,
    'received' => count($normalized),
    'stored' => file_exists($storeFile) ? count($normalized) : 0,
]);
