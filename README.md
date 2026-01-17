# 🌤 Weather Loader

Prosta aplikacja webowa do pobierania danych pogodowych z zewnętrznego API oraz ich asynchronicznego przetwarzania i zapisu w bazie danych.

---

## 📌 Opis projektu

Aplikacja umożliwia:
- pobranie aktualnych danych pogodowych dla wybranej lokalizacji,
- walidację danych z wykorzystaniem checksum (HMAC-SHA256),
- przesyłanie danych w formacie Base64,
- zapis danych z użyciem mechanizmu kolejki,
- asynchroniczne przetwarzanie danych przez worker,
- weryfikację poprawności działania przy użyciu panelu parsera.

Projekt został wykonany w celach edukacyjnych jako zaliczenie ćwiczeń.

---

## 🛠 Technologie

- **Frontend:** HTML, CSS, JavaScript  
- **Backend:** PHP  
- **Baza danych:** MySQL  
- **Serwer HTTP:** Apache2  

---

## 📁 Struktura projektu

- `index.html` – interfejs użytkownika  
- `style.css` – stylizacja aplikacji  
- `app.js` – logika frontendowa i komunikacja z API  
- `weather.php` – REST API (GET / POST)  
- `worker.php` – asynchroniczne przetwarzanie danych z kolejki  
- `parser.php` – panel weryfikacji danych  
- `config.php` – konfiguracja aplikacji  
- `tests.php` – proste testy automatyczne + raport JUnit  

---

## 🚀 Uruchomienie

1. Skopiuj pliki do katalogu:
   ```bash
   /var/www/html/weather
2. Skonfiguruj połączenie z bazą danych w pliku:
   ```bash
   config.php
3. Otwórz aplikację w przeglądarce:
   ```bash
   [Otwórz aplikację w przeglądarce:](http://localhost/weather)
3. Uruchom workera:
   ```bash
   php worker.php
   
