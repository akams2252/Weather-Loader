<?php
// tests.php v=?beta-mscode
// run php /var/www/html/weather/tests.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/config.php';
$secret = $config['secret'] ?? 'ThisIsASecretKey';

$baseUrl = 'http://127.0.0.1/weather/weather.php';
@mkdir(__DIR__ . '/build', 0777, true);

$results = [];
$failures = 0;

function ok($name, $cond, $details = '') {
  global $results, $failures;
  $results[] = ['name'=>$name, 'ok'=>(bool)$cond, 'details'=>$details];
  if (!$cond) $failures++;
}

function canonical(array $p): string {
  return ($p['Measurement'] ?? '') . '|' . ($p['Location'] ?? '') . '|' . (string)($p['Value'] ?? '') . '|' . ($p['Timestamp'] ?? '');
}

function checksum(string $canonical, string $secret): string {
  return base64_encode(hash_hmac('sha256', $canonical, $secret, true));
}

function parse_http_code_from_headers(array $headers): int {
  if (isset($headers[0]) && preg_match('~HTTP/\S+\s(\d{3})~', $headers[0], $m)) {
    return (int)$m[1];
  }
  return 0;
}

function http_get(string $url): array {
  $ctx = stream_context_create([
    'http'=>[
      'timeout'=>8,
      'ignore_errors'=>true,
      'header'=>"User-Agent: tests.php\r\n"
    ]
  ]);

  $body = @file_get_contents($url, false, $ctx);

  $headers = $http_response_header ?? [];
  $code = parse_http_code_from_headers($headers);

  if ($code === 0 && $body !== false && $body !== '') $code = 200;

  return ['code'=>$code, 'body'=>$body !== false ? $body : '', 'headers'=>$headers];
}

function http_post_json(string $url, array $data): array {
  $payload = json_encode($data);

  $ctx = stream_context_create([
    'http'=>[
      'method'=>'POST',
      'timeout'=>8,
      'ignore_errors'=>true,
      'header'=>"Content-Type: application/json\r\nUser-Agent: tests.php\r\n",
      'content'=>$payload
    ]
  ]);

  $body = @file_get_contents($url, false, $ctx);

  $headers = $http_response_header ?? [];
  $code = parse_http_code_from_headers($headers);

  if ($code === 0 && $body !== false && $body !== '') $code = 200;

  return ['code'=>$code, 'body'=>$body !== false ? $body : '', 'headers'=>$headers];
}

function make_junit(array $results, string $file): void {
  $tests = count($results);
  $fails = count(array_filter($results, fn($r)=>!$r['ok']));

  $xml = new SimpleXMLElement('<testsuite/>');
  $xml->addAttribute('name', 'WeatherLoaderSimpleTests');
  $xml->addAttribute('tests', (string)$tests);
  $xml->addAttribute('failures', (string)$fails);
  $xml->addAttribute('time', "0");

  foreach ($results as $r) {
    $tc = $xml->addChild('testcase');
    $tc->addAttribute('name', $r['name']);
    $tc->addAttribute('time', "0");
    if (!$r['ok']) {
      $f = $tc->addChild('failure', htmlspecialchars($r['details'] ?: 'failed', ENT_QUOTES, 'UTF-8'));
      $f->addAttribute('type', 'AssertionError');
    }
  }

  file_put_contents($file, $xml->asXML());
}

$p = [
  'Measurement'=>'temperature',
  'Location'=>'Cieszyn',
  'Value'=>0.4,
  'Timestamp'=>'2025-12-28T17:00Z',
];
$p['Checksum'] = checksum(canonical($p), $secret);

$expected = checksum(canonical($p), $secret);
ok('Checksum matches', hash_equals($expected, $p['Checksum']), 'Checksum mismatch');

$p2 = $p;
$p2['Value'] = 99.9;
$expected2 = checksum(canonical($p2), $secret);
ok('Checksum fails when payload changes', !hash_equals($expected2, $p['Checksum']), 'Checksum should not match after change');

$json = json_encode($p, JSON_UNESCAPED_SLASHES);
$b64 = base64_encode($json);
$dec = base64_decode($b64, true);
ok('Base64 encode/decode round-trip', $dec === $json, 'Base64 round-trip failed');

$get = http_get($baseUrl . '?action=fetch&city=Cieszyn&t=' . time());
ok('GET fetch returns HTTP 200', $get['code'] === 200, "HTTP={$get['code']} body={$get['body']}");
$j = json_decode($get['body'], true);
ok('GET fetch returns JSON with keys', is_array($j) && isset($j['Location'],$j['Value'],$j['Timestamp']), 'Missing Location/Value/Timestamp');

$payload = [
  'Measurement'=>'temperature',
  'Location'=>'Cieszyn',
  'Value'=>0.4,
  'Timestamp'=>gmdate('Y-m-d\TH:i\Z'),
];
$payload['Checksum'] = checksum(canonical($payload), $secret);

$req = ['DataBase64' => base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES))];
$post = http_post_json($baseUrl, $req);

ok('POST queue returns HTTP 200', $post['code'] === 200, "HTTP={$post['code']} body={$post['body']}");
$j2 = json_decode($post['body'], true);
ok('POST queue response ok+queued', is_array($j2) && ($j2['ok'] ?? false) && ($j2['queued'] ?? false), 'API did not return ok+queued');

echo "=== WeatherLoader simple tests ===\n";
foreach ($results as $r) {
  echo ($r['ok'] ? "[PASS] " : "[FAIL] ") . $r['name'] . "\n";
  if (!$r['ok'] && $r['details']) echo "       " . $r['details'] . "\n";
}
echo "---------------------------------\n";
echo $failures === 0 ? "ALL PASS ✅\n" : "FAILURES: $failures ❌\n";

// JUnit xml
$junitFile = __DIR__ . '/build/junit.xml';
make_junit($results, $junitFile);
echo "JUnit report: $junitFile\n";

exit($failures === 0 ? 0 : 1);
