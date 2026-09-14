<?php
/**
 * Shared bootstrap for every HTTP entry point of the demo (`index.php`, `api.php`)
 * and for the PHP integration test suite.
 *
 * Responsibilities, in order:
 *  1. Load the Composer autoloader (which brings in the TaciteOFF/openfoodfacts-php
 *     wrapper and its Guzzle transport).
 *  2. Harden the PHP session (strict mode, HttpOnly/Secure/SameSite cookie, idle timeout).
 *  3. Send the security response headers, including a Content-Security-Policy that only
 *     allows same-origin scripts/styles and Open Food Facts image hosts.
 *  4. Expose the small helper layer used by `api.php`: `state()`, `reply()`, `input()`,
 *     `barcode()`, `api()` and `redact()`.
 *
 * Design rule of the whole project: every Open Food Facts call goes through the wrapper
 * (`OpenFoodFacts\Api`). There is no direct cURL call to OFF anywhere in this codebase.
 *
 * @package off-lab/playground
 * @license MIT
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/i18n.php';


// Never leak stack traces or credentials to the browser: errors are turned into JSON
// messages by api.php and only the exception class name is written to the PHP error log.
ini_set('display_errors', '0');
// `use_strict_mode` makes PHP refuse session ids it did not generate itself,
// which blocks session fixation attempts through a forged cookie.
ini_set('session.use_strict_mode', '1');
// Dedicated cookie name so the demo never collides with another app on the same host.
session_name('off_lab');
// The Secure flag is derived from the current request: behind a TLS-terminating proxy the
// server must be configured to expose HTTPS so that the cookie keeps the flag in production.
session_set_cookie_params(['httponly' => true, 'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict', 'path' => '/']);
session_start();

// Responses embed session state (environment, username, CSRF token) and must never be cached
// by a shared proxy or restored from the browser back/forward cache.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// CSP notes:
//  - `script-src 'self'` means no inline script: all behaviour lives in assets/*.js.
//  - `img-src` additionally allows blob: (local upload preview) and the two OFF image hosts
//    (production and staging). Any other image origin is blocked by the browser.
//  - `connect-src 'self'` guarantees the front end can only talk to api.php on this origin.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: https://images.openfoodfacts.org https://images.openfoodfacts.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
// Idle timeout: 30 minutes without any request wipes the session (including the stored
// Open Food Facts credentials) and rotates the session id.
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
// CSRF token, generated once per session and echoed into the page as a <meta> tag.
// `api.php` compares it with the X-CSRF-Token header of every POST using hash_equals().
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
// Default environment is read-only production; writes are only possible after an explicit
// switch to staging (see the `environment` action in api.php).
$_SESSION['env'] ??= 'prod';
// Interface language, independent from the product language. Seeded once from Accept-Language,
// then changed explicitly through the `interface` action of api.php.
$_SESSION['ui'] ??= ui_locale_from_browser($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');


/**
 * Public, non-sensitive session state handed back to the browser on every response.
 *
 * The password is never part of this payload; only the username is echoed so the UI can
 * display which account is stored. `interfaceLanguage` lets the front end keep its own
 * catalogue in sync with the locale the server renders and answers in.
 *
 * @return array{environment:string,username:?string,credentialsVerified:bool,csrf:string,interfaceLanguage:string}
 */
function state(): array {
    return ['environment' => $_SESSION['env'], 'username' => $_SESSION['credentials']['username'] ?? null,
        'credentialsVerified' => $_SESSION['verified'] ?? false, 'csrf' => $_SESSION['csrf'],
        'interfaceLanguage' => ui_locale()];
}

/**
 * Emit a JSON response and terminate the request.
 *
 * JSON_INVALID_UTF8_SUBSTITUTE keeps the endpoint alive even when Open Food Facts returns a
 * field with broken encoding, which does happen on user-contributed data.
 *
 * @param array<string,mixed> $data Response body.
 * @param int $status HTTP status code.
 */
