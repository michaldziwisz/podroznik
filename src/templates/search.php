<?php
/** @var string $csrf */
/** @var array $defaults */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
$timeDefault = (string)($defaults['time'] ?? '');
$omitTimeChecked = $timeDefault === '' ? 'checked' : '';
?>
<div class="stack">
  <h1>Wyszukiwarka połączeń</h1>

  <div class="card">
    <form method="post" action="/search" class="stack" novalidate>
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">

      <fieldset class="grid-2">
        <legend>Trasa</legend>
        <div class="field">
          <label for="from">Z (miejsce startu)</label>
          <input id="from" name="from" type="text" inputmode="search" autocomplete="off" required value="<?= \TyfloPodroznik\Html::e((string)($defaults['from'] ?? '')) ?>">
          <div class="help">Wpisz miasto, przystanek, ulicę lub adres (np. „Warszawa Zachodnia”, „Kraków”).</div>
        </div>
        <div class="field">
          <label for="to">Do (miejsce docelowe)</label>
          <input id="to" name="to" type="text" inputmode="search" autocomplete="off" required value="<?= \TyfloPodroznik\Html::e((string)($defaults['to'] ?? '')) ?>">
        </div>
      </fieldset>

      <fieldset class="grid-2">
        <legend>Data i godzina</legend>
        <div class="field">
          <label for="date">Data wyjazdu</label>
          <input id="date" name="date" type="date" required value="<?= \TyfloPodroznik\Html::e((string)($defaults['date'] ?? date('Y-m-d'))) ?>">
          <div class="help">Możesz wpisać datę ręcznie lub wybrać z kalendarza.</div>
        </div>
        <div class="field">
          <label for="time">Godzina (opcjonalnie)</label>
          <input
            id="time"
            name="time"
            type="time"
            step="60"
            autocomplete="off"
            aria-describedby="time_help"
            value="<?= \TyfloPodroznik\Html::e($timeDefault) ?>"
          >
          <label class="help">
            <input type="checkbox" name="omit_time" value="1" <?= $omitTimeChecked ?>>
            Pomiń godzinę (pokaż połączenia z całego dnia)
          </label>
          <div id="time_help" class="help">Jeśli podasz godzinę, „Pomiń godzinę” zostanie zignorowane.</div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Tryb</legend>
        <div class="stack">
          <div class="field">
            <span class="help">Wyszukuj wg:</span>
            <label><input type="radio" name="arrive_mode" value="DEPARTURE" checked> Odjazdu</label>
            <label><input type="radio" name="arrive_mode" value="ARRIVAL"> Przyjazdu</label>
          </div>
          <div class="field">
            <span class="help">Podróż:</span>
            <label><input type="radio" name="trip_type" value="one-way" checked> W jedną stronę</label>
            <label><input type="radio" name="trip_type" value="two-way"> W obie strony</label>
          </div>
        </div>
      </fieldset>

      <fieldset class="grid-2">
        <legend>Powrót (opcjonalnie)</legend>
        <div class="field">
          <label for="return_date">Data powrotu</label>
          <input id="return_date" name="return_date" type="date" value="">
        </div>
        <div class="field">
          <label for="return_time">Godzina powrotu (opcjonalnie)</label>
          <input
            id="return_time"
            name="return_time"
            type="time"
            step="60"
            autocomplete="off"
            aria-describedby="return_time_help"
            value=""
          >
          <label class="help">
            <input type="checkbox" name="omit_return_time" value="1" checked>
            Pomiń godzinę powrotu
          </label>
          <div id="return_time_help" class="help">Godzina jest opcjonalna.</div>
        </div>
        <div class="field">
          <span class="help">Wg:</span>
          <label><input type="radio" name="return_arrive_mode" value="DEPARTURE" checked> Odjazdu</label>
          <label><input type="radio" name="return_arrive_mode" value="ARRIVAL"> Przyjazdu</label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Ustawienia wyszukiwania</legend>
        <div class="stack">
          <label><input type="checkbox" name="prefer_direct" value="1" checked> Preferuj bez przesiadek</label>
          <label><input type="checkbox" name="only_online" value="1"> Tylko bilet online</label>
          <div class="field">
            <label for="min_change">Minimalny czas na przesiadkę</label>
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
      </fieldset>

      <?php if ($turnstileRequired && $turnstileSiteKey !== ''): ?>
        <div class="field" aria-label="Weryfikacja antyspam">
          <div class="help">Weryfikacja antyspam (Cloudflare Turnstile).</div>
          <div class="cf-turnstile" data-sitekey="<?= \TyfloPodroznik\Html::e($turnstileSiteKey) ?>"></div>
          <noscript><div class="error">Aby wysłać formularz, włącz JavaScript (Turnstile).</div></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
      <?php endif; ?>

      <div>
        <button class="btn primary" type="submit">Szukaj połączeń</button>
      </div>
    </form>
  </div>
</div>
