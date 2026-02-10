<?php
/** @var string $id */
/** @var array $details */
$sellable = (isset($sellable) && $sellable === true);
$ticketHandoff = (isset($ticketHandoff) && is_array($ticketHandoff)) ? $ticketHandoff : null;
$ticketHandoffOk = is_array($ticketHandoff)
  && is_string($ticketHandoff['fromV'] ?? null) && $ticketHandoff['fromV'] !== ''
  && is_string($ticketHandoff['toV'] ?? null) && $ticketHandoff['toV'] !== ''
  && is_string($ticketHandoff['dateV'] ?? null) && $ticketHandoff['dateV'] !== '';
$matchedResult = (isset($matchedResult) && is_array($matchedResult)) ? $matchedResult : null;
$fromTime = '';
if (is_array($matchedResult)) {
  $fromTime = (string)($matchedResult['from']['time'] ?? '');
}
?>
<div class="stack">
  <h1><?= \TyfloPodroznik\Html::e((string)($details['title'] ?? 'Szczegóły trasy')) ?></h1>
  <p class="help">ID wyniku: <?= \TyfloPodroznik\Html::e($id) ?></p>

  <div class="actions">
    <a class="btn" href="/results#results">Wróć do wyników</a>
    <a class="btn" href="/">Nowe wyszukiwanie</a>
    <?php if ($sellable && $ticketHandoffOk): ?>
      <?php
        $tabToken = bin2hex(random_bytes(16));
        $defineTicketUrl = 'https://www.e-podroznik.pl/public/defineTicketP.do?tabToken='
          . rawurlencode($tabToken)
          . '&resId=' . rawurlencode($id)
          . '&forward=url';
        $searchAction = 'https://www.e-podroznik.pl/public/searchingResults.do?method=task';
      ?>
      <form
        method="post"
        action="<?= \TyfloPodroznik\Html::e($searchAction) ?>"
        target="_blank"
        class="ep-ticket-handoff"
        aria-label="Kup bilet — szczegóły połączenia"
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
        <?php if ($fromTime !== ''): ?>
          <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.timeV" value="<?= \TyfloPodroznik\Html::e($fromTime) ?>">
        <?php else: ?>
          <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.timeV" value="">
          <input type="hidden" name="formCompositeSearchingResults.formCompositeSearcherFinalH.ommitTime" value="on">
        <?php endif; ?>
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

  <?php if (!empty($details['hints'])): ?>
    <section class="card stack" aria-label="Podpowiedzi nawigacji">
      <h2>Podpowiedzi</h2>
      <ol>
        <?php foreach ($details['hints'] as $h): ?>
          <li><?= \TyfloPodroznik\Html::e((string)$h) ?></li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <?php foreach (($details['segments'] ?? []) as $i => $seg): ?>
    <?php
      $carrier = (string)($seg['carrier'] ?? '');
      $line = (string)($seg['line'] ?? '');
      $duration = (string)($seg['duration'] ?? '');
    ?>
    <section class="card stack" aria-label="Odcinek <?= (int)$i + 1 ?>">
      <h2>Odcinek <?= (int)$i + 1 ?></h2>
      <dl>
        <?php if ($line !== ''): ?><dt>Linia / typ</dt><dd><?= \TyfloPodroznik\Html::e($line) ?></dd><?php endif; ?>
        <?php if ($carrier !== ''): ?><dt>Przewoźnik</dt><dd><?= \TyfloPodroznik\Html::e($carrier) ?></dd><?php endif; ?>
        <?php if ($duration !== ''): ?><dt>Czas odcinka</dt><dd><?= \TyfloPodroznik\Html::e($duration) ?></dd><?php endif; ?>
      </dl>

      <?php if (!empty($seg['remarks'])): ?>
        <details>
          <summary>Uwagi (np. udogodnienia)</summary>
          <ul>
            <?php foreach ($seg['remarks'] as $r): ?>
              <li><?= \TyfloPodroznik\Html::e((string)$r) ?></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>

      <?php if (!empty($seg['stops'])): ?>
        <details open>
          <summary>Przystanki (<?= (int)count($seg['stops']) ?>)</summary>
          <table>
            <caption>Przystanki</caption>
            <thead>
              <tr>
                <th scope="col">Godzina</th>
                <th scope="col">Przystanek</th>
                <th scope="col">Przyjazd</th>
                <th scope="col">Odjazd</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($seg['stops'] as $s): ?>
                <tr>
                  <td><?= \TyfloPodroznik\Html::e((string)($s['routeTime'] ?? '')) ?></td>
                  <td><?= \TyfloPodroznik\Html::e((string)($s['name'] ?? '')) ?></td>
                  <td><?= \TyfloPodroznik\Html::e((string)($s['arrival'] ?? '')) ?></td>
                  <td><?= \TyfloPodroznik\Html::e((string)($s['departure'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
