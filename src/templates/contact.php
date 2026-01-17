<?php
/** @var string $csrf */
/** @var array $defaults */
/** @var array $errors */
/** @var array|null $sent */

$kind = (string)($defaults['kind'] ?? 'bug');
$title = (string)($defaults['title'] ?? '');
$description = (string)($defaults['description'] ?? '');
$email = (string)($defaults['email'] ?? '');
$page = (string)($defaults['page'] ?? '');

$issueUrl = is_array($sent) ? (string)($sent['issueUrl'] ?? '') : '';
$reportId = is_array($sent) ? (string)($sent['reportId'] ?? '') : '';
?>

<div class="stack" id="contact">
  <h1>Kontakt / zgłoszenie</h1>

  <div class="help">
    Formularz tworzy publiczne zgłoszenie (issue) na GitHub.
    Jeśli podasz e‑mail lub link do strony, będzie widoczny publicznie.
  </div>

  <?php if ($issueUrl !== '' || $reportId !== ''): ?>
    <div class="card stack" role="status" aria-live="polite">
      <strong class="ok">Zgłoszenie wysłane.</strong>
      <?php if ($issueUrl !== ''): ?>
        <div>
          Issue: <a href="<?= \TyfloPodroznik\Html::e($issueUrl) ?>"><?= \TyfloPodroznik\Html::e($issueUrl) ?></a>
        </div>
      <?php endif; ?>
      <?php if ($reportId !== ''): ?>
        <div>Report ID: <code><?= \TyfloPodroznik\Html::e($reportId) ?></code></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="post" action="/contact/send" class="stack" novalidate>
      <input type="hidden" name="csrf" value="<?= \TyfloPodroznik\Html::e($csrf) ?>">

      <fieldset>
        <legend>Typ</legend>
        <div class="stack" role="radiogroup" aria-label="Typ zgłoszenia">
          <label>
            <input type="radio" name="kind" value="bug" <?= $kind === 'bug' ? 'checked' : '' ?>>
            Błąd
          </label>
          <label>
            <input type="radio" name="kind" value="suggestion" <?= $kind === 'suggestion' ? 'checked' : '' ?>>
            Sugestia
          </label>
          <?php if (!empty($errors['kind'])): ?>
            <div class="error"><?= \TyfloPodroznik\Html::e((string)$errors['kind']) ?></div>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="stack">
        <legend>Treść</legend>
        <div class="field">
          <label for="title">Tytuł</label>
          <input id="title" name="title" type="text" required value="<?= \TyfloPodroznik\Html::e($title) ?>">
          <?php if (!empty($errors['title'])): ?>
            <div class="error"><?= \TyfloPodroznik\Html::e((string)$errors['title']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="description">Opis</label>
          <textarea id="description" name="description" rows="8" required><?= \TyfloPodroznik\Html::e($description) ?></textarea>
          <div class="help">Opisz problem/sugestię i (jeśli to błąd) kroki odtworzenia.</div>
          <?php if (!empty($errors['description'])): ?>
            <div class="error"><?= \TyfloPodroznik\Html::e((string)$errors['description']) ?></div>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="stack">
        <legend>Dane (opcjonalnie)</legend>

        <div class="field">
          <label for="email">E‑mail (publiczny)</label>
          <input id="email" name="email" type="email" autocomplete="email" inputmode="email" value="<?= \TyfloPodroznik\Html::e($email) ?>">
          <div class="help">Jeśli podasz e‑mail, będzie widoczny publicznie w issue.</div>
          <?php if (!empty($errors['email'])): ?>
            <div class="error"><?= \TyfloPodroznik\Html::e((string)$errors['email']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="page">Link do strony (publiczny)</label>
          <input id="page" name="page" type="text" inputmode="url" autocomplete="off" value="<?= \TyfloPodroznik\Html::e($page) ?>">
          <div class="help">Jeśli podasz URL, ułatwi to diagnozę. Będzie widoczny publicznie w issue.</div>
          <?php if (!empty($errors['page'])): ?>
            <div class="error"><?= \TyfloPodroznik\Html::e((string)$errors['page']) ?></div>
          <?php endif; ?>
        </div>
      </fieldset>

      <div class="actions">
        <button class="btn primary" type="submit">Wyślij</button>
        <a class="btn" href="/">Wróć</a>
      </div>
    </form>
  </div>
</div>

