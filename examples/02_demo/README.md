# openfoodfacts-php — demo

A small, self-contained developer playground for the PHP wrapper
[TaciteOFF/openfoodfacts-php](https://github.com/TaciteOFF/openfoodfacts-php). It exercises the
wrapper — and nothing else — against the Open Food Facts API v3.6: read a product, search,
edit fields in staging, upload a photo, and inspect the raw JSON the wrapper returned.

**Live demo: <https://openfoodfacts-php-demo.fly.dev/>** — this folder is its complete source, kept
inside the wrapper repository as `examples/02_demo`.

The interface ships in **French and English**, switchable from the FR/EN control in the header.
The code, the comments and the documentation are in English. A French copy of this document is
kept in [README.fr.md](README.fr.md).

## Quick start

Requirements: PHP 8.1 or newer, Composer 2, the JSON and Fileinfo extensions, and outbound
HTTPS. Verified locally on PHP 8.5.

```sh
composer install
composer start
```

Then open <http://127.0.0.1:8080>. The HTTP entry point is the `public/` directory.

## Features

- **Bilingual interface (FR / EN).** The first visit follows the browser's `Accept-Language`;
  the choice is then stored in the PHP session. The interface language is independent from the
  product language: switching the page to English does not change which translation of the data
  is requested from Open Food Facts. Switching reloads the page so the server-rendered markup
  and the API messages come back in the same language, with no half-translated screen.
- **Full product read** (`fields = null`): labels, characteristics, ingredients, allergens,
  packaging, photos and scores. Every field the wrapper returns is also browsable in an
  exhaustive tree, so nothing is hidden from inspection.
- **Language selector built from the product itself.** Switching language re-reads the product
  through `getProduct(..., lc, ..., tagsLc)` and refreshes texts and available photos. The
  server-localized generic field is used as a fallback when a translation is missing, and the
  selector says so.
- **Every nutrient of `nutrition.aggregated_set`** (schema 1004), with no curated short list:
  zeros, decimals, modifiers, units, reference base, sources and computed values. The raw
  `input_sets` and the legacy `nutriments` object stay browsable separately. No value is dropped
  because it equals zero, and no unit is converted or guessed.
- **Search** by product or brand, 12 results per page, with one click to open a full product.
- **Production is read-only**; staging is enabled through `activeTestMode()`.
- **Credentials live in the PHP session**: cleared on logout, cleared when the environment
  changes, and expired after 30 minutes of inactivity.
- **Row-level editing, staging only**, through `updateProduct()`: a button at the end of each
  editable row (name, quantity, categories, labels, …). The dialog contains only the field you
  picked; its draft survives language switches and reopening that same row, while opening a
  different row starts from that row's current values. The patch preview is limited to the
  chosen field, and the product is re-read automatically after saving. Additives, ingredient
  analysis and the printed date stay read-only and are rejected server-side.
- **Editable nutrition for the `packaging` and `manufacturer` sources**: one action per recorded
  nutrient, with its exact source, unit and modifier, and zeros preserved. Values that only
  exist as computed results offer no edit. Patches use `nutrition.input_sets` and `value_string`;
  estimates and aggregates are never copied back.
- **Photo upload, staging only**: JPEG/PNG up to 5 MB, with preview, image field and language.
  The server checks the size, the real content type and that the bytes really are an image.
- **The wrapper's JSON response**, with copy button, request duration and partial-error handling.
- Responsive layout, keyboard navigation, ARIA tabs, native dialogs, and explicit
  loading/error states.

### Login limitation, stated plainly

`Api::authentification()` stores the credentials but **performs no validation request**. The
wrapper exposes no login method that verifies an account. The demo therefore displays
“credentials stored, not verified”, and only marks them accepted after a successful upload or
update. No direct Open Food Facts endpoint was added to work around this. Logging into a
production account cannot be verified from this read-only interface.

The staging contributor account is distinct from the `off` / `off` HTTP Basic protection of the
test server, which the wrapper handles itself. Production credentials are never carried over to
staging automatically.

### Architecture and wrapper exclusivity

The JavaScript only ever contacts `api.php` on the same origin. Every Open Food Facts call uses
the requested VCS package: `getProduct()`, `search()`, `authentification()`, `activeTestMode()`,
`uploadImage()` and `updateProduct()`. Guzzle — a dependency of the wrapper — is configured as
the transport to set network timeouts, add the identification header, and block any write whose
destination is not the HTTPS staging host, including after a redirect. There is no direct cURL
call to OFF, no second SDK, and no fallback to another API.

Photos are displayed from the URLs the wrapper returns, and only the Open Food Facts image
domains are accepted. External links point to the documentation and to OFF product pages.
Staging photos may be unavailable in the browser; a placeholder state is provided.

`composer.lock` pins the revision of the fork in use. The package is installed from the
TaciteOFF repository, not from the upstream release on Packagist.

Interface strings live in two catalogues with identical keys: `app/i18n.php` for the markup and
the API messages, `public/assets/i18n.js` for what the browser renders at runtime. Adding a
language means adding one entry to `UI_LOCALES` and one block to each catalogue; the test suite
fails if the two locales ever drift apart.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the file-by-file walkthrough, the request protocol
and the security model.

## Deployment

The repository ships a production container: Apache + mod_php, `public/` as the document root,
everything else outside the web root. Nothing in the application changes to run there — sessions,
the 5 MB upload and the per-session rate limiter behave exactly as they do locally.

```sh
docker build -t off-lab .
docker run --rm -p 8080:8080 -e OFF_USER_AGENT="My demo - Web - 1.0 - https://…" off-lab
```

The server listens on `$PORT` (default 8080), which is what Fly.io, Render and Railway inject.
Ready-made configs are included: [`fly.toml`](fly.toml), [`render.yaml`](render.yaml),
[`railway.json`](railway.json). Set `OFF_USER_AGENT` for your deployment; it is the only variable
the app needs.

| File | Role |
| --- | --- |
| `Dockerfile` | Two stages: Composer install on the same PHP version as the runtime, then Apache + mod_php. |
| `docker/vhost.conf` | Document root, deny-everything-outside-`public/`, body limit, logs to stdout. |
| `docker/php.ini` | The recommended settings below, applied in the image. |
| `docker/entrypoint.sh` | Binds Apache to `$PORT`, then hands over to `apache2-foreground`. |

**Keep a single instance.** PHP sessions live on the instance filesystem, so a second replica
would hand users a different session: random CSRF failures, lost credentials, and a rate limiter
that resets. `fly.toml`, `render.yaml` and `railway.json` all pin one instance. Scaling out means
moving sessions to Redis first (`session_set_save_handler`) — not a change this demo needs.

**TLS.** These platforms terminate TLS at their edge and forward plain HTTP, so PHP would not see
HTTPS and would drop the `Secure` flag from the session cookie. The vhost turns the proxy's
`X-Forwarded-Proto` back into `HTTPS=on`. If you expose the container directly to the internet
instead, remove that line and terminate TLS in front of it.

### Vercel and other serverless platforms

Not supported, for two concrete reasons:

- **Sessions.** Vercel functions have a read-only filesystem with only ephemeral `/tmp`, are
  archived when idle, and auto-scale across isolated microVMs. Since every POST is CSRF-checked
  against the session, users would randomly get “session expired”, lose stored credentials, and
  bypass the rate limiter that protects the Open Food Facts servers. It would require an external
  session store; putting the state in a cookie instead would send the OFF password to the
  browser, which this project deliberately never does.
- **Upload size.** The 4.5 MB request body cap rejects the documented 5 MB photo upload with a
  413 before PHP runs.

PHP itself is available there through the `vercel-php` community runtime, so a read-only variant
of this demo would deploy — but the account, upload and edit features would not work as designed.

## Hosting on PHP

If you are not using the container above, configure Apache/Nginx with PHP-FPM, **HTTPS**, and
`public/` as the web root. Do not expose the project root. The `php -S` server is for local
development only.

Recommended configuration:

```ini
upload_max_filesize = 6M
post_max_size = 8M
memory_limit = 128M
max_execution_time = 40
session.cookie_httponly = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
```

Also allow a large enough request body in the reverse proxy (e.g. `client_max_body_size 8m`).
The server must recognise HTTPS so the Secure cookie flag is emitted, which matters behind a
proxy. Provide private PHP session storage with cleanup of expired sessions; passwords are never
written to project files, to the browser, or to application logs.

Set `OFF_USER_AGENT` to the name and a contact address for your deployment, for example
`My PHP demo - Web - 1.0 - https://my-domain.example/contact`. The default is
`openfoodfacts-php-demo/1.0 (+https://github.com/TaciteOFF/openfoodfacts-php)`. This identity
feeds both the wrapper's standard `User-Agent` and the `X-User-Agent` added to outbound requests
to OFF; an `X-User-Agent` already present is preserved (case-insensitive comparison). For a
public, high-traffic instance, add a global rate limit at the reverse-proxy level as well: the
built-in limits are per session only (read 1 s, search 6 s, upload and update 5 s).

## Verification

```sh
composer test
# Data-rendering tests (Node.js, no npm dependency)
node --test tests/*.test.cjs
```

The PHP suite starts its own PHP server on a temporary local port. It covers sessions, CSRF, the
server-side staging restriction, invalid input, logout, password masking, and the interface
language (switching, `Accept-Language` detection, rendered page, localized error messages, and
strict key parity between the two catalogues). It also drives the
real wrapper with a mocked transport to verify the v3.6 URLs, staging authentication, the base64
photo payload, language handling, pagination, multilingual patches, nutritional zeros, partial
errors, and write redirects away from staging. No real account and no real write to Open Food
Facts are used by the tests.

Real reads were verified manually in production and in staging with barcode `3057640385148`; a
real search for “chocolat” returned 12 results on the first page. The mobile layout was checked
at 320 px and 390 px with no horizontal overflow. The multilingual product `3017620422003` was
also checked: 67 aggregated nutrients, 348 fields, labels and fibre at 0 g; FR → EN → NL
switches really re-read the ingredients. Row editing was verified at 390 px: quantity alone,
categories alone, FR/EN ingredients, and salt at zero; excluded fields show no button. Test
drafts were reset without being sent. Real writes remain to be verified with a staging account;
the tests use a mocked transport and modify no Open Food Facts product.

## Licences

Application code: MIT. The wrapper keeps its own MIT licence. Open Food Facts data: ODbL;
photos: CC BY-SA, attributed through the product page. Independent, unofficial tool.
