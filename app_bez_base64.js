// app.js build mscode?v12
const API_URL = "/weather/weather.php";
const SECRET = "ThisIsASecretKey"; // identyczne jak w config.php

const logEl = document.getElementById("log");
const locationEl = document.getElementById("location");
const valueEl = document.getElementById("value");
const tsEl = document.getElementById("ts");

function setLog(txt) {
  logEl.textContent = txt;
}

// domyślny timestamp
if (!tsEl.value) tsEl.value = new Date().toISOString();

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

// base64 UTF-8
function b64EncodeUtf8(str) {
  return btoa(unescape(encodeURIComponent(str)));
}

// 1) Pobierz pogodę
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
  try { data = JSON.parse(text); } catch { data = null; }

  if (!data || data.Location === undefined || data.Value === undefined || data.Timestamp === undefined) {
    setLog(`Niepoprawny JSON z backendu:\n${text}`);
    return;
  }

  locationEl.value = String(data.Location);
  valueEl.value = String(data.Value);
  tsEl.value = String(data.Timestamp);

  setLog(`Pobrano:\n${JSON.stringify(data, null, 2)}\n\nKliknij "Wyślij do API".`);
});

// 2) Wyślij do API jako base64 + checksum
document.getElementById("send").addEventListener("click", async () => {
  const payload = {
    Measurement: "temperature",
    Location: (locationEl.value || "").trim(),
    Value: Number(valueEl.value),
    Timestamp: (tsEl.value || "").trim(),
    Checksum: ""
  };

  if (!payload.Location || !payload.Timestamp || !Number.isFinite(payload.Value)) {
    setLog("Uzupełnij poprawnie Location, Value, Timestamp.");
    return;
  }

  payload.Checksum = await hmacBase64(canonical(payload), SECRET);

  const payloadJson = JSON.stringify(payload);
  const req = { DataBase64: b64EncodeUtf8(payloadJson) };

  setLog(`POST ${API_URL}\n\nRequest payload:\n${payloadJson}\n...`);

  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(req)
  });

  const out = await res.text();

  setLog(
    `Request payload:\n${payloadJson}\n\n` +
    `API response (HTTP ${res.status}):\n${out}\n\n` +
    `Parser/Weryfikacja:\n/weather/parser.php\n` +
    `Worker:\nphp /var/www/html/weather/worker.php`
  );
});
