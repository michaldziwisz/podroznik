<?php
/** @var string $title */
/** @var string $message */
?>
<div class="stack">
  <h1><?= \TyfloPodroznik\Html::e($title) ?></h1>
  <div class="card">
    <p class="error"><?= \TyfloPodroznik\Html::e($message) ?></p>
    <p><a class="btn" href="/">Wróć do wyszukiwarki</a></p>
  </div>
</div>

