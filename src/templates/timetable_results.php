<?php
/** @var string $csrf */
/** @var array $timetable */
/** @var array $filters */

$stopName = (string)($timetable['stop']['name'] ?? '');
$stopCity = (string)($timetable['stop']['city'] ?? '');
$stopId = (string)($timetable['stop']['stopId'] ?? '');
$stopOptions = (array)($timetable['stopOptions'] ?? []);
$groups = (array)($timetable['destinations'] ?? []);

$date = (string)($filters['date'] ?? '');
$fromTime = (string)($filters['from_time'] ?? '');
$toTime = (string)($filters['to_time'] ?? '');

$hasAnyCourseInfo = false;
foreach ($groups as $g) {
    $deps = (array)($g['departures'] ?? []);
    foreach ($deps as $d) {
        $valid = (string)($d['validity'] ?? '');
        $notes = (array)($d['notes'] ?? []);
        if ($valid !== '' || !empty($notes)) {
            $hasAnyCourseInfo = true;
            break 2;
        }
    }
}
?>
<div class="stack" id="timetable">
  <h1>Rozkład jazdy</h1>

  <div class="card stack" role="status" aria-live="polite">
    <div>
      <strong>Przystanek:</strong>
      <?= \TyfloPodroznik\Html::e($stopName !== '' ? $stopName : $stopId) ?>
      <?php if ($stopCity !== ''): ?>
        <span class="help">— <?= \TyfloPodroznik\Html::e($stopCity) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($date !== ''): ?>
      <div><strong>Dzień:</strong> <?= \TyfloPodroznik\Html::e($date) ?></div>
    <?php endif; ?>
    <?php if ($fromTime !== '' || $toTime !== ''): ?>
      <div><strong>Godzina:</strong> <?= \TyfloPodroznik\Html::e($fromTime !== '' ? $fromTime : '00:00') ?><?= $toTime !== '' ? '–' . \TyfloPodroznik\Html::e($toTime) : '' ?></div>
    <?php endif; ?>

    <form method="get" action="/timetable/results" class="stack" novalidate aria-label="Zmień ustawienia rozkładu">
      <fieldset class="grid-2">
        <legend>Zmień ustawienia</legend>
        <div class="field">
          <label for="stopId">Przystanek</label>
          <select id="stopId" name="stopId">
            <?php foreach ($stopOptions as $o): ?>
              <?php
                $oid = (string)($o['id'] ?? '');
                $olabel = (string)($o['label'] ?? '');
                $ogroup = (string)($o['group'] ?? '');
                $oselected = ($oid !== '' && $oid === $stopId) ? 'selected' : '';
                $txt = $ogroup !== '' ? ($ogroup . ' — ' . $olabel) : $olabel;
              ?>
              <option value="<?= \TyfloPodroznik\Html::e($oid) ?>" <?= $oselected ?>><?= \TyfloPodroznik\Html::e($txt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="date">Dzień</label>
          <input id="date" name="date" type="date" required value="<?= \TyfloPodroznik\Html::e($date) ?>">
        </div>
        <div class="field">
          <label for="from_time">Godzina od</label>
          <input id="from_time" name="from_time" type="time" step="60" autocomplete="off" value="<?= \TyfloPodroznik\Html::e($fromTime) ?>">
        </div>
        <div class="field">
          <label for="to_time">Godzina do</label>
          <input id="to_time" name="to_time" type="time" step="60" autocomplete="off" value="<?= \TyfloPodroznik\Html::e($toTime) ?>">
        </div>
      </fieldset>
      <div class="actions">
        <button class="btn primary" type="submit">Odśwież</button>
        <a class="btn" href="/timetable">Inny przystanek</a>
        <a class="btn" href="/">Wyszukiwarka połączeń</a>
      </div>
    </form>
  </div>

  <?php if (!$hasAnyCourseInfo): ?>
    <div class="help">
      Uwaga: dla tego rozkładu e‑podroznik.pl nie podaje dodatkowych informacji o kursowaniu (np. „kursuje w …”), więc filtr dnia może nie odfiltrować kursów, które w rzeczywistości nie jeżdżą w wybranym dniu.
    </div>
  <?php endif; ?>

  <?php if (empty($groups)): ?>
    <div class="card">
      <p class="warn">Brak odjazdów dla wybranych parametrów.</p>
    </div>
  <?php endif; ?>

  <div class="results" aria-label="Kierunki i odjazdy">
    <?php foreach ($groups as $g): ?>
      <?php
        $dest = (string)($g['destination'] ?? '');
        $through = (array)($g['through'] ?? []);
        $deps = (array)($g['departures'] ?? []);
      ?>
      <article class="result" aria-label="Kierunek <?= \TyfloPodroznik\Html::e($dest) ?>">
        <h3><?= \TyfloPodroznik\Html::e($dest !== '' ? $dest : 'Kierunek') ?></h3>
        <?php if (!empty($through)): ?>
          <div class="help">Przez: <?= \TyfloPodroznik\Html::e(implode(', ', $through)) ?></div>
        <?php endif; ?>

        <?php if (empty($deps)): ?>
          <div class="help">Brak odjazdów w wybranym zakresie.</div>
        <?php else: ?>
          <div class="stack">
            <?php foreach ($deps as $d): ?>
              <?php
                $time = (string)($d['time'] ?? '');
                $carrier = (string)($d['carrier'] ?? '');
                $valid = (string)($d['validity'] ?? '');
                $notes = (array)($d['notes'] ?? []);
              ?>
              <?php if ($valid === '' && empty($notes)): ?>
                <div class="card">
                  <strong><?= \TyfloPodroznik\Html::e($time) ?></strong>
                  <?php if ($carrier !== ''): ?> — <?= \TyfloPodroznik\Html::e($carrier) ?><?php endif; ?>
                </div>
              <?php else: ?>
                <details class="card">
                  <summary>
                    <strong><?= \TyfloPodroznik\Html::e($time) ?></strong>
                    <?php if ($carrier !== ''): ?> — <?= \TyfloPodroznik\Html::e($carrier) ?><?php endif; ?>
                  </summary>
                  <?php if ($valid !== ''): ?>
                    <div class="help"><strong>Kursowanie:</strong> <?= \TyfloPodroznik\Html::e($valid) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($notes)): ?>
                    <ul class="help">
                      <?php foreach ($notes as $n): ?>
                        <?php if (trim((string)$n) === '') continue; ?>
                        <li><?= \TyfloPodroznik\Html::e((string)$n) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </details>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</div>
