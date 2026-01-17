<?php
/** @var string $csrf */
/** @var array $defaults */
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
          <input id="q" name="q" type="text" inputmode="search" autocomplete="off" required value="<?= \TyfloPodroznik\Html::e($q) ?>">
          <div class="help">Wpisz nazwę miasta lub przystanku (np. „Iława”, „Warszawa Zachodnia”).</div>
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

