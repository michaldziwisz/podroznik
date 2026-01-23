<?php
/** @var string $resId */
/** @var array $matchedResult */
/** @var array $ticketHandoff */
$ticketHandoff = (isset($ticketHandoff) && is_array($ticketHandoff)) ? $ticketHandoff : null;
$ticketHandoffOk = is_array($ticketHandoff)
  && is_string($ticketHandoff['fromV'] ?? null) && $ticketHandoff['fromV'] !== ''
  && is_string($ticketHandoff['toV'] ?? null) && $ticketHandoff['toV'] !== ''
  && is_string($ticketHandoff['dateV'] ?? null) && $ticketHandoff['dateV'] !== '';
$matchedResult = (isset($matchedResult) && is_array($matchedResult)) ? $matchedResult : null;

$fromStop = is_array($matchedResult) ? (string)($matchedResult['from']['stop'] ?? '') : '';
$toStop = is_array($matchedResult) ? (string)($matchedResult['to']['stop'] ?? '') : '';
$fromTime = is_array($matchedResult) ? (string)($matchedResult['from']['time'] ?? '') : '';
$toTime = is_array($matchedResult) ? (string)($matchedResult['to']['time'] ?? '') : '';
?>

<div class="stack">
  <h1>Zakup biletu</h1>
  <p class="help">
    Zakup odbywa się w serwisie e‑podroznik.pl. Ta strona otworzy e‑podroznik.pl w nowej karcie i spróbuje przejść bezpośrednio do zakupu.
  </p>

  <?php if ($fromTime !== '' || $toTime !== '' || $fromStop !== '' || $toStop !== ''): ?>
    <div class="card stack" role="status" aria-live="polite">
      <strong>Wybrane połączenie:</strong>
      <div><?= \TyfloPodroznik\Html::e(trim($fromTime . ' ' . $fromStop)) ?> → <?= \TyfloPodroznik\Html::e(trim($toTime . ' ' . $toStop)) ?></div>
      <div class="help">ID: <?= \TyfloPodroznik\Html::e($resId) ?></div>
    </div>
  <?php endif; ?>

  <div class="actions">
    <a class="btn" href="/results#results">Wróć do wyników</a>
    <a class="btn" href="/result?id=<?= \TyfloPodroznik\Html::e(rawurlencode($resId)) ?>">Szczegóły</a>
    <?php if ($ticketHandoffOk): ?>
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
        <button class="btn primary" type="submit">Otwórz zakup biletu w e‑podroznik.pl</button>
      </form>
    <?php endif; ?>
  </div>
</div>

