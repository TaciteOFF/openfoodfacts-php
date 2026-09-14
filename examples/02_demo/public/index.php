<?php
/**
 * Single HTML page of the demo.
 *
 * It only needs PHP for two things: starting the hardened session (bootstrap.php) and printing
 * the CSRF token into a <meta> tag. Everything else is static markup progressively filled in by
 * assets/app.js; there is no inline script, which is what allows the strict
 * `script-src 'self'` Content-Security-Policy set in bootstrap.php.
 *
 * Structure of the page:
 *   header          brand, links to the wrapper repository and its docs, account button
 *   workspace       environment switch (production / staging) + the three tool tabs
 *     panel-product barcode form, language selector, rendered product
 *     panel-search  query form, result grid, pagination
 *     panel-upload  gated photo upload form (staging + credentials required)
 *   response-card   raw JSON returned by the wrapper, with timing and copy button
 *   dialogs         account credentials, and the staging row editor
 *
 * The page ships in two interface languages, French and English. Every visible string comes
 * from the catalogue in `app/i18n.php` through `e()` (translate + HTML-escape), the chosen
 * locale is stamped on <html lang> so `assets/i18n.js` can pick it up for the strings the
 * browser renders, and the FR/EN switch in the header posts the `interface` action and reloads.
 *
 * The interface language is independent from the product language: the selector in the Product
 * tab decides which translation of the *data* is requested from Open Food Facts.
 *
 * @package off-lab/playground
 * @license MIT
 */
require __DIR__ . '/bootstrap.php'; ?>
<!doctype html>
<html lang="<?= htmlspecialchars(ui_locale(), ENT_QUOTES) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e('ui.meta_description') ?>">
<!-- Read by app.js and sent back as the X-CSRF-Token header on every POST to api.php. -->
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
<title>openfoodfacts-php — demo</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<!-- Load order matters: i18n.js first, then the two pure modules, then the controller. -->
<link rel="stylesheet" href="assets/app.css"><script src="assets/i18n.js" defer></script><script src="assets/product-data.js" defer></script><script src="assets/product-editor.js" defer></script><script src="assets/app.js" defer></script>
</head>
<body>
<!-- Inline SVG sprite: every icon in the page is a <use href="#id"> of one of these symbols. -->
<svg class="icons" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><symbol id="barcode" viewBox="0 0 24 24"><path d="M3 5v14M6 5v14M10 5v14M12 5v14M16 5v14M20 5v14M22 5v14"/></symbol><symbol id="search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></symbol><symbol id="photo" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="15" rx="3"/><circle cx="12" cy="12" r="3"/><path d="m8 5 2-3h4l2 3"/></symbol><symbol id="arrow" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol><symbol id="user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></symbol><symbol id="code" viewBox="0 0 24 24"><path d="m8 6-6 6 6 6m8-12 6 6-6 6m-2-16-4 20"/></symbol><symbol id="lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 4v3"/></symbol><symbol id="upload" viewBox="0 0 24 24"><path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/></symbol></svg>
<header class="header"><div class="container nav">
<a href="./" class="brand">PHP demo</a>
<nav aria-label="<?= e('ui.nav_links') ?>"><a href="https://github.com/TaciteOFF/openfoodfacts-php" target="_blank" rel="noreferrer">GitHub ↗</a><a href="https://github.com/TaciteOFF/openfoodfacts-php/tree/develop/doc" target="_blank" rel="noreferrer">Docs ↗</a>
<!-- Interface language. Posts the `interface` action, then reloads so PHP re-renders the markup. -->
<div class="segmented ui-language" role="group" aria-label="<?= e('ui.interface_language') ?>"><?php foreach (UI_LOCALES as $code): ?><button type="button" data-ui-lang="<?= $code ?>" aria-pressed="<?= ui_locale() === $code ? 'true' : 'false' ?>"><?= strtoupper($code) ?></button><?php endforeach; ?></div>
<button class="button" id="account-open"><svg aria-hidden="true"><use href="#user"/></svg><span id="account-label"><?= e('ui.account') ?></span></button></nav>
</div></header>
<main>

