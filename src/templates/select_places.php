<?php
/** @var string $csrf */
/** @var string $fromQuery */
/** @var string $toQuery */
/** @var array $fromSuggestions */
/** @var array $toSuggestions */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
?>
<div class="stack">
  <h1>Wybór miejsc</h1>
  <p class="help">Znaleźliśmy kilka możliwych dopasowań. Wybierz właściwe miejsca i kontynuuj.</p>

  <div class="card">
    <form method="post" action="/search" class="stack" aria-label="Wybór miejsc">
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">
      <input type="hidden" name="stage" value="select_places">

      <fieldset>
        <legend>Z: <?= \TyfloPodroznik\Html::e($fromQuery) ?></legend>
        <div class="stack">
          <?php if (empty($fromSuggestions)): ?>
            <p class="error">Brak podpowiedzi dla pola „Z”. Wróć i spróbuj wpisać inaczej.</p>
          <?php endif; ?>
          <?php foreach ($fromSuggestions as $i => $s): ?>
            <?php
              $label = (string)($s['n'] ?? '');
              $info = (string)($s['cai'] ?? ($s['a'][0] ?? ''));
              $pds = (string)($s['placeDataString'] ?? '');
              $id = 'fromV_' . $i;
            ?>
            <label for="<?= \TyfloPodroznik\Html::e($id) ?>">
              <input id="<?= \TyfloPodroznik\Html::e($id) ?>" type="radio" name="fromV" value="<?= \TyfloPodroznik\Html::e($pds) ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <?= \TyfloPodroznik\Html::e($label) ?>
              <?php if ($info !== ''): ?>
                <span class="help">— <?= \TyfloPodroznik\Html::e($info) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset>
        <legend>Do: <?= \TyfloPodroznik\Html::e($toQuery) ?></legend>
        <div class="stack">
          <?php if (empty($toSuggestions)): ?>
            <p class="error">Brak podpowiedzi dla pola „Do”. Wróć i spróbuj wpisać inaczej.</p>
          <?php endif; ?>
          <?php foreach ($toSuggestions as $i => $s): ?>
            <?php
              $label = (string)($s['n'] ?? '');
              $info = (string)($s['cai'] ?? ($s['a'][0] ?? ''));
              $pds = (string)($s['placeDataString'] ?? '');
              $id = 'toV_' . $i;
            ?>
            <label for="<?= \TyfloPodroznik\Html::e($id) ?>">
              <input id="<?= \TyfloPodroznik\Html::e($id) ?>" type="radio" name="toV" value="<?= \TyfloPodroznik\Html::e($pds) ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <?= \TyfloPodroznik\Html::e($label) ?>
              <?php if ($info !== ''): ?>
                <span class="help">— <?= \TyfloPodroznik\Html::e($info) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <?php if ($turnstileRequired && $turnstileSiteKey !== ''): ?>
        <div class="field" role="group" aria-label="Weryfikacja antyspam">
          <div class="help">Weryfikacja antyspam (Cloudflare Turnstile).</div>
          <div class="cf-turnstile" data-sitekey="<?= \TyfloPodroznik\Html::e($turnstileSiteKey) ?>"></div>
          <noscript><div class="error">Aby wysłać formularz, włącz JavaScript (Turnstile).</div></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
      <?php endif; ?>

      <div class="actions">
        <button class="btn primary" type="submit">Kontynuuj</button>
        <a class="btn" href="<?= \TyfloPodroznik\Html::url('/', ['from' => $fromQuery, 'to' => $toQuery]) ?>">Wróć</a>
      </div>
    </form>
  </div>
</div>
