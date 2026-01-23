<?php
/** @var string $csrf */
/** @var array $results */
$turnstile = (isset($turnstile) && is_array($turnstile)) ? $turnstile : [];
$turnstileRequired = (bool)($turnstile['required'] ?? false);
$turnstileSiteKey = (string)($turnstile['siteKey'] ?? '');
$ticketHandoff = (isset($ticketHandoff) && is_array($ticketHandoff)) ? $ticketHandoff : null;
$ticketHandoffOk = is_array($ticketHandoff)
  && is_string($ticketHandoff['fromV'] ?? null) && $ticketHandoff['fromV'] !== ''
  && is_string($ticketHandoff['toV'] ?? null) && $ticketHandoff['toV'] !== ''
  && is_string($ticketHandoff['dateV'] ?? null) && $ticketHandoff['dateV'] !== '';
$count = (int)($results['count'] ?? 0);
$anySellable = false;
foreach (($results['results'] ?? []) as $r) {
  if (is_array($r) && (($r['sellable'] ?? false) === true)) {
    $anySellable = true;
    break;
  }
}
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
    <?php if ($anySellable): ?>
      <div class="help">
        Zakup biletów odbywa się w serwisie e‑podroznik.pl. Połączenia z opcją zakupu są oznaczone „Bilet online: możliwy”.
        Przycisk „Kup bilet” otwiera e‑podroznik.pl w nowej karcie i próbuje przejść bezpośrednio do zakupu danego połączenia.
        Jeśli to się nie uda, w nowej karcie pojawią się wyniki w e‑podroznik.pl – wtedy wybierz „Kup bilet” przy właściwym połączeniu.
      </div>
      <?php if (!$ticketHandoffOk): ?>
        <div class="help warn">
          Nie udało się przygotować przekazania do zakupu biletu (brak danych ostatniego wyszukiwania). Wykonaj wyszukiwanie ponownie.
        </div>
      <?php endif; ?>
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
      <?php if ($anySellable): ?>
        <a class="btn" href="https://www.e-podroznik.pl/">Otwórz e‑podroznik.pl</a>
      <?php endif; ?>
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
          <?php if ($ticketHandoffOk && $sellable && $resId !== ''): ?>
            <?php
              $tabToken = bin2hex(random_bytes(16));
              $defineTicketUrl = 'https://www.e-podroznik.pl/public/defineTicketP.do?tabToken='
                . rawurlencode($tabToken)
                . '&resId=' . rawurlencode($resId)
                . '&forward=url';
              $searchAction = 'https://www.e-podroznik.pl/public/searchingResults.do?method=task';
            ?>
            <form
              method="post"
              action="<?= \TyfloPodroznik\Html::e($searchAction) ?>"
              target="_blank"
              class="ep-ticket-handoff"
              data-ep-ticket-handoff="1"
              data-ep-define-url="<?= \TyfloPodroznik\Html::e($defineTicketUrl) ?>"
              data-ep-window="<?= \TyfloPodroznik\Html::e('epbuy_' . $tabToken) ?>"
            >
              <input type="hidden" name="tseVw" value="<?= \TyfloPodroznik\Html::e((string)($ticketHandoff['tseVw'] ?? 'regularP')) ?>">
              <input type="hidden" name="tabToken" value="<?= \TyfloPodroznik\Html::e($tabToken) ?>">
              <input type="hidden" name="fromV" value="<?= \TyfloPodroznik\Html::e((string)$ticketHandoff['fromV']) ?>">
              <input type="hidden" name="toV" value="<?= \TyfloPodroznik\Html::e((string)$ticketHandoff['toV']) ?>">
              <input type="hidden" name="tripType" value="one-way">
              <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.fromText" value="<?= \TyfloPodroznik\Html::e((string)($ticketHandoff['fromText'] ?? '')) ?>">
              <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.toText" value="<?= \TyfloPodroznik\Html::e((string)($ticketHandoff['toText'] ?? '')) ?>">
              <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.dateV" value="<?= \TyfloPodroznik\Html::e((string)$ticketHandoff['dateV']) ?>">
              <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.arrivalV" value="DEPARTURE">
              <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.timeV" value="<?= \TyfloPodroznik\Html::e($fromTime) ?>">
              <input type="hidden" name="minimalTimeForChangeV" value="<?= \TyfloPodroznik\Html::e((string)($ticketHandoff['minChange'] ?? '')) ?>">
              <?php if (!empty($ticketHandoff['preferDirects'])): ?>
                <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.preferDirects" value="true">
              <?php endif; ?>
              <?php if (!empty($ticketHandoff['onlyOnline'])): ?>
                <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.focusedOnSellable" value="true">
              <?php endif; ?>
              <?php foreach ((array)($ticketHandoff['carrierTypes'] ?? []) as $ct): ?>
                <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.carrierTypes" value="<?= \TyfloPodroznik\Html::e((string)$ct) ?>">
              <?php endforeach; ?>
              <button class="btn" type="submit">Kup bilet</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>
