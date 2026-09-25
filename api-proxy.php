<?php
// Proxy do API ERLC v2 - dodaje nagłówek server-key
// Użycie: api-proxy.php?endpoint=/players (GET) lub api-proxy.php?endpoint=/command (POST)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '/players';
$api_domain = getenv('ERLC_API_DOMAIN') ?: 'https://api.erlc.gg';
$api_key = getenv('ERLC_API_KEY') ?: 'BcULYXDaOFOAqsyfuFiG-sfBaApJjJzPHZYBGxCrzPRgZtQskMryBTKxdJNtI';

// Dla /players pobieramy z v2 z Players=true, dla innych endpointów v1
if ($endpoint === '/players' || $endpoint === '') {
    $url = "$api_domain/v2/server?Players=true";
} elseif ($endpoint === '/command') {
    $url = "$api_domain/v2/server/command";
} else {
    $url = "$api_domain/v1/server" . $endpoint;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Server-Key: $api_key",
    "Accept: application/json"
));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    $post_data = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Server-Key: $api_key",
        "Content-Type: application/json",
        "Accept: application/json"
    ));
}

$response = curl_exec($ch);
if ($response === false) {
    $curl_error = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    echo json_encode(["error" => "Proxy request failed", "details" => $curl_error]);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($http_code);
echo $response;
?>
