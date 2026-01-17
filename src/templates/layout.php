<?php
/** @var string $title */
/** @var string $contentHtml */
/** @var \TyfloPodroznik\UiPrefs $ui */
/** @var string $csrf */
/** @var array|null $flash */
?>
<!doctype html>
<html lang="pl" class="<?= \TyfloPodroznik\Html::e($ui->htmlClass()) ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \TyfloPodroznik\Html::e($title) ?> — Podróżnik Tyflo</title>
    <link rel="stylesheet" href="/assets/app.css">
  </head>
  <body>
    <a class="skip-link" href="#main">Przejdź do treści</a>
    <header class="site" role="banner">
      <div class="wrap">
        <div class="bar">
          <div class="brand">
            <a href="/">
              <div class="title">Podróżnik Tyflo</div>
              <div class="subtitle">Dostępna wyszukiwarka połączeń</div>
            </a>
          </div>
          <nav class="ui-controls" aria-label="Nawigacja">
            <a class="btn small" href="/">Połączenia</a>
            <a class="btn small" href="/timetable">Rozkład z przystanku</a>
            <a class="btn small" href="<?= \TyfloPodroznik\Html::url('/contact', ['back' => (string)($_SERVER['REQUEST_URI'] ?? '/')]) ?>">Zgłoś problem</a>
          </nav>
          <form class="ui-controls" method="post" action="/ui">
            <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">
            <input type="hidden" name="back" value="<?= \TyfloPodroznik\Html::e(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/') ?>">
            <button class="btn small" type="submit" name="action" value="toggle_contrast">Kontrast</button>
            <button class="btn small" type="submit" name="action" value="font_dec" aria-label="Zmniejsz czcionkę">A−</button>
            <button class="btn small" type="submit" name="action" value="font_inc" aria-label="Zwiększ czcionkę">A+</button>
          </form>
        </div>
      </div>
    </header>

    <main id="main" class="wrap" tabindex="-1">
      <?php if (is_array($flash) && isset($flash['message'])): ?>
        <div class="card stack" role="status" aria-live="polite">
          <strong class="<?= \TyfloPodroznik\Html::e((string)($flash['level'] ?? '')) ?>">
            <?= \TyfloPodroznik\Html::e((string)$flash['message']) ?>
          </strong>
        </div>
      <?php endif; ?>

      <?= $contentHtml ?>
    </main>

    <footer class="site">
      <div class="wrap">
        <p>
          Źródło danych: <a href="https://www.e-podroznik.pl/">e‑podroznik.pl</a>. To jest niezależny frontend ukierunkowany na dostępność.
        </p>
      </div>
    </footer>
  </body>
</html>
