<?php
/**
 * Single JSON endpoint of the demo. The browser talks to this file and to nothing else.
 *
 * Protocol
 * --------
 *  GET  api.php  -> {ok:true, state:{...}}            bootstrap of the front end
 *  POST api.php  -> {ok:bool, data?, state, meta?}    one `action` per request
 *
 * Every POST carries the session CSRF token in the `X-CSRF-Token` header and is either a JSON
 * body or, for the photo upload, a `multipart/form-data` body.
 *
 * Actions
 * -------
 *  environment  switch between production (read-only) and staging (read/write)
 *  interface    switch the interface language (fr / en); touches no Open Food Facts call
 *  login        store OFF credentials in the PHP session (see the caveat below)
 *  logout       drop the stored credentials
 *  product      wrapper `getProduct()`    - full product, all fields, localized
 *  search       wrapper `search()`        - 12 results per page
 *  update       wrapper `updateProduct()` - staging only, allow-listed patch
 *  upload       wrapper `uploadImage()`   - staging only, JPEG/PNG up to 5 MB
 *
 * Invariants enforced here, regardless of what the browser sends:
 *  - writes require `environment === 'staging'` **and** stored credentials;
 *  - per-session rate limits (read 1 s, search 6 s, write 5 s);
 *  - every payload returned to the browser goes through `redact()`;
 *  - exception messages coming from upstream are never logged verbatim, because Open Food
 *    Facts can echo request details that include credentials.
 *
 * Authentication caveat: `Api::authentification()` only stores the credentials, it performs no
 * verification request. The session therefore keeps `verified = false` until a write actually
 * succeeds, and the UI says so. No direct OFF endpoint is called to work around this.
 *
 * @package off-lab/playground
 * @license MIT
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/app/product-patch.php';
use OpenFoodFacts\Exception\ProductNotFoundException;
use OpenFoodFacts\Exception\ProductUpdateException;
use OpenFoodFacts\Exception\BadRequestException;

// Bootstrap call: the page asks for its own session state (environment, username, CSRF token).
if ($_SERVER['REQUEST_METHOD'] === 'GET') reply(['ok' => true, 'state' => state()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['ok' => false, 'message' => t('err.method')], 405);
// Constant-time CSRF check on every state-changing request. A stale token means the session
// was rotated or expired, so the user is asked to reload rather than silently re-authenticated.
if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    reply(['ok' => false, 'message' => t('err.session_expired')], 403);
}
// Measured here so the `meta.duration` reported in the UI covers validation + wrapper call.
$start = microtime(true);
try {
    // Two body shapes: multipart for the photo upload (PHP fills $_POST/$_FILES), JSON otherwise.
    // Depth is capped at 32 to keep a hostile payload from exhausting the parser.
    $multipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $data = $multipart ? $_POST : json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException(t('err.bad_request'));
    $action = input($data, 'action', 30);
    // --- environment -------------------------------------------------------------------
    // Switching environments is destructive on purpose: credentials are dropped and the session
    // id rotated, so a production account can never leak into a staging write (or the reverse).
    if ($action === 'environment') {
        $env = input($data, 'environment', 10);
        if (!in_array($env, ['prod', 'staging'], true)) throw new InvalidArgumentException(t('err.bad_environment'));
        if ($env !== $_SESSION['env']) {
            unset($_SESSION['credentials'], $_SESSION['verified']);
            $_SESSION['env'] = $env;
            session_regenerate_id(true);
        }
        reply(['ok' => true, 'state' => state()]);
    }
    // --- login -------------------------------------------------------------------------
    // The wrapper call is made only to surface an immediate argument error; it validates nothing
    // upstream. `verified` stays false until an actual write is accepted by Open Food Facts.
    if ($action === 'login') {
        $username = input($data, 'username', 100);
        $password = input($data, 'password', 500);
        if ($username === '' || $password === '') throw new InvalidArgumentException(t('err.credentials_required'));
        api()->authentification($username, $password);
        session_regenerate_id(true);
        $_SESSION['credentials'] = compact('username', 'password');
        $_SESSION['verified'] = false;
        reply(['ok' => true, 'state' => state(), 'message' => t('msg.credentials_stored')]);
    }
    // --- interface ---------------------------------------------------------------------
    // Interface language only. It changes the language the *page* speaks (markup, messages),
    // never the language the product data is requested in, and never any OFF call. The browser
    // reloads afterwards so the server re-renders the markup in the new locale.
    if ($action === 'interface') {
        $ui = input($data, 'language', 5);
        if (!in_array($ui, UI_LOCALES, true)) throw new InvalidArgumentException(t('err.bad_interface_language'));
        $_SESSION['ui'] = $ui;
        reply(['ok' => true, 'state' => state()]);
    }
    // --- logout ------------------------------------------------------------------------
    if ($action === 'logout') {
        unset($_SESSION['credentials'], $_SESSION['verified']);
        session_regenerate_id(true);
        reply(['ok' => true, 'state' => state()]);
    }
    if (!in_array($action, ['product', 'search', 'upload', 'update'], true)) throw new InvalidArgumentException(t('err.unknown_action'));
    // Enforced here, regardless of anything sent by the browser.
    if (in_array($action, ['upload', 'update'], true) && $_SESSION['env'] !== 'staging') {
        reply(['ok' => false, 'message' => t('err.write_production')], 403);
    }
    if (in_array($action, ['upload', 'update'], true) && !isset($_SESSION['credentials'])) {
        reply(['ok' => false, 'message' => t('err.account_required')], 401);
    }
    // Per-session, per-action throttle. This protects the OFF servers from an over-eager UI;
    // a public deployment should add a global limit at the reverse-proxy level as well.
    $last = $_SESSION['last_call'][$action] ?? 0;
    $delay = $action === 'search' ? 6 : (in_array($action, ['upload', 'update'], true) ? 5 : 1);
    if (microtime(true) - $last < $delay) reply(['ok' => false, 'message' => t('err.rate_limited')], 429);
    $_SESSION['last_call'][$action] = microtime(true);
    $api = api();
    // --- product: getProduct() ----------------------------------------------------------
    // `fields = null` asks the wrapper for the complete product. The language code is passed
    // both as `lc` (localized text) and as `tagsLc` (localized taxonomy labels).
    if ($action === 'product') {
        $code = barcode($data);
        $lc = strtolower(input($data, 'language', 6)) ?: 'fr';
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D', $lc)) throw new InvalidArgumentException(t('err.bad_language'));
        $payload = $api->getProduct($code, null, $lc, null, $lc)->getData();
        $method = 'getProduct';
    // --- update: updateProduct() --------------------------------------------------------
    // The patch is validated first (see app/product-patch.php); nothing reaches the wrapper
    // unless every field is allow-listed. A successful write is the only proof we get that the
    // stored credentials are valid, hence `verified = true`.
    } elseif ($action === 'update') {
        $code = barcode($data);
        $lc = strtolower(input($data, 'language', 6));
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D', $lc)) throw new InvalidArgumentException(t('err.bad_language'));
        $patch = validateProductPatch($data['product'] ?? null);
        $payload = $api->updateProduct($code, $patch, ['all'], $lc, null, $lc);
        $_SESSION['verified'] = true;
        $method = 'updateProduct';
    // --- search: search() ----------------------------------------------------------------
    // The wrapper returns a lazy collection; it is materialised here into a plain array so the
    // response mirrors exactly what the UI renders. Page size is fixed at 12.
    } elseif ($action === 'search') {
        $query = input($data, 'query', 100);
        if (strlen($query) < 2) throw new InvalidArgumentException(t('err.query_short'));
        $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false) throw new InvalidArgumentException(t('err.bad_page'));
        $collection = $api->search($query, $page, 12);
        $products = [];
        foreach ($collection as $product) $products[] = $product->getData();
        $payload = ['products' => $products, 'count' => $collection->searchCount(), 'page' => $page, 'page_size' => 12];
        $method = 'search';
    // --- upload: uploadImage() ------------------------------------------------------------
    // Creates the product if it does not exist yet in staging, then selects the uploaded photo
    // for the given image field and language. Both lists below are deliberately closed.
    } else {
        $code = barcode($data);
        $field = input($data, 'field', 20);
        $lc = input($data, 'language', 2);
        if (!in_array($field, ['front', 'ingredients', 'nutrition', 'packaging'], true) || !in_array($lc, ['fr', 'en', 'de', 'es', 'it'], true)) {
            throw new InvalidArgumentException(t('err.bad_photo_field'));
        }
        // Three independent checks: upload status, declared size, then the *real* content type
        // (finfo) plus getimagesize(), so a renamed file or a non-image payload is rejected
        // before the bytes are handed to the wrapper.
        $file = $_FILES['photo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException(t('err.file_missing'));
        if ($file['size'] > 5 * 1024 * 1024) throw new InvalidArgumentException(t('err.file_too_large'));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !getimagesize($file['tmp_name'])) throw new InvalidArgumentException(t('err.file_type'));
        $payload = $api->uploadImage($code, $field, $file['tmp_name'], $lc);
        $_SESSION['verified'] = true;
        $method = 'uploadImage';
    }
    // Success envelope: redacted payload + fresh session state + call metadata for the UI panel.
    reply(['ok' => true, 'data' => redact($payload), 'state' => state(), 'meta' => ['method' => $method, 'environment' => $_SESSION['env'], 'duration' => (int)round((microtime(true) - $start) * 1000)]]);
// --- Error mapping: wrapper exceptions become stable HTTP statuses + user-facing messages ---
} catch (ProductNotFoundException $e) {
    reply(['ok' => false, 'message' => t('err.not_found')], 404);
// A write can be refused or only partially applied; the raw upstream response is surfaced
// (redacted) so the developer can inspect what OFF actually accepted.
} catch (ProductUpdateException $e) {
    reply(['ok' => false, 'message' => t('err.update_refused'), 'data' => redact($e->getResponse())], 422);
} catch (InvalidArgumentException | JsonException $e) {
    reply(['ok' => false, 'message' => $e instanceof JsonException ? t('err.bad_json') : $e->getMessage()], 400);
// Upstream rejected the request itself (bad environment, bad credentials, rate limiting...).
} catch (BadRequestException $e) {
    reply(['ok' => false, 'message' => t('err.upstream'), 'details' => redact($e->getMessage())], 502);
} catch (Throwable $e) {
    // Do not log exception messages: upstream messages can contain credentials.
    error_log('openfoodfacts-php-demo: ' . get_class($e));
    reply(['ok' => false, 'message' => t('err.failed')], 500);
}
