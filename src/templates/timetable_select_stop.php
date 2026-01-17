<?php
/** @var string $csrf */
/** @var string $q */
/** @var array $suggestions */
/** @var array $filters */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
?>
<div class="stack">
  <h1>Wybór przystanku</h1>
  <p class="help">Wybierz właściwy przystanek i pokaż rozkład jazdy.</p>

  <div class="card">
    <form method="post" action="/timetable/search" class="stack">
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">
      <input type="hidden" name="stage" value="select_stop">

      <fieldset>
        <legend>Zapytanie: <?= \TyfloPodroznik\Html::e($q) ?></legend>
        <?php if (!empty($filters['date'])): ?>
          <div class="help">Dzień: <?= \TyfloPodroznik\Html::e((string)$filters['date']) ?></div>
        <?php endif; ?>
        <?php if (!empty($filters['from_time']) || !empty($filters['to_time'])): ?>
          <div class="help">
            Godzina:
            <?= \TyfloPodroznik\Html::e((string)($filters['from_time'] ?? '')) ?>
            <?php if (!empty($filters['to_time'])): ?>–<?= \TyfloPodroznik\Html::e((string)$filters['to_time']) ?><?php endif; ?>
          </div>
        <?php endif; ?>
      </fieldset>

      <fieldset>
        <legend>Przystanki</legend>
        <div class="stack" role="radiogroup" aria-label="Wybór przystanku">
          <?php foreach ($suggestions as $i => $s): ?>
            <?php
              $label = (string)($s['n'] ?? '');
              $info = (string)($s['cai'] ?? ($s['a'][0] ?? ''));
              $pds = (string)($s['placeDataString'] ?? '');
              $id = 'stop_' . $i;
            ?>
            <label for="<?= \TyfloPodroznik\Html::e($id) ?>">
              <input id="<?= \TyfloPodroznik\Html::e($id) ?>" type="radio" name="stopV" value="<?= \TyfloPodroznik\Html::e($pds) ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <?= \TyfloPodroznik\Html::e($label) ?>
              <?php if ($info !== ''): ?>
                <span class="help">— <?= \TyfloPodroznik\Html::e($info) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
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
        <a class="btn" href="<?= \TyfloPodroznik\Html::url('/timetable', $filters + ['q' => $q]) ?>">Wróć</a>
      </div>
    </form>
  </div>
</div>
