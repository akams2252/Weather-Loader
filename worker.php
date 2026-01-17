<?php
// weather/worker.php
// in console php /var/www/html/weather/worker.php

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

function toMySqlDatetime6(string $iso): string {
  // ISO: 2025-12-27T12:30Z lub 2025-12-27T12:30:46.333Z
  // Konwertujemy do DATETIME(6) w UTC
  $dt = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
  return $dt->format('Y-m-d H:i:s.u'); // ms
}

function failToDlq(PDO $pdo, int $id, string $payloadJson, string $error): void {
  $stmt = $pdo->prepare("INSERT INTO queue_deadletter (payload_json, error_message) VALUES (:p, :e)");
  $stmt->execute([':p' => $payloadJson, ':e' => mb_substr($error, 0, 255)]);
  $pdo->prepare("DELETE FROM queue_messages WHERE id=:id")->execute([':id' => $id]);
}

$pdo = pdoFromConfig($config);

// fetchAll czyli pobieranie
$rows = $pdo->query("SELECT id, payload_json, attempts FROM queue_messages WHERE status='NEW' ORDER BY id ASC LIMIT 50")->fetchAll();

if (!$rows) {
  echo "[Worker] Brak wiadomości NEW.\n";
  exit;
}

echo "[Worker] Found " . count($rows) . " messages.\n";

$processed = 0;
$toDlq = 0;

foreach ($rows as $r) {
  $id = (int)$r['id'];
  $payloadJson = (string)$r['payload_json'];
  $attempts = (int)$r['attempts'];

  try {
    //  PROCESSING
    $pdo->prepare("UPDATE queue_messages SET status='PROCESSING', attempts=attempts+1, locked_at=NOW() WHERE id=:id AND status='NEW'")
        ->execute([':id' => $id]);

    // READ
    $cur = $pdo->prepare("SELECT id, status, payload_json, attempts FROM queue_messages WHERE id=:id");
    $cur->execute([':id'=>$id]);
    $current = $cur->fetch();
    if (!$current || $current['status'] !== 'PROCESSING') {
      // ktoś inny już wziął
      continue;
    }

    $p = json_decode($payloadJson, true);
    if (!is_array($p)) {
      throw new RuntimeException("payload_json is not valid JSON");
    }

    foreach (['Measurement','Location','Value','Timestamp','Checksum'] as $k) {
      if (!array_key_exists($k, $p)) {
        throw new RuntimeException("missing field: {$k}");
      }
    }

    $measurement = (string)$p['Measurement'];
    $location = (string)$p['Location'];
    $value = (float)$p['Value'];
    $timestampIso = (string)$p['Timestamp'];
    $checksum = (string)$p['Checksum'];

    // Konwersja czasu do DATETIME(6)
    $ts = toMySqlDatetime6($timestampIso);

    // Zapis do tabeli docelowej
    $stmt = $pdo->prepare("
      INSERT INTO weather_measurements (measurement, location, value, ts, checksum)
      VALUES (:m, :l, :v, :ts, :c)
    ");
    $stmt->execute([
      ':m' => $measurement,
      ':l' => $location,
      ':v' => $value,
      ':ts'=> $ts,
      ':c' => $checksum,
    ]);

    // Usuń z kolejki po sukcesie
    $pdo->prepare("DELETE FROM queue_messages WHERE id=:id")->execute([':id' => $id]);

    $processed++;
    echo "[Worker] OK id={$id} saved.\n";
  } catch (Throwable $e) {
    $attemptsNow = $attempts + 1;

    // po 3 próbach -> DLQ
    if ($attemptsNow >= ($config['max_attempts'] ?? 3)) {
      $toDlq++;
      failToDlq($pdo, $id, $payloadJson, $e->getMessage());
      echo "[Worker] DLQ id={$id}: " . $e->getMessage() . "\n";
    } else {
      // wróć do NEW
      $pdo->prepare("UPDATE queue_messages SET status='NEW' WHERE id=:id")->execute([':id'=>$id]);
      echo "[Worker] RETRY id={$id}: " . $e->getMessage() . "\n";
    }
  }
}

echo "[Worker] Done. processed={$processed}, dlq={$toDlq}\n";
