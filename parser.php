<?php
// /weather/parser.php build mscode?v12
header('Content-Type: text/html; charset=utf-8');

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

function verify_checksum(array $p, string $secret, ?string &$expected = null, ?string &$canonical = null): bool {
  $canonical = canonical_string($p);
  $expected = compute_checksum($canonical, $secret);
  if (!isset($p['Checksum'])) return false;
  return hash_equals($expected, (string)$p['Checksum']);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tryParseTimestamp(?string $ts): ?DateTimeImmutable {
  if (!$ts) return null;
  try {
    // Timestamp jest w ISO, np. 2025-12-27T12:30Z
    return new DateTimeImmutable($ts);
  } catch (Throwable $e) {
    return null;
  }
}

function prettyTemp(?float $v): array {
  if ($v === null || !is_finite($v)) return ['raw' => '—', 'round' => '—'];
  $round = (string)round($v);                 // do pełnych stopni
  $raw = number_format($v, 1, '.', '');       // 1 miejsce po przecinku
  return ['raw' => $raw, 'round' => $round];
}

// ---------- DB read ----------
$pdo = null;
$dbError = null;
$queue = $meas = $dlq = [];
try {
  $pdo = pdoFromConfig($config);
  $queue = $pdo->query("SELECT id, status, attempts, created_at, payload_json FROM queue_messages ORDER BY id DESC LIMIT 10")->fetchAll();
  $meas  = $pdo->query("SELECT id, measurement, location, value, ts, checksum, inserted_at FROM weather_measurements ORDER BY id DESC LIMIT 10")->fetchAll();
  $dlq   = $pdo->query("SELECT id, error_message, created_at, payload_json FROM queue_deadletter ORDER BY id DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {
  $dbError = $e->getMessage();
}

// ---------- Fetch from API ----------
$city = trim($_GET['city'] ?? 'Cieszyn');
$apiJson = null;
$apiError = null;

if (isset($_GET['do_fetch'])) {
  $url = "http://localhost/weather/weather.php?action=fetch&city=" . urlencode($city) . "&t=" . time();
  // relative
  $ctx = stream_context_create([
    'http' => ['timeout' => 8, 'header' => "User-Agent: WeatherParser\r\n"]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) {
    $apiError = "Nie udało się pobrać danych z weather.php?action=fetch (sprawdź ścieżkę / uprawnienia / allow_url_fopen).";
  } else {
    $j = json_decode($raw, true);
    if (!is_array($j)) $apiError = "Odpowiedź nie jest JSON: " . $raw;
    else $apiJson = $j;
  }
}

// ---------- Verify payload input ----------
$checkResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode = $_POST['mode'] ?? 'json';
  $input = trim($_POST['input'] ?? '');

  if ($input !== '') {
    try {
      if ($mode === 'base64') {
        $decoded = base64_decode($input, true);
        if ($decoded === false) throw new RuntimeException("Niepoprawny Base64.");
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) throw new RuntimeException("Base64 nie dekoduje się do JSON.");
      } else {
        $payload = json_decode($input, true);
        if (!is_array($payload)) throw new RuntimeException("Niepoprawny JSON.");
      }

      $expected = $canonical = null;
      $ok = verify_checksum($payload, $config['secret'], $expected, $canonical);

      $dtUtc = tryParseTimestamp($payload['Timestamp'] ?? null);
      $dtPl = $dtUtc ? $dtUtc->setTimezone(new DateTimeZone('Europe/Warsaw')) : null;

      $temp = isset($payload['Value']) ? (float)$payload['Value'] : null;
      $tp = prettyTemp($temp);

      $checkResult = [
        'ok' => $ok,
        'payload' => $payload,
        'canonical' => $canonical,
        'expected' => $expected,
        'human' => [
          'measurement' => $payload['Measurement'] ?? '—',
          'location' => $payload['Location'] ?? '—',
          'temp_raw' => $tp['raw'],
          'temp_round' => $tp['round'],
          'time_utc' => $dtUtc ? $dtUtc->format('Y-m-d H:i:s') . " UTC" : '—',
          'time_pl' => $dtPl ? $dtPl->format('Y-m-d H:i:s') . " (Europe/Warsaw)" : '—',
        ]
      ];
    } catch (Throwable $e) {
      $checkResult = ['error' => $e->getMessage()];
    }
  }
}

?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Weather Loader – Parser</title>
  <link rel="icon" href="https://i.imgur.com/uZsSNKu.png" type="image/x-icon"/>
  <style>
    :root{
      --bg:#f6f7fb; --card:#fff; --text:#121826; --muted:#5b6475;
      --border:#e6e9f2; --shadow:0 10px 30px rgba(16,24,40,.08);
      --shadow2:0 2px 10px rgba(16,24,40,.06);
      --ok:#16a34a; --bad:#dc2626; --accent:#2563eb; --codebg:#f1f5ff;
    }
    *{box-sizing:border-box}
    body{margin:0; padding:22px; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; background:var(--bg); color:var(--text);}
    .card{max-width:1100px; margin:0 auto; background:var(--card); border:1px solid var(--border); border-radius:18px; box-shadow:var(--shadow); padding:18px;}
    h1{margin:0 0 12px; font-size:28px; letter-spacing:-.02em;}
    h2{margin:18px 0 10px; font-size:18px;}
    .muted{color:var(--muted); font-size:13px; line-height:1.45;}
    .row{display:flex; gap:12px; flex-wrap:wrap;}
    .box{flex:1 1 320px; border:1px solid var(--border); border-radius:14px; padding:12px; box-shadow:var(--shadow2); background:#fff;}
    input,select,textarea{width:100%; padding:10px; border-radius:12px; border:1px solid var(--border); box-shadow:var(--shadow2); outline:none;}
    input:focus,textarea:focus{border-color:rgba(37,99,235,.45); box-shadow:0 0 0 4px rgba(37,99,235,.12);}
    button{padding:10px 14px; border-radius:12px; border:1px solid var(--border); cursor:pointer; font-weight:800; background:#fff; box-shadow:var(--shadow2);}
    button.primary{background:var(--accent); color:#fff; border-color:rgba(37,99,235,.35);}
    code{background:var(--codebg); border:1px solid #dbe7ff; padding:2px 8px; border-radius:999px; color:#0b2a6a; font-size:12.5px;}
    pre{background:#fbfcff; border:1px solid var(--border); padding:12px; border-radius:14px; overflow:auto; box-shadow:var(--shadow2); white-space:pre-wrap;}
    table{width:100%; border-collapse:collapse; font-size:13px;}
    th,td{border-bottom:1px solid var(--border); padding:8px; vertical-align:top;}
    .ok{color:var(--ok); font-weight:900;}
    .bad{color:var(--bad); font-weight:900;}
    a{color:var(--accent); text-decoration:none;}
    a:hover{text-decoration:underline;}
  </style>
</head>
<body>
  <div class="card">
    <h1>Parser / Panel weryfikacji</h1>
    <div class="muted">
      Ten panel służy do sprawdzenia działania projektu: pobrania pogody z API, poprawności checksum oraz przepływu danych przez kolejkę i bazę.
    </div>

    <h2>1) Czytelne pobranie pogody (API)</h2>
    <form method="get" class="row" style="align-items:flex-end;">
      <div class="box">
        <div class="muted">Miasto</div>
        <input name="city" value="<?=h($city)?>" />
        <div class="muted" style="margin-top:8px;">
          Pobiera dane z <code>weather.php?action=fetch</code> i pokazuje je w formie czytelnej (temperatura + czas UTC i PL).
        </div>
      </div>
      <div style="min-width:180px;">
        <input type="hidden" name="do_fetch" value="1" />
        <button class="primary" type="submit">Pobierz pogodę</button>
      </div>
    </form>

    <?php if ($apiError): ?>
      <div class="box" style="margin-top:12px;">
        <div class="bad">Błąd pobierania: <?=h($apiError)?></div>
        <div class="muted" style="margin-top:6px;">
          Jeśli to się pojawi, zamień w kodzie parsera URL na względny: <code>weather.php?action=fetch...</code>.
        </div>
      </div>
    <?php elseif ($apiJson): ?>
      <?php
        $dtUtc = tryParseTimestamp($apiJson['Timestamp'] ?? null);
        $dtPl = $dtUtc ? $dtUtc->setTimezone(new DateTimeZone('Europe/Warsaw')) : null;
        $temp = isset($apiJson['Value']) ? (float)$apiJson['Value'] : null;
        $tp = prettyTemp($temp);
      ?>
      <div class="row" style="margin-top:12px;">
        <div class="box">
          <div class="muted">Lokalizacja</div>
          <div style="font-size:20px; font-weight:900;"><?=h($apiJson['Location'] ?? '—')?></div>
        </div>
        <div class="box">
          <div class="muted">Temperatura</div>
          <div style="font-size:20px; font-weight:900;"><?=h($tp['raw'])?> °C</div>
          <div class="muted">Zaokrąglona: <?=h($tp['round'])?> °C</div>
        </div>
        <div class="box">
          <div class="muted">Czas</div>
          <div><b>UTC:</b> <?=h($dtUtc ? $dtUtc->format('Y-m-d H:i:s') : '—')?> UTC</div>
          <div><b>PL:</b> <?=h($dtPl ? $dtPl->format('Y-m-d H:i:s') : '—')?> (Europe/Warsaw)</div>
        </div>
      </div>

      <div class="box" style="margin-top:12px;">
        <div class="muted">Surowy JSON (dla porównania)</div>
        <pre><?=h(json_encode($apiJson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></pre>
      </div>
    <?php endif; ?>

    <h2>2) Weryfikacja wiadomości (Base64/JSON → bardziej czytelne)</h2>
    <form method="post">
      <div class="row" style="align-items:flex-end;">
        <div class="box">
          <div class="muted">Tryb wejścia</div>
          <select name="mode">
            <option value="json" <?= (($_POST['mode'] ?? '')==='json'?'selected':'') ?>>JSON</option>
            <option value="base64" <?= (($_POST['mode'] ?? '')==='base64'?'selected':'') ?>>Base64 (zakodowany JSON)</option>
          </select>
          <div class="muted" style="margin-top:8px;">
            Wklej payload z aplikacji (JSON lub Base64) i sprawdź, czy checksum jest poprawny.
          </div>
        </div>
        <div style="min-width:180px;">
          <button class="primary" type="submit">Sprawdź</button>
        </div>
      </div>

      <div style="margin-top:12px;">
        <textarea name="input" rows="8" placeholder='{"Measurement":"temperature","Location":"Cieszyn","Value":0.4,"Timestamp":"2025-12-27T12:30Z","Checksum":"..."}'><?=h($_POST['input'] ?? '')?></textarea>
      </div>
    </form>

    <?php if ($checkResult): ?>
      <h2>Wynik weryfikacji</h2>
      <?php if (isset($checkResult['error'])): ?>
        <div class="box"><div class="bad">Błąd: <?=h($checkResult['error'])?></div></div>
      <?php else: ?>
        <div class="row">
          <div class="box">
            <div class="muted">Status checksum</div>
            <div class="<?= $checkResult['ok'] ? 'ok' : 'bad' ?>">
              <?= $checkResult['ok'] ? 'OK – wiadomość integralna' : 'BŁĄD – checksum niezgodny' ?>
            </div>
            <div class="muted" style="margin-top:8px;">To jest wynik po stronie serwera (wiarygodny).</div>
          </div>
          <div class="box">
            <div class="muted">Dane (czytelne)</div>
            <div><b>Measurement:</b> <?=h($checkResult['human']['measurement'])?></div>
            <div><b>Location:</b> <?=h($checkResult['human']['location'])?></div>
            <div><b>Temperatura:</b> <?=h($checkResult['human']['temp_raw'])?> °C (zaokr.: <?=h($checkResult['human']['temp_round'])?> °C)</div>
            <div><b>Czas UTC:</b> <?=h($checkResult['human']['time_utc'])?></div>
            <div><b>Czas PL:</b> <?=h($checkResult['human']['time_pl'])?></div>
          </div>
        </div>

        <div class="box" style="margin-top:12px;">
          <div class="muted">Canonical string</div>
          <pre><?=h($checkResult['canonical'])?></pre>
          <div class="muted">Expected checksum (server-side)</div>
          <pre><?=h($checkResult['expected'])?></pre>
          <div class="muted">Payload (zdekodowany)</div>
          <pre><?=h(json_encode($checkResult['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?></pre>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <h2>3) Podgląd przepływu danych (kolejka → worker → baza)</h2>
    <?php if ($dbError): ?>
      <div class="box"><div class="bad">Błąd bazy: <?=h($dbError)?></div></div>
    <?php else: ?>
      <div class="muted">
        Kolejka: <code>queue_messages</code> • Docelowe dane: <code>weather_measurements</code> • DLQ: <code>queue_deadletter</code><br/>
        Worker uruchamiasz: <code>php /var/www/html/weather/worker.php</code>
      </div>

      <h2 style="margin-top:14px;">Kolejka (ostatnie 10)</h2>
      <table>
        <tr><th>ID</th><th>Status</th><th>Attempts</th><th>Created</th><th>Payload</th></tr>
        <?php foreach ($queue as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td><?=h($r['status'])?></td>
            <td><?=h($r['attempts'])?></td>
            <td><?=h($r['created_at'])?></td>
            <td><pre><?=h($r['payload_json'])?></pre></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <h2>Dane zapisane (ostatnie 10)</h2>
      <table>
        <tr><th>ID</th><th>Measurement</th><th>Location</th><th>Value</th><th>TS</th><th>Inserted</th></tr>
        <?php foreach ($meas as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td><?=h($r['measurement'])?></td>
            <td><?=h($r['location'])?></td>
            <td><?=h(number_format((float)$r['value'], 1, '.', ''))?></td>
            <td><?=h($r['ts'])?></td>
            <td><?=h($r['inserted_at'])?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <h2>DLQ (ostatnie 10)</h2>
      <table>
        <tr><th>ID</th><th>Error</th><th>Created</th><th>Payload</th></tr>
        <?php foreach ($dlq as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td><?=h($r['error_message'])?></td>
            <td><?=h($r['created_at'])?></td>
            <td><pre><?=h($r['payload_json'])?></pre></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <h2>4) Szybkie linki</h2>
    <div class="muted">
      Aplikacja: <a href="/weather/" target="_blank">/weather/</a><br/>
      API fetch: <a href="/weather/weather.php?action=fetch&city=Cieszyn&t=<?=time()?>" target="_blank">/weather/weather.php?action=fetch&city=Cieszyn</a>
    </div>
  </div>
</body>
</html>
