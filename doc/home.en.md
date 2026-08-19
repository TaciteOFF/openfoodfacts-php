# Documentation — openfoodfacts-php (v3.6 fork)

🇫🇷 [Version française](home.md) · 🇬🇧 English

PHP wrapper for the [Open Food Facts](https://world.openfoodfacts.org/), [Open Beauty Facts](https://world.openbeautyfacts.org/), [Open Pet Food Facts](https://world.openpetfoodfacts.org/) and [Open Products Facts](https://world.openproductsfacts.org/) APIs.

This fork uses **API v3.6** (product schema 1004) for product reads, writes and image uploads. The differences from upstream are listed in the [README](../README.en.md).

## Table of contents

- [Installation](#installation)
- [The `Api` client](#the-api-client)
  - [Constructor](#constructor)
  - [Reading a product — `getProduct()`](#reading-a-product--getproduct)
  - [Writing a product — `updateProduct()`](#writing-a-product--updateproduct)
  - [Uploading an image — `uploadImage()`](#uploading-an-image--uploadimage)
  - [Facets — `getBrands()`, `getCategories()`, …](#facets--getbrands-getcategories-)
  - [Legacy search — `search()` and `getByFacets()`](#legacy-search--search-and-getbyfacets)
  - [Data exports — `downloadData()`](#data-exports--downloaddata)
  - [Test server — `activeTestMode()`](#test-server--activetestmode)
- [The `SearchApi` client (Search-a-licious)](#the-searchapi-client-search-a-licious)
- [Models](#models)
- [Exceptions](#exceptions)
- [Cache, logging and HTTP client](#cache-logging-and-http-client)
- [Running the tests](#running-the-tests)

## Installation

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/TaciteOFF/openfoodfacts-php" }
  ],
  "require": {
    "openfoodfacts/openfoodfacts-php": "^1.0"
  }
}
```

Requirements: PHP ≥ 8.1 (tested up to 8.4), `json` extension.

## The `Api` client

### Constructor

```php
$api = new OpenFoodFacts\Api(
    userAgent:      'MyApp - Web - 1.0 - https://example.org', // mandatory
    currentAPI:     'food',   // 'food' | 'beauty' | 'pet' | 'product'
    geography:      'fr',     // 'world', country code ('fr', 'de'…) or 'fr-en'
    logger:         null,     // PSR-3 LoggerInterface (default: NullLogger)
    clientInterface: null,    // Guzzle ClientInterface (default: new Client())
    cacheInterface: null      // PSR-16 CacheInterface (default: no cache)
);
```

- **`userAgent`** (mandatory): identifies your application to the OFF servers. The SDK sends the header `User-Agent: SDK PHP - {userAgent}`. Include a name, a version and a contact.
- **`currentAPI`**: the product database to query. It also determines the type of `Document` returned (`FoodDocument`, `BeautyDocument`, `PetDocument`, `ProductDocument`).
- **`geography`**: the subdomain called (`https://{geography}.openfoodfacts.org`) — filters country and interface language.

The public constant `Api::API_VERSION` (currently `'3.6'`) is the API version — hence the product schema — requested by every product route.

### Reading a product — `getProduct()`

```php
public function getProduct(
    string  $barcode,            // digits only
    ?array  $fields = null,      // list of fields to return (null = all)
    ?string $lc = null,          // language used to localize fields
    ?string $cc = null,          // country code
    ?string $tagsLc = null,      // language for taxonomy tags
    ?string $productType = null  // 'food'|'beauty'|'petfood'|'product'|'all'
): Document
```

```php
$product = $api->getProduct('3057640385148', ['product_name', 'nutriscore_grade'], 'fr');
echo $product->product_name;          // magic field access
$all = $product->getData();           // full array (recursively sorted)
isset($product->brands);              // check a field's presence
```

Key points:

- **Filter with `$fields`**: a full product record often exceeds 100 KB. Special values: `all`, `none`, `raw`, plus generated fields to request explicitly such as `knowledge_panels` or `attribute_groups`.
- **Missing field → `null`** (no PHP warning). The schema evolves: since v3.6, the `*_hierarchy` / `*_lc` fields of taxonomized fields are replaced by `tags_sources`; since v3.1, `ecoscore_*` is named `environmental_score_*`.
- **`$productType: 'all'`**: if the product belongs to another flavor (e.g. a cosmetic scanned through the food API), the server redirects to the right server and the redirect is followed automatically. Without this parameter, the product is reported as not found.
- Unknown product → `ProductNotFoundException`.
- Reads are cached for 1 h when a PSR-16 cache is provided.

### Writing a product — `updateProduct()`

Structured v3 WRITE API (`PATCH /api/v3.6/product/{code}`). Creates the product if it does not exist. Requires a contributor account:

```php
$api->authentification('my_user', 'my_password');

$result = $api->updateProduct('3057640385148', [
    'product_name_fr' => 'Eau de Volvic',
    'categories_tags' => ['en:waters'],
    'packagings_add'  => [ /* structured packaging components */ ],
]);
// $result = v3 envelope: status, result, errors, warnings, product
```

Fields currently supported by the v3 write API: language-specific fields (`product_name_xx`, `ingredients_text_xx`…), tags fields (`categories_tags`, `labels_tags`…), packagings (`packagings`, `packagings_add`, `packagings_complete`) and selection of uploaded images.

Envelope status handling:

| `status` | Behavior |
|---|---|
| `success` | returns the envelope |
| `success_with_warnings` | returns the envelope, warnings logged through the logger |
| `success_with_errors` | throws `ProductUpdateException` (the write was **partially** applied — the envelope stays available through `getResponse()`) |
| `failure` | throws `ProductUpdateException` |

The legacy method `addNewProduct(array $postData)` (cgi `product_jqm2.pl`) still exists but is **deprecated**.

### Uploading an image — `uploadImage()`

`POST /api/v3.6/product/{code}/images` — the image is sent base64 encoded, and can be **selected** in the same call for a field and a language:

```php
$api->authentification('my_user', 'my_password');

// upload + select as the French "front" image
$result = $api->uploadImage('3057640385148', 'front', '/path/photo.jpg', 'fr');

// raw upload, no selection
$result = $api->uploadImage('3057640385148', '', '/path/photo.jpg');
```

Valid selection fields: `front`, `ingredients`, `nutrition`, `packaging`. Formats: JPEG, PNG, GIF, HEIC. Works for all flavors.

### Facets — `getBrands()`, `getCategories()`, …

Magic accessors list the values of a facet with their product counts, as a `Collection`:

```php
$brands = $api->getBrands();
echo $brands->searchCount();
foreach ($brands as $doc) { echo $doc->name; }
```

Available facets: `additives`, `allergens`, `brands`, `categories`, `countries`, `contributors`, `code`, `entry_dates`, `ingredients`, `label`, `languages`, `nutrition_grade`, `packaging`, `packaging_codes`, `purchase_places`, `photographer`, `informer`, `states`, `stores`, `traces` — i.e. `getAdditives()`, `getAllergens()`, etc. An unknown facet throws `BadRequestException`.

### Legacy search — `search()` and `getByFacets()`

```php
$collection = $api->search('volvic', page: 1, pageSize: 20);
$collection = $api->getByFacets(['brand' => 'volvic', 'country' => 'france'], page: 1);
```

These methods rely on the old engine (`cgi/search.pl` and the website facet URLs). For full-text search, **prefer [`SearchApi`](#the-searchapi-client-search-a-licious)**.

### Data exports — `downloadData()`

Downloads a full database dump to a local file: `$api->downloadData('/tmp/dump.tar.gz', 'mongodb')` (types: `mongodb`, `csv`, `rdf`). Beware: the MongoDB dump weighs tens of GB.

### Test server — `activeTestMode()`

```php
$api->activeTestMode();                        // switches to https://world.openfoodfacts.net
$api->authentification('staging_user', 'pw');  // staging contributor account, for writes
```

`activeTestMode()` configures the htaccess gate (`off`/`off`, sent as HTTP Basic) of the `.net` staging server. This is **distinct** from the contributor account: for writes, provide an account through `authentification()`. Staging data is reset regularly — use it to try `updateProduct()`/`uploadImage()` without polluting production.

## The `SearchApi` client (Search-a-licious)

Client for the modern search engine (`https://search.openfoodfacts.org`), independent from the `Api` client:

```php
$search = new OpenFoodFacts\SearchApi('MyApp - Web - 1.0 - https://example.org');

// full-text search + Lucene syntax
$result = $search->search('categories_tags:"en:beverages" strawberry', langs: ['fr', 'en'], pageSize: 20);
echo $result->count;                       // total count
foreach ($result->listDocuments as $doc) { /* SearchDocument */ }

// document by identifier (barcode)
$doc = $search->getDocument('3057640385148');

// autocomplete on taxonomies
$auto = $search->autocomplete('choco', ['category'], lang: 'fr', size: 10);
foreach ($auto->options as $option) { echo $option->text; }
```

- `search(?string $query, ?array $langs, ?int $pageSize, ?int $page, ?array $fields, ?string $sortBy, ?string $indexId): SearchResult` — `$query` supports the [Lucene syntax](https://lucene.apache.org/core/3_6_0/queryparsersyntax.html); without `$query`, a `$sortBy` is required.
- `getDocument(string $identifier, ?string $indexId): SearchDocument` — not found → `ProductNotFoundException`.
- `autocomplete(string $query, array $taxonomyNames, ?string $lang, ?int $size, ?int $fuzziness, ?string $indexId): AutocompleteResult`.

## Models

- **`Document`** (and its specializations `FoodDocument`, `BeautyDocument`, `PetDocument`, `ProductDocument`, `SearchDocument`): read-only container for product data. Magic access (`$doc->product_name`), presence check (`isset($doc->…)`), sorted export (`$doc->getData()`). Missing field → `null`.
- **`Collection`**: paginated list of `Document`, iterable (`foreach`). Methods: `pageCount()` (items on the page), `searchCount()` (total for the search), `getPage()`, `getPageSize()`, `getSkip()`.
- **`Model\SearchResult`**: result of `SearchApi::search()` — public properties `count`, `isCountExact`, `page`, `pageSize`, `pageCount`, `listDocuments`, `aggregations`, `warning`, `took`, `timedOut`.
- **`Model\AutocompleteResult`** / **`Model\AutocompleteOption`**: autocomplete options (`id`, `text`, `taxonomy_name`).

## Exceptions

All under `OpenFoodFacts\Exception`:

```text
\Exception
├── BadRequestException            generic request / API response error
│   ├── InvalidParameterException  invalid parameter (e.g. non-numeric barcode)
│   │   └── MissingCredentialsException  write without authentification()
│   ├── ProductUpdateException     write rejected or partially applied
│   │                              → getResponse() = full v3 envelope
│   └── UnknownException           unexpected response (non-JSON, unknown status)
├── ProductNotFoundException       product not found (getProduct, SearchApi::getDocument)
├── NotFoundException              404 from the search engine (SearchApi)
└── ValidationException            422 from the search engine (SearchApi)
```

A `catch (BadRequestException $e)` therefore catches every `Api` client error except `ProductNotFoundException`, to handle separately:

```php
try {
    $product = $api->getProduct($barcode);
} catch (ProductNotFoundException) {
    // unknown product
} catch (BadRequestException $e) {
    // network error, invalid response, bad parameter…
}
```

## Cache, logging and HTTP client

All three dependencies are injectable through the constructor (see [`examples/01-basic_api_usage/cached_example.php`](../examples/01-basic_api_usage/cached_example.php)):

- **PSR-16 cache**: reads (`getProduct`, facets) are cached for 1 h. Writes are never cached.
- **PSR-3 logger**: every request is traced at `info` level; network failures and `success_with_warnings` at `warning`.
- **Guzzle client**: inject your own to configure timeouts, proxy or middlewares. The SDK enforces its `User-Agent`, disables Guzzle HTTP errors on v3 routes (statuses are handled by the SDK) and forces **strict** redirects (a redirected `PATCH`/`POST` keeps its method and body).

## Running the tests

```bash
composer install
vendor/bin/phpunit --testsuite "Unit test"      # unit tests (MockHandler, no network)
vendor/bin/phpunit --testsuite "Integration test"  # hits the live API
vendor/bin/phpstan                               # static analysis (level 8)
```

GitHub Actions CI replays everything on PHP 8.1 → 8.4.
