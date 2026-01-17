// app.js build mscode?v13

const API_URL = "/weather/weather.php";
const SECRET = "ThisIsASecretKey"; // identyczne jak w config.php

const logEl = document.getElementById("log");
const locationEl = document.getElementById("location");
const valueEl = document.getElementById("value");
const tsEl = document.getElementById("ts");

function setLog(txt) {
  logEl.textContent = txt;
}

// domyślny timestamp (UTC ISO)
if (!tsEl.value) {
  tsEl.value = new Date().toISOString();
}

// kanoniczny string do checksum
function canonical(p) {
  return `${p.Measurement}|${p.Location}|${p.Value}|${p.Timestamp}`;
}

// HMAC-SHA256 -> Base64
async function hmacBase64(message, secret) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    "raw",
    enc.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"]
  );

  const sig = await crypto.subtle.sign("HMAC", key, enc.encode(message));
  const bytes = new Uint8Array(sig);

  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

// Base64 UTF-8 (cały JSON)
function b64EncodeUtf8(str) {
  return btoa(unescape(encodeURIComponent(str)));
}

/*
   1) POBIERZ POGODĘ Z API (GET)
*/
document.getElementById("loadWeather").addEventListener("click", async () => {
  const city = (locationEl.value || "Cieszyn").trim() || "Cieszyn";
  const url = `${API_URL}?action=fetch&city=${encodeURIComponent(city)}&t=${Date.now()}`;

  setLog(`GET ${url}\n...`);

  const res = await fetch(url, { cache: "no-store" });
  const text = await res.text();

  if (!res.ok) {
    setLog(`Błąd GET (HTTP ${res.status}):\n${text}`);
    return;
  }

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    data = null;
  }

  if (!data || data.Location === undefined || data.Value === undefined || data.Timestamp === undefined) {
    setLog(`Niepoprawny JSON z backendu:\n${text}`);
    return;
  }

  // uzupełnij formularz
  locationEl.value = String(data.Location);
  valueEl.value = String(data.Value);
  tsEl.value = String(data.Timestamp);

  setLog(
    `Pobrano z API:\n${JSON.stringify(data, null, 2)}\n\n` +
    `Kliknij "Wyślij do API (kolejka)".`
  );
});

/*
   2) WYŚLIJ DO API (POST – Base64 + checksum)
*/
document.getElementById("send").addEventListener("click", async () => {
  const payload = {
    Measurement: "temperature",
    Location: (locationEl.value || "").trim(),
    Value: Number(valueEl.value),
    Timestamp: (tsEl.value || "").trim(),
    Checksum: ""
  };

  // prosta walidacja
  if (!payload.Location || !payload.Timestamp || !Number.isFinite(payload.Value)) {
    setLog("Uzupełnij poprawnie Location, Value oraz Timestamp.");
    return;
  }

  // checksum (HMAC)
  payload.Checksum = await hmacBase64(canonical(payload), SECRET);

  // JSON + Base64
  const payloadJson = JSON.stringify(payload);
  const base64Payload = b64EncodeUtf8(payloadJson);
  const req = { DataBase64: base64Payload };

  // log
  setLog(
    `POST ${API_URL}\n\n` +
    `Request payload (JSON):\n${payloadJson}\n\n` +
    `--- BASE64 PAYLOAD ---\n` +
    `${base64Payload}\n` +
    `----------------------------------\n\n` +
    `Wysyłanie do API...\n`
  );

  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(req)
  });

  const out = await res.text();

  setLog(
    `Request payload (JSON):\n${payloadJson}\n\n` +
    `--- BASE64 PAYLOAD ---\n` +
    `${base64Payload}\n` +
    `----------------------------------\n\n` +
    `API response (HTTP ${res.status}):\n${out}\n\n` +
    `Parser / Weryfikacja:\n/weather/parser.php\n` +
    `Worker (CLI):\nphp /var/www/html/weather/worker.php`
  );
});
