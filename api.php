<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Webhook-Secret');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function normalizeMdtItem(array $item): array {
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

    if (!isset($normalized['is_wanted'])) {
        $normalized['is_wanted'] = ($normalized['status'] === 'warrant');
    }
    if (!isset($normalized['has_id_card'])) {
        $normalized['has_id_card'] = $normalized['ssn'] !== 'Brak' && $normalized['name'] !== 'Nieznane';
    }

    return $normalized;
}

$baseDir = __DIR__;
$dataDir = $baseDir . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

$storeFile = $dataDir . '/mdt_webhook.json';
$records = [];
if (file_exists($storeFile)) {
    $raw = file_get_contents($storeFile);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (isset($decoded['records']) && is_array($decoded['records'])) {
                $records = $decoded['records'];
            } else {
                $records = $decoded;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $incoming = json_decode($raw, true);
    $payload = $incoming;
    if (is_array($incoming) && isset($incoming['payload'])) {
        $payload = $incoming['payload'];
    }

    $items = [];
    if (is_array($payload)) {
        if (isset($payload['records']) && is_array($payload['records'])) {
            $items = $payload['records'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $items = $payload['data'];
        } elseif (isset($payload['person']) && is_array($payload['person'])) {
            $items = [$payload['person']];
        } else {
            $items = [$payload];
        }
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized[] = normalizeMdtItem($item);
    }

    if (!empty($normalized)) {
        $records = $normalized;
        file_put_contents($storeFile, json_encode(['records' => $records, 'updated_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    echo json_encode([
        'status' => 'ok',
        'event' => is_array($incoming) ? ($incoming['event'] ?? 'snapshot') : 'snapshot',
        'received' => count($normalized),
        'records' => count($records),
    ]);
    exit;
}

if (isset($_GET['health']) || isset($_GET['status'])) {
    echo json_encode(['status' => 'ok', 'records' => count($records)]);
    exit;
}

$ssnQ = strtolower(trim((string)($_GET['ssn'] ?? '')));
$nameQ = strtolower(trim((string)($_GET['name'] ?? '')));

$filtered = [];
foreach ($records as $item) {
    if (!is_array($item)) {
        continue;
    }

    $ssn = strtolower((string)($item['ssn'] ?? ''));
    $name = strtolower((string)($item['name'] ?? ''));

    if ($ssnQ !== '' && strpos($ssn, $ssnQ) === false) {
        continue;
    }
    if ($nameQ !== '' && strpos($name, $nameQ) === false) {
        continue;
    }

    $filtered[] = $item;
}

if (!empty($_GET['debug'])) {
    echo json_encode([
        'count' => count($filtered),
        'source' => $storeFile,
        'query' => ['ssn' => $ssnQ, 'name' => $nameQ],
        'records' => $filtered,
    ]);
    exit;
}

echo json_encode($filtered);