function reply(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read a string field from the decoded request body, enforcing type and length.
 *
 * Everything is trimmed except the password, where leading/trailing whitespace may be
 * significant and must reach Open Food Facts unchanged.
 *
 * @param array<string,mixed> $data Decoded JSON body or $_POST for multipart requests.
 * @param string $key Field name.
 * @param int $max Maximum accepted length in bytes.
 * @throws InvalidArgumentException When the field is missing, not a string, or too long.
 */
function input(array $data, string $key, int $max = 200): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max) {
        throw new InvalidArgumentException(t('err.invalid_value', ['field' => $key]));
    }
    return $key === 'password' ? $value : trim($value);
}

/**
 * Read and validate a barcode: 4 to 24 digits, nothing else.
 *
 * The strict pattern also prevents path traversal or URL injection into the wrapper call.
 *
 * @throws InvalidArgumentException When the value is not a plausible barcode.
 */
function barcode(array $data): string {
    $code = input($data, 'barcode', 24);
    if (!preg_match('/^[0-9]{4,24}$/D', $code)) {
        throw new InvalidArgumentException(t('err.barcode'));
    }
    return $code;
}

/**
 * Build a configured Open Food Facts API client for the current session.
 *
 * The client is the wrapper's own `OpenFoodFacts\Api`; only the HTTP transport is customised:
 *  - network timeouts (25 s total, 8 s connect) so a slow OFF response cannot hang PHP-FPM;
 *  - an `X-User-Agent` header identifying this deployment (see the OFF_USER_AGENT env var);
 *  - a Guzzle middleware that rejects any non GET/HEAD request whose destination is not
 *    `https://world.openfoodfacts.net`, i.e. a hard, transport-level guarantee that no write
 *    can reach production, even after an unexpected redirect.
 *
 * Session state is applied last: staging mode via `activeTestMode()`, then credentials via
 * `authentification()` when the user stored an account.
 */
function api(): OpenFoodFacts\Api {
    $userAgent = getenv('OFF_USER_AGENT') ?: 'openfoodfacts-php-demo/1.0 (+https://github.com/TaciteOFF/openfoodfacts-php)';
    $handler = GuzzleHttp\HandlerStack::create();
    $handler->push(GuzzleHttp\Middleware::mapRequest(static function (Psr\Http\Message\RequestInterface $request) {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) && ($request->getUri()->getScheme() !== 'https' || $request->getUri()->getHost() !== 'world.openfoodfacts.net')) {
            throw new InvalidArgumentException(t('err.write_blocked'));
        }
        return $request;
    }), 'staging_writes_only');
    // Guzzle adds default headers only when absent, case-insensitively.
    // The wrapper continues to supply its standard User-Agent header.
    $api = new OpenFoodFacts\Api($userAgent, 'food', 'fr', null,
        new GuzzleHttp\Client([
            'handler' => $handler,
            'timeout' => 25,
            'connect_timeout' => 8,
            'headers' => ['X-User-Agent' => $userAgent],
        ]));
    if ($_SESSION['env'] === 'staging') $api->activeTestMode();
    if (isset($_SESSION['credentials'])) {
        $api->authentification($_SESSION['credentials']['username'], $_SESSION['credentials']['password']);
    }
    return $api;
}

/**
 * Recursively mask secrets in any payload before it is returned to the browser or logged.
 *
 * Two complementary passes:
 *  - keys matching password/authorization/cookie/token/user_id/image_data_base64 are replaced
 *    wholesale (the base64 image is dropped for size as much as for privacy);
 *  - any string containing the session password verbatim has it replaced, which covers error
 *    messages echoed back by Open Food Facts.
 *
 * @param mixed $value Arbitrary decoded payload.
 * @return mixed Same shape, with secrets replaced by the localized redaction marker.
 */
function redact(mixed $value): mixed {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = preg_match('/password|authorization|cookie|token|user_id|image_data_base64/i', (string)$key) ? t('label.redacted') : redact($item);
        }
    } elseif (is_string($value)) {
        $secret = $_SESSION['credentials']['password'] ?? '';
        if ($secret !== '') $value = str_replace($secret, t('label.redacted'), $value);
    }
    return $value;
}
