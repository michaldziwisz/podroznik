<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class App
{
    private readonly View $view;

    public function __construct()
    {
        $this->view = new View();
    }

    public function handle(): void
    {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        $this->sendSecurityHeaders();

        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (
            $path !== '/'
            && str_ends_with($path, '/')
            && in_array($method, ['GET', 'HEAD'], true)
        ) {
            $canonical = rtrim($path, '/');
            if ($canonical === '') {
                $canonical = '/';
            }
            $query = parse_url($requestUri, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $canonical .= '?' . $query;
            }
            Html::redirect($canonical, 301);
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }

        try {
            switch ($path) {
                case '/':
                    $this->pageSearch();
                    return;
                case '/timetable':
                    $this->pageTimetable();
                    return;
                case '/timetable/search':
                    $this->handleTimetableSearch();
                    return;
                case '/timetable/results':
                    $this->pageTimetableResults();
                    return;
                case '/contact':
                    $this->pageContact();
                    return;
                case '/contact/send':
                    $this->handleContact();
                    return;
                case '/search':
                    $this->handleSearch();
                    return;
                case '/results':
                    $this->pageResults();
                    return;
                case '/result':
                    $this->pageResult();
                    return;
                case '/extend':
                    $this->handleExtend();
                    return;
                case '/ui':
                    $this->handleUi();
                    return;
                case '/health':
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "ok\n";
                    return;
                default:
                    $this->respondNotFound();
                    return;
            }
        } catch (\Throwable $e) {
            $this->respondError($e);
        }
    }

    private function layout(string $title, string $contentHtml, array $extra = []): void
    {
        $ui = UiPrefs::fromCookies();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->view->render('layout', [
            'title' => $title,
            'contentHtml' => $contentHtml,
            'ui' => $ui,
            'csrf' => Csrf::token(),
            'flash' => $flash,
            ...$extra,
        ]);
    }

    private function pageSearch(): void
    {
        $defaults = [
            'from' => (string)($_GET['from'] ?? ''),
            'to' => (string)($_GET['to'] ?? ''),
            'date' => (string)($_GET['date'] ?? ''),
            'time' => (string)($_GET['time'] ?? ''),
        ];
        if ($defaults['date'] === '') {
            $defaults['date'] = (string)($_SESSION['last_search_form']['date'] ?? '');
        }
        if ($defaults['from'] === '') {
            $defaults['from'] = (string)($_SESSION['last_search_form']['from'] ?? '');
        }
        if ($defaults['to'] === '') {
            $defaults['to'] = (string)($_SESSION['last_search_form']['to'] ?? '');
        }
        if ($defaults['time'] === '') {
            $defaults['time'] = (string)($_SESSION['last_search_form']['time'] ?? '');
        }
        $dateDefault = Input::normalizeDateYmd($defaults['date'] ?? '');
        $defaults['date'] = $dateDefault ?? date('Y-m-d');

        $timeDefault = Input::normalizeTimeHm($defaults['time'] ?? '');
        $defaults['time'] = $timeDefault ?? '';
        $this->layout('Wyszukiwarka połączeń', $this->view->render('search', [
            'csrf' => Csrf::token(),
            'defaults' => $defaults,
            'turnstile' => $this->turnstileViewModel(),
        ]));
    }

    private function pageTimetable(): void
    {
        $defaults = [
            'q' => (string)($_GET['q'] ?? ''),
            'date' => (string)($_GET['date'] ?? ''),
            'from_time' => (string)($_GET['from_time'] ?? ''),
            'to_time' => (string)($_GET['to_time'] ?? ''),
        ];

        foreach (array_keys($defaults) as $k) {
            if ($defaults[$k] === '') {
                $defaults[$k] = (string)($_SESSION['last_timetable_form'][$k] ?? '');
            }
        }

        $dateDefault = Input::normalizeDateYmd($defaults['date'] ?? '');
        $defaults['date'] = $dateDefault ?? date('Y-m-d');

        $fromDefault = Input::normalizeTimeHm($defaults['from_time'] ?? '');
        $defaults['from_time'] = $fromDefault ?? '';

        $toDefault = Input::normalizeTimeHm($defaults['to_time'] ?? '');
        $defaults['to_time'] = $toDefault ?? '';

        $this->layout('Rozkład jazdy z przystanku', $this->view->render('timetable', [
            'csrf' => Csrf::token(),
            'defaults' => $defaults,
            'turnstile' => $this->turnstileViewModel(),
        ]));
    }

    private function pageContact(): void
    {
        $defaults = [
            'kind' => (string)($_GET['kind'] ?? ''),
            'title' => (string)($_GET['title'] ?? ''),
            'description' => (string)($_GET['description'] ?? ''),
            'email' => (string)($_GET['email'] ?? ''),
            'page' => (string)($_GET['page'] ?? ''),
        ];

        if ($defaults['kind'] === '') {
            $defaults['kind'] = 'bug';
        }
        if (!in_array($defaults['kind'], ['bug', 'suggestion'], true)) {
            $defaults['kind'] = 'bug';
        }

        if ($defaults['page'] === '') {
            $ref = (string)($_GET['back'] ?? '');
            if ($ref !== '' && !str_starts_with($ref, '/')) {
                $ref = '';
            }
            if ($ref !== '') {
                $defaults['page'] = $this->absoluteUrl($ref);
            }
        }

        $lastSent = $_SESSION['last_contact_sent'] ?? null;
        if (!is_array($lastSent)) {
            $lastSent = null;
        }
        unset($_SESSION['last_contact_sent']);

        $this->layout('Kontakt', $this->view->render('contact', [
            'csrf' => Csrf::token(),
            'defaults' => $defaults,
            'errors' => [],
            'sent' => $lastSent,
        ]));
    }

    private function handleUi(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respondNotFound();
            return;
        }
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            $this->flash('Błędny token bezpieczeństwa (CSRF). Spróbuj ponownie.', 'error');
            Html::redirect('/');
        }
        $action = (string)($_POST['action'] ?? '');
        $back = (string)($_POST['back'] ?? '/');
        if ($back === '' || !str_starts_with($back, '/')) {
            $back = '/';
        }
        UiPrefs::handlePost($action, $back);
    }

    private function handleContact(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respondNotFound();
            return;
        }
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            $this->flash('Błędny token bezpieczeństwa (CSRF). Spróbuj ponownie.', 'error');
            Html::redirect('/contact');
        }

        $kind = (string)($_POST['kind'] ?? 'bug');
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $page = trim((string)($_POST['page'] ?? ''));

        $errors = [];
        if (!in_array($kind, ['bug', 'suggestion'], true)) {
            $errors['kind'] = 'Wybierz typ zgłoszenia.';
            $kind = 'bug';
        }
        if ($title === '') {
            $errors['title'] = 'Podaj tytuł.';
        }
        if ($description === '') {
            $errors['description'] = 'Podaj opis zgłoszenia.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Podaj prawidłowy adres e‑mail albo zostaw pole puste.';
        }
        if ($page !== '' && !preg_match('/^https?:\\/\\//i', $page)) {
            $errors['page'] = 'Podaj pełny adres URL (zaczynający się od http:// lub https://) albo zostaw pole puste.';
        }

        $defaults = [
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'email' => $email,
            'page' => $page,
        ];

        if ($errors !== []) {
            $this->layout('Kontakt', $this->view->render('contact', [
                'csrf' => Csrf::token(),
                'defaults' => $defaults,
                'errors' => $errors,
                'sent' => null,
            ]));
            return;
        }

        $client = SygnalistaClient::fromEnv();
        if ($client === null) {
            $this->flash('Formularz kontaktowy nie jest jeszcze skonfigurowany (brak ustawień sygnalisty).', 'error');
            Html::redirect('/contact');
        }

        $diagnostics = [
            'web' => array_filter([
                'page' => $page !== '' ? $page : null,
                'userAgent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'acceptLanguage' => (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            ], static fn($v): bool => is_string($v) && $v !== ''),
            'server' => [
                'php' => PHP_VERSION,
            ],
        ];

        $forwardedFor = $this->clientIpForSygnalista();

        try {
            $result = $client->sendReport(
                kind: $kind,
                title: $title,
                description: $description,
                email: $email !== '' ? $email : null,
                diagnostics: $diagnostics,
                forwardedFor: $forwardedFor,
            );
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            $msg = $msg !== '' ? $msg : 'Wystąpił nieoczekiwany błąd.';
            if (str_starts_with($msg, 'Unknown app.id:')) {
                $appId = trim(substr($msg, strlen('Unknown app.id:')));
                $msg = 'Sygnalista nie jest skonfigurowany dla tej aplikacji'
                    . ($appId !== '' ? (' (app.id: ' . $appId . ')') : '')
                    . '. Dodaj mapowanie w konfiguracji workera (`APP_REPO_MAP`) i wykonaj deploy.';
            } elseif ($msg === 'Rate limit exceeded') {
                $msg = 'Przekroczono limit zgłoszeń na minutę. Odczekaj chwilę i spróbuj ponownie.';
            } elseif (str_contains($msg, 'Invalid x-sygnalista-app-token')) {
                $msg = 'Nieprawidłowy token aplikacji (x-sygnalista-app-token). Sprawdź konfigurację po stronie sygnalisty oraz `SYGNALISTA_APP_TOKEN`.';
            }
            $this->flash('Nie udało się wysłać zgłoszenia. ' . $msg, 'error');
            $this->layout('Kontakt', $this->view->render('contact', [
                'csrf' => Csrf::token(),
                'defaults' => $defaults,
                'errors' => [],
                'sent' => null,
            ]));
            return;
        }

        $issueUrl = is_array($result) ? ($result['issue']['html_url'] ?? null) : null;
        $reportId = is_array($result) ? ($result['reportId'] ?? null) : null;

        $_SESSION['last_contact_sent'] = [
            'issueUrl' => is_string($issueUrl) ? $issueUrl : '',
            'reportId' => is_string($reportId) ? $reportId : '',
        ];

        Html::redirect('/contact');
    }

    private function handleTimetableSearch(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respondNotFound();
            return;
        }
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            $this->flash('Błędny token bezpieczeństwa (CSRF). Spróbuj ponownie.', 'error');
            Html::redirect('/timetable');
        }

        $stage = (string)($_POST['stage'] ?? 'initial');
        $client = EpodroznikClient::fromSession();

        if ($stage === 'select_stop') {
            $pending = $_SESSION['pending_timetable'] ?? null;
            $pendingSuggestions = $_SESSION['pending_timetable_suggestions'] ?? null;
            unset($_SESSION['pending_timetable'], $_SESSION['pending_timetable_suggestions']);

            if (!is_array($pending) || !is_array($pendingSuggestions)) {
                $this->flash('Brak danych wyboru przystanku. Wykonaj wyszukiwanie ponownie.', 'error');
                Html::redirect('/timetable');
            }

            $turnstileError = $this->validateTurnstileForPost();
            if ($turnstileError !== null) {
                $this->flash($turnstileError, 'error');
                $this->layout('Wybór przystanku', $this->view->render('timetable_select_stop', [
                    'csrf' => Csrf::token(),
                    'q' => (string)($pending['q'] ?? ''),
                    'suggestions' => $pendingSuggestions,
                    'filters' => [
                        'date' => (string)($pending['date'] ?? ''),
                        'from_time' => (string)($pending['from_time'] ?? ''),
                        'to_time' => (string)($pending['to_time'] ?? ''),
                    ],
                    'turnstile' => $this->turnstileViewModel(),
                ]));
                return;
            }

            $stopV = (string)($_POST['stopV'] ?? '');
            $stopId = $this->stopIdFromPlaceDataString($stopV);
            if ($stopId === null) {
                $this->flash('Wybierz prawidłowy przystanek.', 'error');
                $this->layout('Wybór przystanku', $this->view->render('timetable_select_stop', [
                    'csrf' => Csrf::token(),
                    'q' => (string)($pending['q'] ?? ''),
                    'suggestions' => $pendingSuggestions,
                    'filters' => [
                        'date' => (string)($pending['date'] ?? ''),
                        'from_time' => (string)($pending['from_time'] ?? ''),
                        'to_time' => (string)($pending['to_time'] ?? ''),
                    ],
                    'turnstile' => $this->turnstileViewModel(),
                ]));
                return;
            }

            $_SESSION['last_timetable_form'] = [
                'q' => (string)($pending['q'] ?? ''),
                'date' => (string)($pending['date'] ?? ''),
                'from_time' => (string)($pending['from_time'] ?? ''),
                'to_time' => (string)($pending['to_time'] ?? ''),
            ];

            Html::redirect(Html::url('/timetable/results', [
                'stopId' => $stopId,
                'date' => (string)($pending['date'] ?? ''),
                'from_time' => (string)($pending['from_time'] ?? ''),
                'to_time' => (string)($pending['to_time'] ?? ''),
            ]));
        }

        $turnstileError = $this->validateTurnstileForPost();
        if ($turnstileError !== null) {
            $this->flash($turnstileError, 'error');
            $defaults = $this->readTimetableParamsFromPost();
            $defaults['date'] = Input::normalizeDateYmd($defaults['date'] ?? '') ?? date('Y-m-d');
            $defaults['from_time'] = Input::normalizeTimeHm($defaults['from_time'] ?? '') ?? '';
            $defaults['to_time'] = Input::normalizeTimeHm($defaults['to_time'] ?? '') ?? '';

            $this->layout('Rozkład jazdy z przystanku', $this->view->render('timetable', [
                'csrf' => Csrf::token(),
                'defaults' => $defaults,
                'turnstile' => $this->turnstileViewModel(),
            ]));
            return;
        }

        $params = $this->readTimetableParamsFromPost();
        if ($params['q'] === '') {
            $this->flash('Uzupełnij pole „Miasto / przystanek”.', 'error');
            Html::redirect('/timetable');
        }

        $dateNorm = Input::normalizeDateYmd($params['date']);
        if ($dateNorm === null) {
            $this->flash('Podaj prawidłowy dzień.', 'error');
            Html::redirect(Html::url('/timetable', [
                'q' => $params['q'],
                'date' => $params['date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ]));
        }
        $params['date'] = $dateNorm;

        $fromTimeNorm = Input::normalizeTimeHm($params['from_time']) ?? '';
        $toTimeNorm = Input::normalizeTimeHm($params['to_time']) ?? '';
        $params['from_time'] = $fromTimeNorm;
        $params['to_time'] = $toTimeNorm;

        if ($fromTimeNorm !== '' && $toTimeNorm !== '' && $this->timeToMinutes($fromTimeNorm) > $this->timeToMinutes($toTimeNorm)) {
            $this->flash('„Godzina od” nie może być późniejsza niż „Godzina do”.', 'error');
            Html::redirect(Html::url('/timetable', [
                'q' => $params['q'],
                'date' => $params['date'],
                'from_time' => $fromTimeNorm,
                'to_time' => $toTimeNorm,
            ]));
        }

        $resp = $client->suggest($params['q'], requestKind: 'SOURCE', type: 'STOPS');
        $suggestions = $this->filterStopSuggestions($resp['suggestions'] ?? []);

        if ($suggestions === []) {
            $this->flash('Nie znaleziono przystanków dla podanej frazy. Spróbuj wpisać bardziej ogólną nazwę (np. miasto).', 'error');
            Html::redirect(Html::url('/timetable', [
                'q' => $params['q'],
                'date' => $params['date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ]));
        }

        $pick = $this->pickSuggestion($params['q'], $suggestions);
        if ($pick !== null) {
            $stopId = $this->stopIdFromPlaceDataString((string)($pick['placeDataString'] ?? ''));
            if ($stopId === null) {
                $this->flash('Nie udało się odczytać identyfikatora przystanku z sugestii.', 'error');
                Html::redirect(Html::url('/timetable', [
                    'q' => $params['q'],
                    'date' => $params['date'],
                    'from_time' => $params['from_time'],
                    'to_time' => $params['to_time'],
                ]));
            }

            $_SESSION['last_timetable_form'] = [
                'q' => $params['q'],
                'date' => $params['date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ];

            Html::redirect(Html::url('/timetable/results', [
                'stopId' => $stopId,
                'date' => $params['date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ]));
        }

        $_SESSION['pending_timetable'] = $params;
        $_SESSION['pending_timetable_suggestions'] = $suggestions;

        $this->layout('Wybór przystanku', $this->view->render('timetable_select_stop', [
            'csrf' => Csrf::token(),
            'q' => $params['q'],
            'suggestions' => $suggestions,
            'filters' => [
                'date' => $params['date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ],
            'turnstile' => $this->turnstileViewModel(),
        ]));
    }

    private function handleSearch(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respondNotFound();
            return;
        }
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            $this->flash('Błędny token bezpieczeństwa (CSRF). Spróbuj ponownie.', 'error');
            Html::redirect('/');
        }

        $stage = (string)($_POST['stage'] ?? 'initial');
        $client = EpodroznikClient::fromSession();

        if ($stage === 'select_places') {
            $pending = $_SESSION['pending_search'] ?? null;
            $pendingSuggestions = $_SESSION['pending_suggestions'] ?? null;
            unset($_SESSION['pending_search'], $_SESSION['pending_suggestions']);

            if (!is_array($pending) || !is_array($pendingSuggestions)) {
                $this->flash('Brak danych wyboru miejsc. Wykonaj wyszukiwanie ponownie.', 'error');
                Html::redirect('/');
            }
            $fromSug = (array)($pendingSuggestions['from'] ?? []);
            $toSug = (array)($pendingSuggestions['to'] ?? []);
            if ($fromSug === [] || $toSug === []) {
                $this->flash('Nie znaleziono dopasowań dla jednego z pól. Spróbuj wpisać bardziej ogólną nazwę (np. miasto).', 'error');
                Html::redirect(Html::url('/', ['from' => (string)($pending['fromQuery'] ?? ''), 'to' => (string)($pending['toQuery'] ?? '')]));
            }

            $turnstileError = $this->validateTurnstileForPost();
            if ($turnstileError !== null) {
                $this->flash($turnstileError, 'error');
                $this->layout('Wybór miejsc', $this->view->render('select_places', [
                    'csrf' => Csrf::token(),
                    'fromQuery' => (string)($pending['fromQuery'] ?? ''),
                    'toQuery' => (string)($pending['toQuery'] ?? ''),
                    'fromSuggestions' => $fromSug,
                    'toSuggestions' => $toSug,
                    'turnstile' => $this->turnstileViewModel(),
                ]));
                return;
            }

            $fromV = (string)($_POST['fromV'] ?? '');
            $toV = (string)($_POST['toV'] ?? '');
            if ($fromV === '' || $toV === '') {
                $this->flash('Wybierz zarówno miejsce startu, jak i cel.', 'error');
                $this->layout('Wybór miejsc', $this->view->render('select_places', [
                    'csrf' => Csrf::token(),
                    'fromQuery' => (string)($pending['fromQuery'] ?? ''),
                    'toQuery' => (string)($pending['toQuery'] ?? ''),
                    'fromSuggestions' => $fromSug,
                    'toSuggestions' => $toSug,
                    'turnstile' => $this->turnstileViewModel(),
                ]));
                return;
            }

            $pending['fromV'] = $fromV;
            $pending['toV'] = $toV;
            $this->runSearchAndRenderResults($client, $pending);
            return;
        }

        $turnstileError = $this->validateTurnstileForPost();
        if ($turnstileError !== null) {
            $this->flash($turnstileError, 'error');
            $defaults = [
                'from' => trim((string)($_POST['from'] ?? '')),
                'to' => trim((string)($_POST['to'] ?? '')),
                'date' => trim((string)($_POST['date'] ?? '')),
                'time' => trim((string)($_POST['time'] ?? '')),
            ];
            $defaults['date'] = Input::normalizeDateYmd($defaults['date'] ?? '') ?? date('Y-m-d');
            $defaults['time'] = Input::normalizeTimeHm($defaults['time'] ?? '') ?? '';

            $this->layout('Wyszukiwarka połączeń', $this->view->render('search', [
                'csrf' => Csrf::token(),
                'defaults' => $defaults,
                'turnstile' => $this->turnstileViewModel(),
            ]));
            return;
        }

        $params = $this->readSearchParamsFromPost();

        if ($params['fromQuery'] === '' || $params['toQuery'] === '') {
            $this->flash('Uzupełnij pola „Z” oraz „Do”.', 'error');
            Html::redirect(Html::url('/', [
                'from' => $params['fromQuery'],
                'to' => $params['toQuery'],
                'date' => $params['date'],
                'time' => $params['time'],
            ]));
        }

        $dateNorm = Input::normalizeDateYmd($params['date']);
        if ($dateNorm === null) {
            $this->flash('Podaj prawidłową datę wyjazdu.', 'error');
            Html::redirect(Html::url('/', [
                'from' => $params['fromQuery'],
                'to' => $params['toQuery'],
                'date' => $params['date'],
                'time' => $params['time'],
            ]));
        }
        $params['date'] = $dateNorm;

        $resolved = $this->resolvePlaces($client, $params['fromQuery'], $params['toQuery']);

        if ($resolved['needsSelection']) {
            if (($resolved['fromSuggestions'] ?? []) === [] || ($resolved['toSuggestions'] ?? []) === []) {
                $msg = 'Nie znaleziono dopasowań.';
                if (($resolved['fromSuggestions'] ?? []) === []) {
                    $msg .= ' Dla pola „Z”: ' . $params['fromQuery'] . '.';
                }
                if (($resolved['toSuggestions'] ?? []) === []) {
                    $msg .= ' Dla pola „Do”: ' . $params['toQuery'] . '.';
                }
                $this->flash($msg . ' Spróbuj wpisać bardziej ogólną nazwę (np. miasto).', 'error');
                Html::redirect(Html::url('/', ['from' => $params['fromQuery'], 'to' => $params['toQuery']]));
            }
            $_SESSION['pending_search'] = $params;
            $_SESSION['pending_suggestions'] = [
                'from' => $resolved['fromSuggestions'],
                'to' => $resolved['toSuggestions'],
            ];
            $this->layout('Wybór miejsc', $this->view->render('select_places', [
                'csrf' => Csrf::token(),
                'fromQuery' => $params['fromQuery'],
                'toQuery' => $params['toQuery'],
                'fromSuggestions' => $resolved['fromSuggestions'],
                'toSuggestions' => $resolved['toSuggestions'],
                'turnstile' => $this->turnstileViewModel(),
            ]));
            return;
        }

        $params['fromV'] = $resolved['fromV'];
        $params['toV'] = $resolved['toV'];
        $this->runSearchAndRenderResults($client, $params);
    }

    private function pageTimetableResults(): void
    {
        $stopId = trim((string)($_GET['stopId'] ?? ''));
        if ($stopId === '' || !preg_match('/^\\d+$/', $stopId)) {
            $this->flash('Podaj prawidłowy przystanek (stopId).', 'error');
            Html::redirect('/timetable');
        }

        $dateRaw = trim((string)($_GET['date'] ?? ''));
        $dateNorm = Input::normalizeDateYmd($dateRaw) ?? date('Y-m-d');

        $fromTimeNorm = Input::normalizeTimeHm(trim((string)($_GET['from_time'] ?? ''))) ?? '';
        $toTimeNorm = Input::normalizeTimeHm(trim((string)($_GET['to_time'] ?? ''))) ?? '';
        if ($fromTimeNorm !== '' && $toTimeNorm !== '' && $this->timeToMinutes($fromTimeNorm) > $this->timeToMinutes($toTimeNorm)) {
            $this->flash('„Godzina od” nie może być późniejsza niż „Godzina do”.', 'error');
            Html::redirect(Html::url('/timetable/results', [
                'stopId' => $stopId,
                'date' => $dateNorm,
                'from_time' => '',
                'to_time' => '',
            ]));
        }

        $filters = [
            'date' => $dateNorm,
            'from_time' => $fromTimeNorm,
            'to_time' => $toTimeNorm,
        ];

        try {
            $client = EpodroznikClient::fromSession();
            $html = $client->getGeneralTimetableStop($stopId);
            $parser = new TimetableParser();
            $timetable = $parser->parseGeneralTimetableHtml($html);
            $timetable = TimetableRules::filter($timetable, $filters);
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            $msg = $msg !== '' ? $msg : 'Wystąpił nieoczekiwany błąd.';
            $this->flash('Nie udało się pobrać rozkładu z e‑podroznik.pl. ' . $msg, 'error');
            Html::redirect('/timetable');
        }

        $_SESSION['last_timetable_form'] = [
            'q' => (string)($_SESSION['last_timetable_form']['q'] ?? ''),
            'date' => $dateNorm,
            'from_time' => $fromTimeNorm,
            'to_time' => $toTimeNorm,
        ];

        $this->layout('Rozkład jazdy', $this->view->render('timetable_results', [
            'csrf' => Csrf::token(),
            'timetable' => $timetable,
            'filters' => $filters,
        ]));
    }

    private function handleExtend(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->respondNotFound();
            return;
        }
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            $this->flash('Błędny token bezpieczeństwa (CSRF). Spróbuj ponownie.', 'error');
            Html::redirect('/');
        }

        $turnstileError = $this->validateTurnstileForPost();
        if ($turnstileError !== null) {
            $this->flash($turnstileError, 'error');
            Html::redirect('/results#results');
        }

        $dir = (string)($_POST['dir'] ?? '');
        $url = null;
        if ($dir === 'back') {
            $url = $_SESSION['extend_back'] ?? null;
        } elseif ($dir === 'forward') {
            $url = $_SESSION['extend_forward'] ?? null;
        }
        if (!is_string($url) || $url === '') {
            $this->flash('Brak kolejnych wyników do pobrania.', 'warn');
            Html::redirect('/results#results');
        }

        $client = EpodroznikClient::fromSession();
        try {
            $html = $client->get($url, allowRelative: true);
            $parser = new ResultsParser();
            $results = $parser->parseResultsPageHtml($html);
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            $msg = $msg !== '' ? $msg : 'Wystąpił nieoczekiwany błąd.';
            $this->flash('Nie udało się pobrać kolejnych wyników z e‑podroznik.pl. ' . $msg, 'error');
            Html::redirect('/results#results');
        }

        $_SESSION['extend_back'] = $results['extendBackUrl'];
        $_SESSION['extend_forward'] = $results['extendForwardUrl'];
        $_SESSION['last_results'] = $results;

        Html::redirect('/results');
    }

    private function pageResults(): void
    {
        $results = $_SESSION['last_results'] ?? null;
        if (!is_array($results)) {
            $this->flash('Brak zapisanych wyników. Wykonaj wyszukiwanie.', 'warn');
            Html::redirect('/');
        }
        $this->layout('Wyniki wyszukiwania', $this->view->render('results', [
            'csrf' => Csrf::token(),
            'results' => $results,
            'turnstile' => $this->turnstileViewModel(),
        ]));
    }

    private function pageResult(): void
    {
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            $this->respondNotFound();
            return;
        }

        try {
            $client = EpodroznikClient::fromSession();
            $html = $client->getResultExtended($id);
            $parser = new DetailsParser();
            $details = $parser->parseExtendedHtml($html);
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            $msg = $msg !== '' ? $msg : 'Wystąpił nieoczekiwany błąd.';
            $this->flash('Nie udało się pobrać szczegółów z e‑podroznik.pl. ' . $msg, 'error');
            Html::redirect('/results#results');
        }

        $this->layout('Szczegóły trasy', $this->view->render('result', [
            'csrf' => Csrf::token(),
            'id' => $id,
            'details' => $details,
        ]));
    }

    private function runSearchAndRenderResults(EpodroznikClient $client, array $params): void
    {
        try {
            $html = $client->search($params);
            $parser = new ResultsParser();
            $results = $parser->parseResultsPageHtml($html);
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            $msg = $msg !== '' ? $msg : 'Wystąpił nieoczekiwany błąd.';
            $this->flash('Nie udało się pobrać wyników z e‑podroznik.pl. ' . $msg, 'error');
            Html::redirect(Html::url('/', [
                'from' => (string)($params['fromQuery'] ?? ''),
                'to' => (string)($params['toQuery'] ?? ''),
                'date' => (string)($params['date'] ?? ''),
                'time' => (string)($params['time'] ?? ''),
            ]));
        }

        $_SESSION['extend_back'] = $results['extendBackUrl'];
        $_SESSION['extend_forward'] = $results['extendForwardUrl'];
        $_SESSION['last_results'] = $results;
        $_SESSION['last_search_form'] = [
            'from' => (string)($params['fromQuery'] ?? ''),
            'to' => (string)($params['toQuery'] ?? ''),
            'date' => (string)(Input::normalizeDateYmd((string)($params['date'] ?? '')) ?? ''),
            'time' => (string)(Input::normalizeTimeHm((string)($params['time'] ?? '')) ?? ''),
        ];
        Html::redirect('/results');
    }

    private function resolvePlaces(EpodroznikClient $client, string $fromQuery, string $toQuery): array
    {
        $from = $client->suggest($fromQuery, requestKind: 'SOURCE');
        $to = $client->suggest($toQuery, requestKind: 'DESTINATION');

        $fromSuggestions = $this->filterRealSuggestions($from['suggestions'] ?? []);
        $toSuggestions = $this->filterRealSuggestions($to['suggestions'] ?? []);

        $fromPick = $this->pickSuggestion($fromQuery, $fromSuggestions);
        $toPick = $this->pickSuggestion($toQuery, $toSuggestions);

        $needsSelection = ($fromPick === null || $toPick === null);
        if ($needsSelection) {
            return [
                'needsSelection' => true,
                'fromSuggestions' => $fromSuggestions,
                'toSuggestions' => $toSuggestions,
                'fromV' => '',
                'toV' => '',
            ];
        }

        return [
            'needsSelection' => false,
            'fromSuggestions' => $fromSuggestions,
            'toSuggestions' => $toSuggestions,
            'fromV' => (string)($fromPick['placeDataString'] ?? ''),
            'toV' => (string)($toPick['placeDataString'] ?? ''),
        ];
    }

    private function filterRealSuggestions(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $s) {
            if (!is_array($s)) {
                continue;
            }
            if (($s['isFake'] ?? false) === true) {
                continue;
            }
            if (!isset($s['placeDataString']) || !is_string($s['placeDataString']) || $s['placeDataString'] === '') {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    private function pickSuggestion(string $query, array $suggestions): ?array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return null;
        }
        foreach ($suggestions as $s) {
            $n = isset($s['n']) && is_string($s['n']) ? mb_strtolower(trim($s['n'])) : '';
            if ($n !== '' && $n === $q) {
                return $s;
            }
        }
        if (count($suggestions) === 1) {
            return $suggestions[0];
        }
        return null;
    }

    private function filterStopSuggestions(array $suggestions): array
    {
        $suggestions = $this->filterRealSuggestions($suggestions);
        $out = [];
        foreach ($suggestions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $pds = $s['placeDataString'] ?? null;
            if (!is_string($pds) || !preg_match('/^s\\|\\d+$/', $pds)) {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    private function stopIdFromPlaceDataString(string $placeDataString): ?string
    {
        $placeDataString = trim($placeDataString);
        if (!preg_match('/^s\\|(\\d+)$/', $placeDataString, $m)) {
            return null;
        }
        return (string)$m[1];
    }

    private function readTimetableParamsFromPost(): array
    {
        return [
            'q' => trim((string)($_POST['q'] ?? '')),
            'date' => trim((string)($_POST['date'] ?? '')),
            'from_time' => trim((string)($_POST['from_time'] ?? '')),
            'to_time' => trim((string)($_POST['to_time'] ?? '')),
        ];
    }

    private function timeToMinutes(string $timeHm): int
    {
        if (!preg_match('/^(\\d{2}):(\\d{2})$/', $timeHm, $m)) {
            return 0;
        }
        return ((int)$m[1] * 60) + (int)$m[2];
    }

    private function absoluteUrl(string $path): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($host === '') {
            return $path;
        }
        return $scheme . '://' . $host . $path;
    }

    private function clientIpForSygnalista(): string
    {
        $ip = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
        return '';
    }

    private function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    private function turnstileViewModel(): array
    {
        $t = Turnstile::fromEnv();
        if ($t === null) {
            return [
                'enabled' => false,
                'required' => false,
                'siteKey' => '',
            ];
        }

        $ip = $this->clientIpForSygnalista();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        return [
            'enabled' => true,
            'required' => !$t->isSessionValid($ip, $ua),
            'siteKey' => $t->siteKey(),
        ];
    }

    private function validateTurnstileForPost(): ?string
    {
        $t = Turnstile::fromEnv();
        if ($t === null) {
            return null;
        }

        $ip = $this->clientIpForSygnalista();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($t->isSessionValid($ip, $ua)) {
            return null;
        }

        $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
        if ($token === '') {
            return 'Potwierdź weryfikację antyspam (Turnstile) i spróbuj ponownie.';
        }

        try {
            $ok = $t->verifyToken($token, $ip);
        } catch (\Throwable) {
            return 'Nie udało się zweryfikować weryfikacji antyspam. Spróbuj ponownie.';
        }

        if (!$ok) {
            return 'Nie udało się zweryfikować weryfikacji antyspam. Spróbuj ponownie.';
        }

        $t->markSessionValid($ip, $ua);
        return null;
    }

    private function readSearchParamsFromPost(): array
    {
        $fromQuery = trim((string)($_POST['from'] ?? ''));
        $toQuery = trim((string)($_POST['to'] ?? ''));

        $date = trim((string)($_POST['date'] ?? ''));
        $timeRaw = trim((string)($_POST['time'] ?? ''));
        $time = Input::normalizeTimeHm($timeRaw) ?? '';
        $omitTime = (isset($_POST['omit_time']) && $_POST['omit_time'] === '1');
        if ($time !== '') {
            $omitTime = false;
        }

        $arrivalV = (string)($_POST['arrive_mode'] ?? 'DEPARTURE');
        if (!in_array($arrivalV, ['DEPARTURE', 'ARRIVAL'], true)) {
            $arrivalV = 'DEPARTURE';
        }

        $tripType = (string)($_POST['trip_type'] ?? 'one-way');
        if (!in_array($tripType, ['one-way', 'two-way'], true)) {
            $tripType = 'one-way';
        }

        $returnDate = trim((string)($_POST['return_date'] ?? ''));
        $returnTimeRaw = trim((string)($_POST['return_time'] ?? ''));
        $returnTime = Input::normalizeTimeHm($returnTimeRaw) ?? '';
        $omitReturnTime = (isset($_POST['omit_return_time']) && $_POST['omit_return_time'] === '1');
        if ($returnTime !== '') {
            $omitReturnTime = false;
        }

        $returnArrivalV = (string)($_POST['return_arrive_mode'] ?? 'DEPARTURE');
        if (!in_array($returnArrivalV, ['DEPARTURE', 'ARRIVAL'], true)) {
            $returnArrivalV = 'DEPARTURE';
        }

        $preferDirects = (isset($_POST['prefer_direct']) && $_POST['prefer_direct'] === '1');
        $onlyOnline = (isset($_POST['only_online']) && $_POST['only_online'] === '1');

        $minChange = trim((string)($_POST['min_change'] ?? ''));
        if ($minChange !== '' && !in_array($minChange, ['5', '10', '20', '30'], true)) {
            $minChange = '';
        }

        $carrierTypes = $_POST['carrier_types'] ?? [];
        if (!is_array($carrierTypes)) {
            $carrierTypes = [];
        }
        $carrierTypes = array_values(array_unique(array_filter(array_map('strval', $carrierTypes), static function (string $v): bool {
            return in_array($v, ['1', '2', '3', '4', '5'], true);
        })));
        if ($carrierTypes === []) {
            $carrierTypes = ['1', '2', '3', '4', '5'];
        }

        return [
            'fromQuery' => $fromQuery,
            'toQuery' => $toQuery,
            'date' => $date,
            'time' => $time,
            'omitTime' => $omitTime,
            'arrivalV' => $arrivalV,
            'tripType' => $tripType,
            'returnDate' => $returnDate,
            'returnTime' => $returnTime,
            'omitReturnTime' => $omitReturnTime,
            'returnArrivalV' => $returnArrivalV,
            'preferDirects' => $preferDirects,
            'onlyOnline' => $onlyOnline,
            'minChange' => $minChange,
            'carrierTypes' => $carrierTypes,
            'tseVw' => 'regularP',
        ];
    }

    private function respondNotFound(): void
    {
        http_response_code(404);
        $this->layout('Nie znaleziono', $this->view->render('error', [
            'title' => 'Nie znaleziono',
            'message' => 'Nie znaleziono takiej strony.',
        ]));
    }

    private function respondError(\Throwable $e): void
    {
        error_log((string)$e);
        http_response_code(500);
        $this->layout('Błąd', $this->view->render('error', [
            'title' => 'Wystąpił błąd',
            'message' => 'Wystąpił błąd po stronie serwera. Spróbuj ponownie za chwilę.',
        ]));
    }

    private function flash(string $message, string $level): void
    {
        $_SESSION['flash'] = ['message' => $message, 'level' => $level];
    }
}
