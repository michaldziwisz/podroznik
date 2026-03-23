<?php
/** @var string $title */
/** @var string $contentHtml */
/** @var \TyfloPodroznik\UiPrefs $ui */
/** @var string $csrf */
/** @var array|null $flash */

$assetVersion = static function (string $path): string {
    if (!is_file($path)) {
        return '';
    }
    $hash = @sha1_file($path);
    if (!is_string($hash) || $hash === '') {
        $mtime = @filemtime($path);
        return is_int($mtime) ? (string)$mtime : '';
    }
    return substr($hash, 0, 12);
};
$cssPath = __DIR__ . '/../../public/assets/app.css';
$jsPath = __DIR__ . '/../../public/assets/app.js';
$cssVer = $assetVersion($cssPath);
$jsVer = $assetVersion($jsPath);
$cssHref = '/assets/app.css' . ($cssVer !== '' ? ('?v=' . rawurlencode($cssVer)) : '');
$jsSrc = '/assets/app.js' . ($jsVer !== '' ? ('?v=' . rawurlencode($jsVer)) : '');
?>
<!doctype html>
<html lang="pl" class="<?= \TyfloPodroznik\Html::e($ui->htmlClass()) ?>">
  <head>
    <meta charset="utf-8">
	    <meta name="viewport" content="width=device-width, initial-scale=1">
	    <title><?= \TyfloPodroznik\Html::e($title) ?> — Podróżnik Tyflo</title>
	    <link rel="stylesheet" href="<?= \TyfloPodroznik\Html::e($cssHref) ?>">
	    <script src="<?= \TyfloPodroznik\Html::e($jsSrc) ?>" defer></script>
  </head>
  <body>
    <header class="site">
      <a class="skip-link" href="#main">Przejdź do treści</a>
      <div class="wrap">
        <div class="bar">
          <div class="brand">
            <a href="/">
              <span class="title">Podróżnik Tyflo</span>
              <span class="subtitle">Dostępna wyszukiwarka połączeń</span>
            </a>
          </div>
          <nav class="ui-controls site-nav">
            <a class="btn small" href="/">Połączenia</a>
            <a class="btn small" href="/timetable">Rozkład z przystanku</a>
            <a class="btn small" href="/contact">Zgłoś problem</a>
          </nav>
          <form class="ui-controls ui-prefs" method="post" action="/ui">
            <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">
            <input type="hidden" name="back" value="<?= \TyfloPodroznik\Html::e(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/') ?>">
            <button class="btn small" type="submit" name="action" value="toggle_contrast">Kontrast</button>
            <button class="btn small" type="submit" name="action" value="font_dec">Czcionka −</button>
            <button class="btn small" type="submit" name="action" value="font_inc">Czcionka +</button>
          </form>
        </div>
      </div>
    </header>

    <main id="main" class="wrap" tabindex="-1">
      <?php if (is_array($flash) && isset($flash['message'])): ?>
        <div class="card stack">
          <strong class="<?= \TyfloPodroznik\Html::e((string)($flash['level'] ?? '')) ?>">
            <?= \TyfloPodroznik\Html::e((string)$flash['message']) ?>
          </strong>
        </div>
      <?php endif; ?>

      <?= $contentHtml ?>
    </main>

    <footer class="site">
      <div class="wrap">
        <p><a href="https://www.e-podroznik.pl/">Źródło danych: e‑podroznik.pl</a></p>
        <p class="help">To jest niezależny frontend ukierunkowany na dostępność.</p>
      </div>
    </footer>
  </body>
</html>