<!-- Workspace: environment switch, tool tabs, and the response panel side by side. -->
<section class="workspace container" aria-labelledby="workspace-title">
<div class="workspace-heading"><h1 id="workspace-title">openfoodfacts-php <span>API v3.6</span></h1><div class="environment"><div class="segmented" role="group" aria-label="<?= e('ui.env_group') ?>"><button data-env="prod" aria-pressed="true"><?= e('ui.production') ?></button><button data-env="staging" aria-pressed="false"><?= e('ui.staging') ?></button></div></div></div>
<div class="environment-note" id="environment-note"><?= e('ui.env_note_prod') ?></div>
<div class="workspace-grid"><section class="tool-card">
<div class="tabs" role="tablist" aria-label="<?= e('ui.features') ?>"><button role="tab" id="tab-product" data-tab="product" aria-selected="true" aria-controls="panel-product"><svg><use href="#barcode"/></svg><?= e('ui.tab_product') ?></button><button role="tab" id="tab-search" data-tab="search" aria-selected="false" aria-controls="panel-search" tabindex="-1"><svg><use href="#search"/></svg><?= e('ui.tab_search') ?></button><button role="tab" id="tab-upload" data-tab="upload" aria-selected="false" aria-controls="panel-upload" tabindex="-1"><svg><use href="#photo"/></svg><?= e('ui.tab_upload') ?></button></div>
<!-- Product tab: getProduct() - barcode, language, and the full rendered product. -->
<div id="panel-product" role="tabpanel" aria-labelledby="tab-product" class="panel">

<form id="product-form"><label for="barcode-input"><?= e('ui.barcode') ?></label><div class="input-action"><div class="input-icon"><svg><use href="#barcode"/></svg><input id="barcode-input" name="barcode" inputmode="numeric" pattern="[0-9]{4,24}" maxlength="24" value="3057640385148" placeholder="<?= e('ui.barcode_placeholder') ?>" required></div><button class="button primary" type="submit"><?= e('ui.run') ?></button></div><div class="form-bottom"><div class="examples"><span><?= e('ui.examples') ?></span><button type="button" data-example="3057640385148">Volvic</button><button type="button" data-example="3017620422003">Nutella</button><button type="button" data-example="7622210449283">Oreo</button></div><label class="language-label" for="product-language"><?= e('ui.language') ?> <select id="product-language"><option value="fr">FR</option><option value="en">EN</option><option value="de">DE</option><option value="es">ES</option><option value="it">IT</option></select></label></div></form>
<div id="product-result" class="product-result"><div class="empty-state"><?= e('ui.no_result') ?></div></div>
</div>

