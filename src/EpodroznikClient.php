<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class EpodroznikClient
{
    private const BASE_URL = 'https://www.e-podroznik.pl';
    private const BASE_HOST = 'www.e-podroznik.pl';

    private function __construct(
        private string $cookieJar,
        private ?string $tabToken,
    ) {
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

        $resp = $this->post('/public/suggest.do', [
            'query' => $query,
            'type' => $type,
            'requestKind' => $requestKind,
            'countryCode' => '',
            'forcingCountryCode' => 'false',
        ], extraHeaders: [
            'X-Requested-With: XMLHttpRequest',
        ]);

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return ['status' => '1', 'suggestions' => []];
        }
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
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Nie można zainicjować cURL.');
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
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $this->buildFormUrlEncodedBody($data ?? []);
        }

        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = trim((string)curl_error($ch));
            if ($err === '') {
                $err = trim((string)curl_strerror($errno));
            }
            curl_close($ch);
            $suffix = '';
            if ($errno !== 0) {
                $suffix = ' (errno ' . $errno . ')';
            }
            throw new \RuntimeException('Błąd połączenia z e-podroznik.pl: ' . ($err !== '' ? $err : 'unknown error') . $suffix);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new \RuntimeException('e-podroznik.pl zwrócił błąd HTTP ' . $code);
        }
        return (string)$resp;
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

        $html = $this->request('GET', self::BASE_URL . '/', null, followLocation: true);
        if (!preg_match('/name=\"tabToken\"\\s*value=\"([^\"]+)\"/i', $html, $m)) {
            throw new \RuntimeException('Nie udało się pobrać tabToken z e-podroznik.pl.');
        }
        $this->tabToken = (string)$m[1];
        $_SESSION['ep_tabToken'] = $this->tabToken;
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
