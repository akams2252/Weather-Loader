<?php
// weather.php build mscode?v12
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

function pdoFromConfig(array $config): PDO {
  $dsn = sprintf(
    "mysql:host=%s;dbname=%s;charset=%s",
    $config['db']['host'],
    $config['db']['name'],
    $config['db']['charset']
  );
  return new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

function canonical_string(array $p): string {
  return ($p['Measurement'] ?? '') . '|'
    . ($p['Location'] ?? '') . '|'
    . (string)($p['Value'] ?? '') . '|'
    . ($p['Timestamp'] ?? '');
}

function compute_checksum(string $canonical, string $secret): string {
  $raw = hash_hmac('sha256', $canonical, $secret, true);
  return base64_encode($raw);
}

function verify_checksum(array $p, string $secret): bool {
  if (!isset($p['Checksum'])) return false;
  $expected = compute_checksum(canonical_string($p), $secret);
  return hash_equals($expected, (string)$p['Checksum']);
}

// GET: pobranie pogody
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch') {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $city = trim($_GET['city'] ?? 'Cieszyn');
  if ($city === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing city']);
    exit;
  }

  $getJson = function(string $url): array {
    $ctx = stream_context_create([
      'http' => ['timeout' => 8, 'header' => "User-Agent: WeatherLoader\r\n"]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("Fetch failed");
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new RuntimeException("Invalid JSON");
    return $j;
  };

  try {
    // 1) geocoding miasta
    $geoUrl = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($city) . "&count=1&language=pl&format=json";
    $geo = $getJson($geoUrl);

    if (empty($geo['results'][0])) {
      http_response_code(404);
      echo json_encode(['error' => 'City not found']);
      exit;
    }

    $r = $geo['results'][0];
    $lat = $r['latitude'];
    $lon = $r['longitude'];
    $loc = $r['name'];

    // 2) aktualna pogoda
    $wUrl = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=temperature_2m&timezone=UTC";
    $w = $getJson($wUrl);

    $temp = $w['current']['temperature_2m'] ?? null;
    $time = $w['current']['time'] ?? gmdate('c');

    if ($temp === null) throw new RuntimeException("No temperature");

    echo json_encode([
      'Location' => $loc,
      'Value' => (float)$temp,
      'Timestamp' => $time . "Z"
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// POST: do kolejki
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['DataBase64'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid request. Expected { "DataBase64": "..." }']);
  exit;
}

$decoded = base64_decode((string)$data['DataBase64'], true);
if ($decoded === false) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid base64']);
  exit;
}

$payload = json_decode($decoded, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['error' => 'Decoded base64 is not JSON']);
  exit;
}

foreach (['Measurement','Location','Value','Timestamp','Checksum'] as $k) {
  if (!array_key_exists($k, $payload)) {
    http_response_code(400);
    echo json_encode(['error' => "Missing field: $k"]);
    exit;
  }
}

if (!verify_checksum($payload, $config['secret'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Checksum invalid']);
  exit;
}

try {
  $pdo = pdoFromConfig($config);
  $stmt = $pdo->prepare("INSERT INTO queue_messages (payload_json) VALUES (:p)");
  $stmt->execute([':p' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
  echo json_encode(['ok' => true, 'queued' => true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'DB error', 'details' => $e->getMessage()]);
}
