# Architecture

Developer-facing documentation for the `openfoodfacts-php` demo. It explains how the pieces fit
together, what the HTTP protocol between the page and the server looks like, and which rules the
code deliberately enforces. For installation and features, see [README.md](README.md).

## Overview

```
browser                         PHP (public/)                       Open Food Facts
────────                        ─────────────                       ───────────────
index.php ── page
assets/app.css      styles
assets/i18n.js           ─┐
assets/product-data.js   ─┤ pure modules (no DOM, no I/O)
assets/product-editor.js ─┤
assets/app.js            ─┘ UI controller ── fetch ──▶ api.php ──▶ OpenFoodFacts\Api ──▶ v3.6
                                                 │        │              (wrapper + Guzzle)
                                                 │        ├─ bootstrap.php  session, headers,
                                                 │        │                 client factory
                                                 │        ├─ app/i18n.php   interface catalogue
                                                 │        └─ app/product-patch.php  write
                                                 │                                  allow-list
                                                 └── JSON {ok, data, state, meta}
```

Two invariants shape everything else:

1. **The browser never talks to Open Food Facts.** It only ever calls `api.php` on the same
   origin, and the Content-Security-Policy blocks any other connection. Every OFF call goes
   through the wrapper, server-side.
2. **The server is the authority.** Guards in the front end exist for usability; each one is
   re-checked in `api.php`, and the integration suite asserts that a browser lying about the
   environment or the fields it edits is rejected.

## Files

| Path | Role |
| --- | --- |
| `public/index.php` | The single HTML page. PHP is used only for the session and the CSRF meta tag. |
| `public/bootstrap.php` | Session hardening, security headers, and the helper layer (`state()`, `reply()`, `input()`, `barcode()`, `api()`, `redact()`). |
| `public/api.php` | The only endpoint. One `action` per POST; maps wrapper exceptions to stable HTTP statuses. |
| `app/product-patch.php` | `validateProductPatch()`: the server-side allow-list for writes. |
| `app/i18n.php` | Interface locale resolution and the catalogue for markup + API messages. |
| `public/assets/i18n.js` | Client-side catalogue (`window.OFFI18n`, CommonJS in tests). |
| `public/assets/app.js` | UI controller: requests, tabs, product/search/upload, edit dialog. |
| `public/assets/product-data.js` | Pure rendering helpers (`window.OFFProduct`, CommonJS in tests). |
| `public/assets/product-editor.js` | Pure draft/patch model (`window.OFFEditor`, CommonJS in tests). |
| `public/assets/app.css` | Whole stylesheet, no build step. |
| `Dockerfile`, `docker/` | Production image: Apache + mod_php, `public/` as document root, `$PORT` binding. |
| `fly.toml`, `render.yaml`, `railway.json` | One-instance deployment configs for the three container hosts. |
| `tests/integration.php` | PHP suite: real local HTTP, patch validation, wrapper with a mocked transport. |
| `tests/*.test.cjs` | Node suites for the two pure JavaScript modules. |

## Request protocol

`GET api.php` returns the session state so the page can boot:

```json
{"ok": true, "state": {"environment": "prod", "username": null, "credentialsVerified": false,
                       "csrf": "…", "interfaceLanguage": "fr"}}
```

`POST api.php` carries the CSRF token in the `X-CSRF-Token` header and a JSON body — except the
photo upload, which is `multipart/form-data`. The body always names an `action`:

| Action | Wrapper call | Notes |
| --- | --- | --- |
| `environment` | – | `prod` ⇄ `staging`; drops credentials and rotates the session id. |
| `interface` | – | Interface language (`fr` / `en`); touches no Open Food Facts call. |
| `login` | `authentification()` | Stores credentials in the session; verifies nothing (see below). |
| `logout` | – | Drops credentials, rotates the session id. |
| `product` | `getProduct()` | `fields = null`, `lc` and `tagsLc` set to the requested language. |
| `search` | `search()` | 12 results per page, page 1–100. |
| `update` | `updateProduct()` | Staging + credentials required; patch validated first. |
| `upload` | `uploadImage()` | Staging + credentials required; JPEG/PNG ≤ 5 MB. |

Successful responses look like:

```json
{"ok": true, "data": {...}, "state": {...},
 "meta": {"method": "getProduct", "environment": "prod", "duration": 412}}
```

Errors are `{"ok": false, "message": "…"}` with a meaningful status: 400 invalid input,
401 credentials required, 403 CSRF or production write, 404 product not found, 422 write refused
or partially applied (with the upstream response in `data`), 429 rate limited, 502 upstream
error, 500 anything else.

## Security model

- **Session**: `use_strict_mode`, a dedicated cookie name, `HttpOnly` / `SameSite=Strict`, and
  `Secure` whenever the request is HTTPS. 30 minutes of inactivity wipes the session. The
  session id is rotated on login, logout and environment change.
- **CSRF**: one token per session, rendered into a `<meta>` tag, compared with `hash_equals()`
  on every POST.
- **CSP**: `default-src 'self'`, no inline script, `img-src` restricted to `blob:` and the two
  Open Food Facts image hosts, `connect-src 'self'`, `frame-ancestors 'none'`.
- **Writes cannot reach production.** Three independent layers: the UI renders no edit control
  outside staging; `api.php` returns 403 for `update`/`upload` when the session is not in
  staging; and a Guzzle middleware throws on any non-GET/HEAD request whose destination is not
  `https://fr.openfoodfacts.net` or `https://world.openfoodfacts.net`, which also covers redirects. The last layer is what the
  integration suite asserts with a 307 pointing at production.
- **Write allow-list**: `validateProductPatch()` runs before the wrapper is called. Unknown
  fields, computed fields (`nova-group`, scores), read-only tags and aggregated nutrition are
  rejected with a 400 and never leave the process.
