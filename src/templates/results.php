<?php
/** @var string $csrf */
/** @var array $results */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
$count = (int)($results['count'] ?? 0);
?>
<div class="stack" id="results">
  <h1>Wyniki wyszukiwania</h1>

  <div class="card stack" role="status" aria-live="polite">
    <div>
      <strong>Liczba wyników:</strong> <?= $count ?>
    </div>
    <?php if ($count === 0): ?>
      <div class="help">
        Brak wyników dla wybranych ustawień. Spróbuj zaznaczyć inne środki transportu albo wyłączyć „Preferuj bez przesiadek”.
      </div>
    <?php endif; ?>
    <div class="actions">
      <form method="post" action="/extend" class="stack" novalidate>
        <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">
        <div class="actions">
          <button class="btn" type="submit" name="dir" value="back" <?= empty($_SESSION['extend_back']) ? 'disabled' : '' ?>>Wcześniejsze połączenia</button>
          <button class="btn" type="submit" name="dir" value="forward" <?= empty($_SESSION['extend_forward']) ? 'disabled' : '' ?>>Późniejsze połączenia</button>
        </div>

        <?php if ($turnstileRequired && $turnstileSiteKey !== ''): ?>
          <div class="field" aria-label="Weryfikacja antyspam">
            <div class="help">Weryfikacja antyspam (Cloudflare Turnstile).</div>
            <div class="cf-turnstile" data-sitekey="<?= \TyfloPodroznik\Html::e($turnstileSiteKey) ?>"></div>
            <noscript><div class="error">Aby wysłać formularz, włącz JavaScript (Turnstile).</div></noscript>
          </div>
          <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <?php endif; ?>
      </form>
      <a class="btn" href="/">Nowe wyszukiwanie</a>
    </div>
  </div>

  <div class="results" aria-label="Lista wyników">
    <?php foreach (($results['results'] ?? []) as $idx => $r): ?>
      <?php
        $fromStop = (string)($r['from']['stop'] ?? '');
        $toStop = (string)($r['to']['stop'] ?? '');
        $fromTime = (string)($r['from']['time'] ?? '');
        $toTime = (string)($r['to']['time'] ?? '');
        $fromDate = (string)($r['from']['date'] ?? '');
        $toDate = (string)($r['to']['date'] ?? '');
        $duration = (string)($r['duration'] ?? '');
        $sellable = (bool)($r['sellable'] ?? false);
        $resId = (string)($r['resId'] ?? '');
        $segments = (int)($r['connectionsCount'] ?? 0);
        $changes = (int)($r['sort']['changes'] ?? 0);
        if ($segments > 0 && $changes === 0) {
          $changes = max(0, $segments - 1);
        }
      ?>
      <article class="result" aria-label="Wynik <?= (int)$idx + 1 ?>">
        <h3>
          <?= \TyfloPodroznik\Html::e($fromTime) ?> <?= \TyfloPodroznik\Html::e($fromStop) ?>
          → <?= \TyfloPodroznik\Html::e($toTime) ?> <?= \TyfloPodroznik\Html::e($toStop) ?>
          <?php if ($duration !== ''): ?>
            <span class="meta">(<?= \TyfloPodroznik\Html::e($duration) ?>)</span>
          <?php endif; ?>
        </h3>
        <dl class="meta">
          <?php if ($fromDate !== ''): ?><dt>Odjazd</dt><dd><?= \TyfloPodroznik\Html::e(trim($fromTime . ' ' . $fromDate)) ?></dd><?php endif; ?>
          <?php if ($toDate !== ''): ?><dt>Przyjazd</dt><dd><?= \TyfloPodroznik\Html::e(trim($toTime . ' ' . $toDate)) ?></dd><?php endif; ?>
          <?php if ($duration !== ''): ?><dt>Czas podróży</dt><dd><?= \TyfloPodroznik\Html::e($duration) ?></dd><?php endif; ?>
          <dt>Przesiadki</dt><dd><?= (int)$changes ?></dd>
          <dt>Bilet online</dt>
          <dd><?= $sellable ? '<span class="ok">możliwy</span>' : '<span class="warn">brak / niedostępny</span>' ?></dd>
        </dl>
        <div class="actions">
          <?php if ($resId !== ''): ?>
            <a class="btn" href="<?= \TyfloPodroznik\Html::url('/result', ['id' => $resId]) ?>">Szczegóły</a>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>
