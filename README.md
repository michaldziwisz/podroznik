# Podróżnik (Tyflo) — dostępny frontend do e‑podroznik.pl

To jest lekki frontend w PHP, który korzysta z publicznych endpointów `www.e-podroznik.pl` (podpowiedzi miejsc, wyszukiwanie połączeń, szczegóły trasy, rozkład jazdy z przystanku) i renderuje wyniki w prostym, semantycznym HTML z naciskiem na dostępność (klawiatura, czytniki ekranu, wysoki kontrast, większa czcionka).

## Uruchomienie lokalne

W katalogu repo:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Otwórz `http://127.0.0.1:8080/`.

## Uwagi

- To nie jest oficjalny produkt e‑podróżnik.pl i może przestać działać, jeśli zmienią endpointy lub format HTML.
- Ta aplikacja nie implementuje zakupu biletów — skupia się na dostępnej wyszukiwarce i prezentacji wyników/szczegółów.

## Zgłoszenia (Sygnalista)

W aplikacji jest widok kontaktowy: `/contact`. Może wysyłać zgłoszenia do systemu „sygnalista” (Cloudflare Worker `POST /v1/report`), który tworzy issue na GitHub.

Po stronie workera „sygnalista” musisz dodać mapowanie aplikacji do repo z issue (zmienna `APP_REPO_MAP`), np.:

```json
{ "podroznik": "michaldziwisz/podroznik" }
```

Konfiguracja przez zmienne środowiskowe (np. `fastcgi_param` w nginx):

- `SYGNALISTA_BASE_URL` (np. `https://sygnalista.<twoj-subdomain>.workers.dev`)
- `SYGNALISTA_APP_ID` (np. `podroznik`)
- opcjonalnie: `SYGNALISTA_APP_TOKEN` (jeśli w Workerze włączony `APP_TOKEN_MAP`)
- opcjonalnie: `SYGNALISTA_APP_VERSION`, `SYGNALISTA_APP_BUILD`, `SYGNALISTA_APP_CHANNEL`
