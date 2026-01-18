<?php
/** @var string $csrf */
/** @var array $defaults */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
$q = (string)($defaults['q'] ?? '');
$date = (string)($defaults['date'] ?? date('Y-m-d'));
$fromTime = (string)($defaults['from_time'] ?? '');
$toTime = (string)($defaults['to_time'] ?? '');
?>
<div class="stack">
  <h1>Rozkład jazdy z przystanku</h1>

  <div class="card">
    <form method="post" action="/timetable/search" class="stack" novalidate>
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">

	      <fieldset>
	        <legend>Przystanek</legend>
	        <div class="field">
	          <label for="q">Miasto / przystanek</label>
	          <input
	            id="q"
	            name="q"
	            type="text"
	            inputmode="search"
	            autocomplete="off"
	            required
	            value="<?= \TyfloPodroznik\Html::e($q) ?>"
	            role="combobox"
	            aria-autocomplete="list"
	            aria-expanded="false"
	            aria-controls="stop_suggestions"
	            aria-describedby="q_help q_status"
	            data-ep-suggest="1"
	            data-ep-kind="SOURCE"
	            data-ep-type="STOPS"
	            data-ep-hidden="stopV"
	            data-ep-list="stop_suggestions"
	            data-ep-status="q_status"
	          >
	          <input type="hidden" id="stopV" name="stopV" value="">
	          <div id="q_help" class="help">Wpisz nazwę miasta lub przystanku. Podpowiedzi pojawią się po wpisaniu min. 2 znaków. Użyj strzałek góra/dół i Enter.</div>
	          <div id="q_status" class="sr-only" aria-live="polite"></div>
	          <ul id="stop_suggestions" class="autocomplete-list" role="listbox" hidden></ul>
	        </div>
	      </fieldset>

      <fieldset class="grid-2">
        <legend>Filtry (opcjonalnie)</legend>
        <div class="field">
          <label for="date">Dzień</label>
          <input id="date" name="date" type="date" required value="<?= \TyfloPodroznik\Html::e($date) ?>">
          <div class="help">Rozkład zostanie przefiltrowany do wybranego dnia na podstawie opisów kursowania.</div>
        </div>
        <div class="field">
          <label for="from_time">Godzina od</label>
          <input id="from_time" name="from_time" type="time" step="60" autocomplete="off" value="<?= \TyfloPodroznik\Html::e($fromTime) ?>">
          <div class="help">Jeśli puste — pokaż cały dzień.</div>
        </div>
        <div class="field">
          <label for="to_time">Godzina do</label>
          <input id="to_time" name="to_time" type="time" step="60" autocomplete="off" value="<?= \TyfloPodroznik\Html::e($toTime) ?>">
          <div class="help">Jeśli puste — bez górnego limitu.</div>
        </div>
      </fieldset>

      <?php if ($turnstileRequired && $turnstileSiteKey !== ''): ?>
        <div class="field" aria-label="Weryfikacja antyspam">
          <div class="help">Weryfikacja antyspam (Cloudflare Turnstile).</div>
          <div class="cf-turnstile" data-sitekey="<?= \TyfloPodroznik\Html::e($turnstileSiteKey) ?>"></div>
          <noscript><div class="error">Aby wysłać formularz, włącz JavaScript (Turnstile).</div></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
      <?php endif; ?>

      <div class="actions">
        <button class="btn primary" type="submit">Pokaż rozkład</button>
        <a class="btn" href="/">Wyszukiwarka połączeń</a>
      </div>
    </form>
  </div>

  <div class="help">
    Źródło: „Tabliczki Dworcowe i Przystankowe” w e‑podroznik.pl. Lista „przez:” nie zawsze zawiera wszystkie miejscowości pośrednie.
  </div>
</div>