- **Secret handling**: passwords live in the PHP session only. `redact()` masks password,
  authorization, cookie, token, user id and base64 image keys — and any string containing the
  session password — in every payload returned to the browser. Exception messages from upstream
  are never logged; only the exception class name is.
- **Uploads**: checked three times over — upload status, declared size, then the real MIME type
  via `finfo` plus `getimagesize()`.
- **Rate limiting**: per session and per action (read 1 s, search 6 s, writes 5 s). A public
  instance should add a global limit in the reverse proxy.

## Data-rendering rules

These are the rules the Node suites pin, and the reason the demo is useful for inspecting the
wrapper:

- Only `null`, `undefined` and `""` count as missing. `0`, `false` and `"0.00"` are values and
  are displayed.
- Numbers are never reformatted, rounded or converted. A nutrient's `value`, `value_string` and
  `value_computed` can coexist, and all of them are shown and labelled.
- Unknown and future fields always remain visible in the exhaustive field tree, even when no
  dedicated section knows about them.
- Every value is HTML-escaped before interpolation, and image URLs are accepted only over HTTPS
  on the official Open Food Facts image hosts.
- Language fallback follows the wrapper: requested language → server-localized generic field →
  the product's own main language. The selector marks a language it had to fall back to.

## Interface languages

The page speaks French or English. This is *not* the product language: the selector in the
Product tab decides which translation of the data is requested from Open Food Facts, while the
FR/EN control in the header decides which language the page itself speaks.

Two catalogues, identical keys:

| Catalogue | Owns |
| --- | --- |
| `app/i18n.php` | The markup rendered by `index.php`, and every message returned by `api.php`, `bootstrap.php` and `product-patch.php`. |
| `public/assets/i18n.js` | Everything the browser renders at runtime: statuses, product sections, nutrient names, editor help. |

A few keys exist on both sides on purpose — labels PHP prints for the first paint and JavaScript
rewrites as the state changes (the environment note, the account button, the response panel
placeholders).

Resolution order: the locale stored in the session, then `Accept-Language`, then French. The
chosen locale is stamped on `<html lang>`, which is where `i18n.js` reads it from, so the page is
never momentarily in the wrong language. Switching posts the `interface` action and reloads: the
server re-renders the markup and will answer in the new locale, which rules out a half-translated
screen at the cost of re-reading the displayed product.

Data is never translated. Nutrient ids the catalogue does not know keep their raw id, and values,
units and modifiers are rendered identically in both locales — the Node suite asserts exactly
that.

To add a language: add its code to `UI_LOCALES` in `app/i18n.php`, add one block to each
catalogue, and run the suites. `composer test` fails if the locales do not define the same keys,
or if any translation is empty.

## The editing model

`product-editor.js` keeps a *draft*: a deep clone of the product as last read, a map of changed
fields, and a map of changed nutrients keyed by the triple (source, preparation, base) rather
than by index, so a draft survives OFF reordering `input_sets`.

Everything is a diff against the original: typing a value back to what it was removes the entry,
so the generated patch only ever holds real changes, and an empty patch keeps the save button
disabled. A draft is scoped to `environment/barcode/field/source/nutrient` — reopening the same
row resumes it, opening any other row starts fresh.

Only a subset of what is displayed is editable, mirroring the server allow-list: localized and
global texts, taxonomy lists, and nutrients belonging to a `packaging` or `manufacturer` input
set. Nutrition is written as `nutrition.input_sets` with `value_string`, never as an aggregate,
because Open Food Facts recomputes aggregates, estimates and scores itself.

After a save, the product is re-read so the screen shows what OFF stored rather than the local
draft. A write is never retried automatically: it may have applied partially, and the 422
response carries the upstream detail for inspection.

## Login limitation

`Api::authentification()` only stores the credentials; the wrapper has no verifying login call.
The session therefore keeps `credentialsVerified = false` and the UI says “credentials stored,
not verified” until an upload or an update actually succeeds, at which point the flag flips.
No direct OFF endpoint was added to work around this.

## Deployment constraints

The app assumes what a normal PHP host gives it, and two of those assumptions decide where it can
run:

- **A persistent session store.** Credentials, the CSRF token, the environment, the interface
  locale and the rate-limiter timestamps all live in `$_SESSION`. With file-based sessions this
  means one instance, or a shared store. Every shipped deployment config pins a single instance
  for that reason; scaling out requires `session_set_save_handler` against Redis first.
- **Request bodies above 4.5 MB**, for the 5 MB photo upload.

Both rule out serverless platforms such as Vercel, where the filesystem is read-only with an
ephemeral `/tmp`, instances scale out independently, and the request body is capped at 4.5 MB.
The container image is the supported path; see the Deployment section of the README.

One deployment detail worth knowing: behind a TLS-terminating proxy, PHP sees plain HTTP and would
drop the `Secure` flag from the session cookie. `docker/vhost.conf` maps the proxy's
`X-Forwarded-Proto` to `HTTPS=on` so `bootstrap.php` stays correct without any code change.

## Tests

```sh
composer test                # PHP: HTTP endpoint, patch allow-list, wrapper with mocked transport
node --test tests/*.test.cjs # JavaScript: the two pure modules
```

The PHP suite also covers the interface language end to end: the `interface` action, the
`Accept-Language` detection, the rendered page in both locales, localized error messages, and
strict key parity between the two catalogues. The Node suite asserts that switching locale
changes the labels and leaves the data untouched.

`tests/integration.php` boots a throwaway `php -S` on a random port, drives `api.php` over real
HTTP with a cookie jar, then instantiates the real fork through the application's own `api()`
factory and swaps only the Guzzle handler for a `MockHandler`. No real account is used and no
Open Food Facts product is modified.
