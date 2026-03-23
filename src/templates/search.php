<?php
/** @var string $csrf */
/** @var array $defaults */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
$timeDefault = (string)($defaults['time'] ?? '');
?>
<div class="stack">
  <h1>Wyszukiwarka połączeń</h1>

  <div class="card">
    <form method="post" action="/search" class="stack search-form" novalidate>
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">

      <fieldset class="grid-2">
	        <legend>Trasa</legend>
	        <div class="field">
	          <label for="from">Z</label>
	          <input
	            id="from"
	            name="from"
	            type="text"
	            inputmode="search"
	            autocomplete="off"
	            placeholder="Miasto, przystanek, ulica lub adres"
	            required
	            value="<?= \TyfloPodroznik\Html::e((string)($defaults['from'] ?? '')) ?>"
	            role="combobox"
	            aria-autocomplete="list"
	            aria-expanded="false"
	            aria-controls="from_suggestions"
	            data-ep-suggest="1"
	            data-ep-kind="SOURCE"
	            data-ep-type="ALL"
	            data-ep-hidden="fromV"
	            data-ep-list="from_suggestions"
	          >
	          <input type="hidden" id="fromV" name="fromV" value="">
	          <ul id="from_suggestions" class="autocomplete-list" role="listbox" hidden></ul>
	        </div>
	        <div class="field">
	          <label for="to">Do</label>
	          <input
	            id="to"
	            name="to"
	            type="text"
	            inputmode="search"
	            autocomplete="off"
	            placeholder="Miasto, przystanek, ulica lub adres"
	            required
	            value="<?= \TyfloPodroznik\Html::e((string)($defaults['to'] ?? '')) ?>"
	            role="combobox"
	            aria-autocomplete="list"
	            aria-expanded="false"
	            aria-controls="to_suggestions"
	            data-ep-suggest="1"
	            data-ep-kind="DESTINATION"
	            data-ep-type="ALL"
	            data-ep-hidden="toV"
	            data-ep-list="to_suggestions"
	          >
	          <input type="hidden" id="toV" name="toV" value="">
	          <ul id="to_suggestions" class="autocomplete-list" role="listbox" hidden></ul>
	        </div>
	      </fieldset>

      <fieldset class="grid-2">
        <legend>Data i godzina</legend>
	        <div class="field">
	          <label for="date">Data wyjazdu</label>
	          <input
	            id="date"
	            name="date"
	            type="text"
	            inputmode="numeric"
	            autocomplete="off"
	            placeholder="YYYY-MM-DD"
	            data-ep-normalize="date"
	            required
	            value="<?= \TyfloPodroznik\Html::e((string)($defaults['date'] ?? date('Y-m-d'))) ?>"
	          >
	        </div>
	        <div class="field">
	          <label for="time">Godzina (opcjonalnie)</label>
	          <input type="hidden" name="omit_time" value="1">
	          <input
	            id="time"
	            name="time"
	            type="text"
	            inputmode="numeric"
	            autocomplete="off"
	            placeholder="HH:MM"
	            data-ep-normalize="time"
	            value="<?= \TyfloPodroznik\Html::e($timeDefault) ?>"
	          >
        </div>
      </fieldset>

      <div class="grid-2">
        <fieldset>
          <legend>Wyszukuj wg</legend>
          <div class="stack">
            <label><input type="radio" name="arrive_mode" value="DEPARTURE" checked> Odjazdu</label>
            <label><input type="radio" name="arrive_mode" value="ARRIVAL"> Przyjazdu</label>
          </div>
        </fieldset>
        <fieldset>
          <legend>Podróż</legend>
          <div class="stack">
            <label><input type="radio" name="trip_type" value="one-way" checked> W jedną stronę</label>
            <label><input type="radio" name="trip_type" value="two-way"> W obie strony</label>
          </div>
        </fieldset>
      </div>

	      <fieldset class="grid-2" id="return_fields" hidden disabled>
	        <legend>Powrót (opcjonalnie)</legend>
	        <div class="field">
	          <label for="return_date">Data powrotu</label>
	          <input
	            id="return_date"
	            name="return_date"
	            type="text"
	            inputmode="numeric"
	            autocomplete="off"
	            placeholder="YYYY-MM-DD"
	            data-ep-normalize="date"
	            value=""
	          >
	        </div>
	        <div class="field">
	          <label for="return_time">Godzina powrotu (opcjonalnie)</label>
	          <input type="hidden" name="omit_return_time" value="1">
	          <input
	            id="return_time"
	            name="return_time"
	            type="text"
	            inputmode="numeric"
	            autocomplete="off"
	            placeholder="HH:MM"
	            data-ep-normalize="time"
	            value=""
	          >
        </div>
        <div class="field">
          <span class="help">Wg:</span>
          <label><input type="radio" name="return_arrive_mode" value="DEPARTURE" checked> Odjazdu</label>
          <label><input type="radio" name="return_arrive_mode" value="ARRIVAL"> Przyjazdu</label>
        </div>
      </fieldset>

      <script>
        (function () {
          var form = document.querySelector('form[action="/search"]');
          if (!form) return;

          var returnFields = document.getElementById('return_fields');
          if (!returnFields) return;

          var inputs = returnFields.querySelectorAll('input, select, textarea, button');

          function sync() {
            var checked = form.querySelector('input[name="trip_type"]:checked');
            var isTwoWay = checked && checked.value === 'two-way';
            returnFields.hidden = !isTwoWay;
            returnFields.disabled = !isTwoWay;
            for (var i = 0; i < inputs.length; i++) {
              inputs[i].disabled = !isTwoWay;
            }
          }

          var radios = form.querySelectorAll('input[name="trip_type"]');
          for (var i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', sync);
          }
          sync();
        })();
      </script>

      <details class="card">
        <summary>Ustawienia wyszukiwania (opcjonalnie)</summary>
        <div class="stack">
          <label><input type="checkbox" name="prefer_direct" value="1" checked> Preferuj bez przesiadek</label>
          <label><input type="checkbox" name="only_online" value="1"> Tylko bilet online</label>
          <div class="field">
            <label id="min_change_label" for="min_change">Minimalny czas na przesiadkę</label>
            <select id="min_change" name="min_change">
              <option value="">Domyślny</option>
              <option value="5">Co najmniej 5 minut</option>
              <option value="10">Co najmniej 10 minut</option>
              <option value="20">Co najmniej 20 minut</option>
              <option value="30">Co najmniej 30 minut</option>
            </select>
          </div>
          <fieldset>
            <legend>Środki transportu</legend>
            <div class="stack">
              <label><input type="checkbox" name="carrier_types[]" value="2" checked> Bus</label>
              <label><input type="checkbox" name="carrier_types[]" value="3" checked> Kolej</label>
              <label><input type="checkbox" name="carrier_types[]" value="1" checked> Autokar</label>
              <label><input type="checkbox" name="carrier_types[]" value="4" checked> Miejska</label>
              <label><input type="checkbox" name="carrier_types[]" value="5" checked> Prom</label>
            </div>
          </fieldset>
        </div>
      </details>

      <div>
        <button class="btn primary" type="submit">Szukaj połączeń</button>
      </div>

      <?php if ($turnstileRequired && $turnstileSiteKey !== ''): ?>
        <div class="field">
          <div class="help">Weryfikacja antyspam (Cloudflare Turnstile).</div>
          <div class="cf-turnstile" data-sitekey="<?= \TyfloPodroznik\Html::e($turnstileSiteKey) ?>"></div>
          <noscript><div class="error">Aby wysłać formularz, włącz JavaScript (Turnstile).</div></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
      <?php endif; ?>
    </form>
  </div>
</div>
