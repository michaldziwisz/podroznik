<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class EpodroznikClient
{
    private const BASE_URL = 'https://www.e-podroznik.pl';
    private const BASE_HOST = 'www.e-podroznik.pl';

    private ?\CurlHandle $curl = null;

    private function __construct(
        private string $cookieJar,
        private ?string $tabToken,
    ) {
    }

    public function __destruct()
    {
        if ($this->curl instanceof \CurlHandle) {
            @curl_close($this->curl);
            $this->curl = null;
        }
    }

    public static function fromSession(): self
    {
        $cookieJar = $_SESSION['ep_cookiejar'] ?? null;
        $cookieJarOk = is_string($cookieJar) && $cookieJar !== '' && is_file($cookieJar) && is_writable($cookieJar);
        if (!$cookieJarOk) {
            $cookieJar = tempnam(sys_get_temp_dir(), 'epodroznik_cookie_');
            if (!is_string($cookieJar) || $cookieJar === '') {
                throw new \RuntimeException('Nie można utworzyć pliku cookies (tmp).');
            }
            @chmod($cookieJar, 0o600);
            $_SESSION['ep_cookiejar'] = $cookieJar;
        }

        $tabToken = $_SESSION['ep_tabToken'] ?? null;
        if (!is_string($tabToken) || $tabToken === '') {
            $tabToken = null;
        }
        if (!$cookieJarOk) {
            $tabToken = null;
            unset($_SESSION['ep_tabToken']);
        }

        return new self($cookieJar, $tabToken);
    }

    public function suggest(string $query, string $requestKind, string $type = 'ALL'): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['status' => '1', 'suggestions' => []];
        }
        if (!in_array($requestKind, ['SOURCE', 'DESTINATION'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy requestKind dla sugestii.');
        }
        if (!in_array($type, ['AUTO', 'ALL', 'CITIES', 'STOPS', 'STREETS', 'ADDRESSES', 'GEOGRAPHICAL', 'LINE'], true)) {
            $type = 'ALL';
        }

        $this->ensureInitialized();

        $data = $this->suggestRequest($query, $requestKind, $type);
        $suggestions = $data['suggestions'];
        $status = isset($data['status']) && is_string($data['status']) ? trim($data['status']) : '';

        if ($suggestions === [] && ($status === '' || $status !== '0')) {
            // Sometimes e‑podroznik returns empty results when the remote session/token expires.
            // Recreate cookies + token and try once more.
            $this->resetRemoteSession();
            $this->ensureInitialized();
            $data2 = $this->suggestRequest($query, $requestKind, $type);
            if ($data2['suggestions'] !== []) {
                return $data2;
            }

            $status2 = isset($data2['status']) && is_string($data2['status']) ? trim($data2['status']) : '';
            if ($status2 !== '' && $status2 !== '0') {
                throw new \RuntimeException('e‑podroznik.pl zwrócił błąd podpowiedzi (status=' . $status2 . '). Spróbuj ponownie później.');
            }
            return $data2;
        }

        if ($status !== '' && $status !== '0' && $suggestions === []) {
            throw new \RuntimeException('e‑podroznik.pl zwrócił błąd podpowiedzi (status=' . $status . '). Spróbuj ponownie później.');
        }

        return $data;
    }

    private function suggestRequest(string $query, string $requestKind, string $type): array
    {
        $resp = $this->post('/public/suggest.do', [
            'query' => $query,
            'type' => $type,
            'requestKind' => $requestKind,
            'countryCode' => '',
            'forcingCountryCode' => 'false',
            'tabToken' => (string)$this->tabToken,
        ], extraHeaders: [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: ' . self::BASE_URL,
            'Referer: ' . self::BASE_URL . '/',
        ], followLocation: true);

        $respTrim = trim($resp);
        if ($respTrim === '') {
            // e‑podroznik sometimes returns HTTP 200 with an empty body when there are no matches.
            return ['status' => '0', 'suggestions' => []];
        }

        try {
            $data = json_decode($respTrim, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Nie udało się odczytać podpowiedzi z e‑podroznik.pl (nieprawidłowa odpowiedź). Spróbuj ponownie później.');
        }

        if (!is_array($data) || !isset($data['suggestions']) || !is_array($data['suggestions'])) {
            throw new \RuntimeException('Nie udało się odczytać listy podpowiedzi z e‑podroznik.pl. Spróbuj ponownie później.');
        }

        /** @var array{suggestions: array} $data */
        return $data;
    }

    public function search(array $params): string
    {
        $this->ensureInitialized();

        $fromV = (string)($params['fromV'] ?? '');
        $toV = (string)($params['toV'] ?? '');
        if ($fromV === '' || $toV === '') {
            throw new \RuntimeException('Brak fromV/toV (placeDataString).');
        }

        $fromText = (string)($params['fromQuery'] ?? '');
        $toText = (string)($params['toQuery'] ?? '');

        $tseVw = (string)($params['tseVw'] ?? 'regularP');
        if ($tseVw === '') {
            $tseVw = 'regularP';
        }

        $dateV = $this->formatDateForEpodroznik((string)($params['date'] ?? ''));
        if ($dateV === null) {
            throw new \RuntimeException('Nieprawidłowa data.');
        }

        $arrivalV = (string)($params['arrivalV'] ?? 'DEPARTURE');
        if (!in_array($arrivalV, ['DEPARTURE', 'ARRIVAL'], true)) {
            $arrivalV = 'DEPARTURE';
        }

        $tripType = (string)($params['tripType'] ?? 'one-way');
        if (!in_array($tripType, ['one-way', 'two-way'], true)) {
            $tripType = 'one-way';
        }

        $data = [
            'tseVw' => $tseVw,
            'tabToken' => (string)$this->tabToken,
            'fromV' => $fromV,
            'toV' => $toV,
            'tripType' => $tripType,
            'formCompositeSearchingResults.formCompositeSearcherFinalH.fromText' => $fromText,
            'formCompositeSearchingResults.formCompositeSearcherFinalH.toText' => $toText,
            'formCompositeSearchingResults.formCompositeSearcherFinalH.dateV' => $dateV,
            'formCompositeSearchingResults.formCompositeSearcherFinalH.arrivalV' => $arrivalV,
        ];

        $omitTime = (bool)($params['omitTime'] ?? true);
        $timeRaw = trim((string)($params['time'] ?? ''));
        $timeV = $this->formatTimeForEpodroznik($timeRaw);
        if (!$omitTime && $timeRaw !== '' && $timeV !== null && $timeV !== '') {
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.timeV'] = $timeV;
        } else {
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.timeV'] = '';
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.ommitTime'] = 'on';
        }

        if ((bool)($params['preferDirects'] ?? false)) {
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.preferDirects'] = 'true';
        }

        if ((bool)($params['onlyOnline'] ?? false)) {
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.focusedOnSellable'] = 'true';
        }

        $minChange = (string)($params['minChange'] ?? '');
        $data['minimalTimeForChangeV'] = $minChange;

        $carrierTypes = $params['carrierTypes'] ?? [];
        if (is_array($carrierTypes) && $carrierTypes !== []) {
            foreach ($carrierTypes as $v) {
                $vv = (string)$v;
                if (in_array($vv, ['1', '2', '3', '4', '5'], true)) {
                    $data['formCompositeSearchingResults.formCompositeSearcherFinalH.carrierTypes'][] = $vv;
                }
            }
        }

        if ($tripType === 'two-way') {
            $returnDateV = $this->formatDateForEpodroznik((string)($params['returnDate'] ?? ''));
            if ($returnDateV !== null) {
                $data['formCompositeSearchingResults.formCompositeSearcherFinalH.returnDateV'] = $returnDateV;
            } else {
                $data['formCompositeSearchingResults.formCompositeSearcherFinalH.ommitReturnDate'] = 'on';
            }

            $returnArrivalV = (string)($params['returnArrivalV'] ?? 'DEPARTURE');
            if (!in_array($returnArrivalV, ['DEPARTURE', 'ARRIVAL'], true)) {
                $returnArrivalV = 'DEPARTURE';
            }
            $data['formCompositeSearchingResults.formCompositeSearcherFinalH.returnArrivalV'] = $returnArrivalV;

            $omitReturnTime = (bool)($params['omitReturnTime'] ?? true);
            $returnTimeRaw = trim((string)($params['returnTime'] ?? ''));
            $returnTimeV = $this->formatTimeForEpodroznik($returnTimeRaw);
            if (!$omitReturnTime && $returnTimeRaw !== '' && $returnTimeV !== null && $returnTimeV !== '') {
                $data['formCompositeSearchingResults.formCompositeSearcherFinalH.returnTimeV'] = $returnTimeV;
            } else {
                $data['formCompositeSearchingResults.formCompositeSearcherFinalH.returnTimeV'] = '';
                $data['formCompositeSearchingResults.formCompositeSearcherFinalH.ommitReturnTime'] = 'on';
            }
        }

        $html = $this->post('/public/searchingResults.do?method=task', $data, followLocation: true);
        if (trim($html) === '') {
            $this->resetRemoteSession();
            $this->ensureInitialized();
            $data['tabToken'] = (string)$this->tabToken;
            $html = $this->post('/public/searchingResults.do?method=task', $data, followLocation: true);
        }

        return $html;
    }

    public function getResultExtended(string $resId): string
    {
        $this->ensureInitialized();
        $resId = trim($resId);
        if ($resId === '') {
            throw new \RuntimeException('Brak resultId.');
        }
        return $this->get('/public/searchingResultExtended.do?tabToken=' . rawurlencode((string)$this->tabToken) . '&resultId=' . rawurlencode($resId));
    }

    public function getGeneralTimetableStop(string $stopId): string
    {
        $this->ensureInitialized();
        $stopId = trim($stopId);
        if ($stopId === '' || !preg_match('/^\\d+$/', $stopId)) {
            throw new \RuntimeException('Brak stopId.');
        }

        $url = '/public/generalTimetable.do?tabToken=' . rawurlencode((string)$this->tabToken) . '&stopId=' . rawurlencode($stopId);
        $html = $this->get($url);
        if (trim($html) === '') {
            $this->resetRemoteSession();
            $this->ensureInitialized();
            $url = '/public/generalTimetable.do?tabToken=' . rawurlencode((string)$this->tabToken) . '&stopId=' . rawurlencode($stopId);
            $html = $this->get($url);
        }
        return $html;
    }

    public function get(string $pathOrUrl, bool $allowRelative = false): string
    {
        $url = $this->normalizeUrl($pathOrUrl);
        return $this->request('GET', $url, null, followLocation: true);
    }

    private function post(string $path, array $data, array $extraHeaders = [], bool $followLocation = false): string
    {
        $url = $this->normalizeUrl($path);
        return $this->request('POST', $url, $data, extraHeaders: $extraHeaders, followLocation: $followLocation);
    }

    private function normalizeUrl(string $pathOrUrl): string
    {
        $candidate = trim($pathOrUrl);
        if ($candidate === '') {
            throw new \InvalidArgumentException('Brak URL.');
        }

        if (str_starts_with($candidate, '/')) {
            return self::BASE_URL . $candidate;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        }

        if (preg_match('/^https?:\\/\\//i', $candidate) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowy URL dla e‑podroznik.pl.');
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Nieprawidłowy URL dla e‑podroznik.pl.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('Nieprawidłowy schemat URL dla e‑podroznik.pl.');
        }
        if ($host !== self::BASE_HOST) {
            throw new \InvalidArgumentException('Nieprawidłowy host URL dla e‑podroznik.pl.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (int)$port !== 443) {
            throw new \InvalidArgumentException('Nieprawidłowy port URL dla e‑podroznik.pl.');
        }

        return $candidate;
    }

    private function request(
        string $method,
        string $url,
        ?array $data,
        array $extraHeaders = [],
        bool $followLocation = true,
    ): string {
        $ch = $this->curl;
        if (!$ch instanceof \CurlHandle) {
            $ch = curl_init();
            if ($ch === false) {
                throw new \RuntimeException('Nie można zainicjować cURL.');
            }
            $this->curl = $ch;
        } else {
            curl_reset($ch);
        }

        $headers = array_merge([
            'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7',
        ], $extraHeaders);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_URL => $url,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $this->buildFormUrlEncodedBody($data ?? []);
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        $this->throttle();
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = trim((string)curl_error($ch));
            if ($err === '') {
                $err = trim((string)curl_strerror($errno));
            }
            $suffix = '';
            if ($errno !== 0) {
                $suffix = ' (errno ' . $errno . ')';
            }
            throw new \RuntimeException('Błąd połączenia z e-podroznik.pl: ' . ($err !== '' ? $err : 'unknown error') . $suffix);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($code >= 400) {
            throw new \RuntimeException('e-podroznik.pl zwrócił błąd HTTP ' . $code);
        }

        $resp = (string)$resp;
        $this->detectBlockPage($resp);
        return (string)$resp;
    }

    private function throttle(): void
    {
        $ms = $this->envInt('EPODROZNIK_MIN_INTERVAL_MS');
        if ($ms <= 0) {
            return;
        }

        $path = sys_get_temp_dir() . '/podroznik-epodroznik-rate-limit.json';
        $fp = @fopen($path, 'c+');
        if (!is_resource($fp)) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }

        $raw = stream_get_contents($fp);
        $last = 0.0;
        if (is_string($raw) && preg_match('/\"last\"\\s*:\\s*([0-9]+(?:\\.[0-9]+)?)/', $raw, $m)) {
            $last = (float)$m[1];
        }

        $minInterval = ((float)$ms) / 1000.0;
        $now = microtime(true);
        $wait = ($last + $minInterval) - $now;
        if ($wait > 0) {
            usleep((int)round($wait * 1000000));
        }

        $now = microtime(true);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode(['last' => $now], JSON_UNESCAPED_SLASHES));
        fflush($fp);

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function envInt(string $name): int
    {
        $v = $_SERVER[$name] ?? getenv($name);
        if (is_string($v)) {
            $v = trim($v);
        }
        if (is_string($v) && $v !== '' && preg_match('/^-?\\d+$/', $v)) {
            return (int)$v;
        }
        return 0;
    }

    private function detectBlockPage(string $html): void
    {
        $t = mb_strtolower($html);
        if (str_contains($t, 'denial of service') && str_contains($t, 'blacklist')) {
            throw new \RuntimeException('e‑podroznik.pl blokuje zapytania z tego IP (ochrona DDoS / blacklist). Spróbuj ponownie później.');
        }
        if (str_contains($t, 'dos') && str_contains($t, 'attack') && str_contains($t, 'detected')) {
            throw new \RuntimeException('e‑podroznik.pl blokuje zapytania z tego IP (ochrona DDoS). Spróbuj ponownie później.');
        }
        if (str_contains($t, 'page-unhalted') || str_contains($t, 'nieoczekiwany błąd') || str_contains($t, 'nieobsłużony wyjątek')) {
            throw new \RuntimeException('e‑podroznik.pl zwraca stronę błędu („nieoczekiwany błąd”). Spróbuj ponownie później.');
        }
    }

    private function buildFormUrlEncodedBody(array $data): string
    {
        $pairs = [];
        foreach ($data as $k => $v) {
            $key = rawurlencode((string)$k);
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $pairs[] = $key . '=' . rawurlencode((string)$vv);
                }
                continue;
            }
            $pairs[] = $key . '=' . rawurlencode((string)$v);
        }
        return implode('&', $pairs);
    }

    private function resetRemoteSession(): void
    {
        $oldJar = $this->cookieJar;
        $newJar = tempnam(sys_get_temp_dir(), 'epodroznik_cookie_');
        if (!is_string($newJar) || $newJar === '') {
            throw new \RuntimeException('Nie można utworzyć pliku cookies (tmp).');
        }
        @chmod($newJar, 0o600);
        $this->cookieJar = $newJar;
        $_SESSION['ep_cookiejar'] = $newJar;

        $this->tabToken = null;
        unset($_SESSION['ep_tabToken']);

        if (is_string($oldJar) && $oldJar !== '' && $oldJar !== $newJar && is_file($oldJar)) {
            @unlink($oldJar);
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->tabToken !== null && $this->tabToken !== '') {
            return;
        }

        $candidates = [
            self::BASE_URL . '/',
            self::BASE_URL . '/public/seoIndexMainPage.do',
            self::BASE_URL . '/rozklad-jazdy',
        ];

        foreach ($candidates as $url) {
            $html = $this->request('GET', $url, null, followLocation: true);
            $tabToken = $this->extractTabToken($html);
            if ($tabToken !== null) {
                $this->tabToken = $tabToken;
                $_SESSION['ep_tabToken'] = $this->tabToken;
                return;
            }
        }

        throw new \RuntimeException('Nie udało się pobrać tabToken z e-podroznik.pl.');
    }

    private function extractTabToken(string $html): ?string
    {
        // Try the hidden input.
        if (preg_match('/name=\"tabToken\"\\s*value=\"([0-9a-f]{32})\"/i', $html, $m)) {
            return (string)$m[1];
        }

        // Try a JS variable/field assignment.
        if (preg_match('/\\btabToken\\b\\s*[:=]\\s*[\"\\\']([0-9a-f]{32})[\"\\\']/i', $html, $m)) {
            return (string)$m[1];
        }

        // Fallback: JS initialization.
        if (preg_match('/EPodroznik\\.setTabToken\\((?:\"|\\\')([0-9a-f]{32})(?:\"|\\\')\\)/i', $html, $m)) {
            return (string)$m[1];
        }

        // Fallback: try to find any "tabToken ... <32-hex>" pattern.
        if (preg_match('/tabtoken[^0-9a-f]{0,200}([0-9a-f]{32})/i', $html, $m)) {
            return (string)$m[1];
        }

        return null;
    }

    private function formatDateForEpodroznik(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        if (preg_match('/^\\d{2}\\.\\d{2}\\.\\d{4}$/', $date)) {
            return $date;
        }
        if (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $date, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return null;
    }

    private function formatTimeForEpodroznik(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        $time = preg_replace('/[^0-9:.,]/u', '', $time) ?? $time;
        $time = str_replace([',', '.'], ':', $time);

        if (preg_match('/^(\\d{1,2}):(\\d{1,2})(?::(\\d{1,2}))?(?:\\.\\d+)?$/', $time, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $h, $min);
        }

        if (preg_match('/^\\d{1,4}$/', $time)) {
            $digits = $time;
            $len = strlen($digits);
            if ($len <= 2) {
                $h = (int)$digits;
                $min = 0;
            } else {
                $min = (int)substr($digits, -2);
                $h = (int)substr($digits, 0, -2);
            }
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $h, $min);
        }
        return null;
    }
}
