<?php
/** @var string $id */
/** @var array $details */
$buyUrl = isset($buyUrl) && is_string($buyUrl) ? trim($buyUrl) : '';
?>
<div class="stack">
  <h1><?= \TyfloPodroznik\Html::e((string)($details['title'] ?? 'Szczegóły trasy')) ?></h1>
  <p class="help">ID wyniku: <?= \TyfloPodroznik\Html::e($id) ?></p>

  <div class="actions">
    <a class="btn" href="/results#results">Wróć do wyników</a>
    <a class="btn" href="/">Nowe wyszukiwanie</a>
    <?php if ($buyUrl !== ''): ?>
      <a class="btn" href="<?= \TyfloPodroznik\Html::e($buyUrl) ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Kup bilet (e‑podroznik.pl)</a>
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