<!-- Search tab: search() - 12 results per page. -->
<div id="panel-search" role="tabpanel" aria-labelledby="tab-search" class="panel" hidden><form id="search-form"><label for="search-input"><?= e('ui.search_label') ?></label><div class="input-action"><div class="input-icon"><svg><use href="#search"/></svg><input id="search-input" placeholder="<?= e('ui.search_placeholder') ?>" minlength="2" maxlength="100" required></div><button class="button primary"><?= e('ui.run') ?></button></div></form><div id="search-result" class="search-result"></div><div id="pagination" class="pagination" hidden><button id="prev-page" class="button"><?= e('ui.previous') ?></button><span id="page-label"></span><button id="next-page" class="button"><?= e('ui.next') ?></button></div></div>
<!-- Upload tab: uploadImage() - staging only; the fieldset stays disabled until the gate passes. -->
<div id="panel-upload" role="tabpanel" aria-labelledby="tab-upload" class="panel" hidden><div id="upload-gate" class="notice"></div><form id="upload-form"><fieldset id="upload-fields" disabled><div class="form-row"><div><label for="upload-barcode"><?= e('ui.barcode') ?></label><input id="upload-barcode" name="barcode" inputmode="numeric" pattern="[0-9]{4,24}" maxlength="24" placeholder="<?= e('ui.upload_barcode_placeholder') ?>" required></div><div><label for="photo-field"><?= e('ui.image_field') ?></label><select id="photo-field" name="field"><option value="front"><?= e('ui.field_front') ?></option><option value="ingredients"><?= e('ui.field_ingredients') ?></option><option value="nutrition"><?= e('ui.field_nutrition') ?></option><option value="packaging"><?= e('ui.field_packaging') ?></option></select></div></div><label class="dropzone" id="dropzone" for="photo-input"><svg><use href="#upload"/></svg><strong id="file-label"><?= e('ui.choose_photo') ?></strong><span><?= e('ui.photo_constraint') ?></span><input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png" required></label><img id="photo-preview" alt="<?= e('ui.photo_preview_alt') ?>" hidden><div class="upload-bottom"><label for="photo-language"><?= e('ui.language') ?> <select id="photo-language" name="language"><option value="fr"><?= e('ui.lang_fr') ?></option><option value="en"><?= e('ui.lang_en') ?></option><option value="de"><?= e('ui.lang_de') ?></option><option value="es"><?= e('ui.lang_es') ?></option><option value="it"><?= e('ui.lang_it') ?></option></select></label><button class="button primary" type="submit"><?= e('ui.send') ?></button></div><p class="help"><?= e('ui.upload_help') ?></p></fieldset></form><div id="upload-result" role="status"></div></div>
</section>
<!-- Response panel: the raw JSON the wrapper returned, plus method, status and duration. -->
<aside class="response-card" aria-label="<?= e('ui.response_aria') ?>"><div class="response-heading"><div><svg><use href="#code"/></svg><h2><?= e('ui.response') ?></h2></div><span class="json-tag">JSON</span></div><div class="request-label"><span id="request-method">getProduct()</span><span id="response-status"><?= e('ui.pending') ?></span></div><pre id="response-json" tabindex="0"><?= e('ui.no_request_comment') ?></pre><div class="response-footer"><span id="response-time"><?= e('ui.no_request') ?></span><button id="copy-json" disabled><?= e('ui.copy_json') ?></button></div></aside></div>
<div id="feedback" class="feedback" role="status" aria-live="polite"></div>
</section></main>
<footer class="site-footer container"><span><?= e('ui.footer_wrapper') ?> <a href="https://github.com/TaciteOFF/openfoodfacts-php" target="_blank" rel="noreferrer">TaciteOFF</a></span><span><?= e('ui.footer_data') ?> <a href="https://world.openfoodfacts.org" target="_blank" rel="noreferrer">Open Food Facts</a> · <a href="https://opendatacommons.org/licenses/odbl/1-0/" target="_blank" rel="noreferrer">ODbL</a> · <?= e('ui.footer_photos') ?></span></footer>
<!-- Account dialog: credentials are kept in the PHP session only, never in the browser. -->
<dialog id="account-dialog"><div class="dialog-head"><h2><?= e('ui.account_dialog_title') ?></h2><button class="close-button" id="account-close" aria-label="<?= e('ui.close') ?>">×</button></div><p id="account-environment"></p><div class="notice"><code>authentification()</code> <?= e('ui.auth_notice') ?></div><form id="account-form"><label for="username"><?= e('ui.username') ?></label><input id="username" name="username" autocomplete="username" maxlength="100" required><label for="password"><?= e('ui.password') ?></label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="500" required><p class="help"><?= e('ui.account_help') ?></p><button class="button primary full" type="submit"><?= e('ui.save') ?></button></form><div id="account-saved" hidden><p id="account-state"></p><button class="button full" id="logout"><?= e('ui.logout') ?></button></div><p id="account-message" role="status"></p></dialog>
<!-- Editor dialog: one row at a time, with a live preview of the patch that will be sent. -->
<dialog id="editor-dialog" aria-labelledby="editor-title">
<div class="dialog-head"><h2 id="editor-title"><?= e('ui.editor_title') ?></h2><button class="close-button" id="editor-close" aria-label="<?= e('ui.editor_close') ?>">×</button></div>
<p id="editor-context"></p>
<form id="editor-form"><fieldset id="editor-fields">
<div id="editor-language-controls"><div class="editor-language"><label for="editor-language"><?= e('ui.language') ?></label><select id="editor-language"></select><input id="editor-new-language" aria-label="<?= e('ui.new_language') ?>" placeholder="<?= e('ui.new_language_placeholder') ?>" maxlength="6"><button class="button" id="editor-add-language" type="button"><?= e('ui.add') ?></button></div></div>
<p class="help" id="editor-help"></p>
<div id="editor-text-fields"></div><div id="editor-nutrients"></div>
</fieldset>
<details class="data-section"><summary><?= e('ui.patch') ?> <span id="editor-count">0</span></summary><pre id="editor-patch" tabindex="0">{}</pre></details>
<div id="editor-message" role="status" aria-live="polite"></div><pre id="editor-response" tabindex="0" hidden></pre>
<div class="editor-actions"><button class="button" id="editor-reset" type="button"><?= e('ui.reset') ?></button><button class="button primary" id="editor-save" type="submit" disabled><?= e('ui.save_staging') ?></button></div>
</form></dialog>
<noscript><?= e('ui.noscript') ?></noscript>
</body></html>
