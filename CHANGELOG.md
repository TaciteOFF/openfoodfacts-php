# Changelog

## [1.1.1](https://github.com/TaciteOFF/openfoodfacts-php/compare/v1.1.0...v1.1.1) (2026-09-16)

### Fixes and improvements

* Add `InvalidBarcodeException` for empty or non-numeric barcodes, with the rejected value available through `getBarcode()` and a specific message for empty input.
* Preserve compatibility with existing `catch (InvalidParameterException)` and `catch (BadRequestException)` blocks.
* Centralize barcode validation for `getProduct()`, `updateProduct()` and `uploadImage()`, rejecting invalid values before any HTTP request and preserving leading zeros.
* Update the French and English documentation with the exception hierarchy and handling examples.

### Maintenance

* Remove the automatic release workflow and disable automated review reporting.

### Validation

* Unit tests on PHP 8.5: 72 tests, 282 assertions, no failures.
* PHPStan level 8 and strict Composer validation pass.
* GitHub Actions passes on PHP 8.1, 8.2, 8.3, 8.4 and 8.5 for the barcode validation changes.

## [1.1.0](https://github.com/TaciteOFF/openfoodfacts-php/compare/v1.0.0...v1.1.0) (2026-09-15)

### Migration

* Rename the Composer package to `taciteoff/openfoodfacts-php`. Replace the old dependency with `taciteoff/openfoodfacts-php:^1.1`; PHP namespaces remain `OpenFoodFacts\`. The fork and upstream package cannot be installed together.
* Limit image files to 10 MiB before base64 encoding; reject empty and non-regular files locally.

### Fixes and improvements

* Preserve the selected flavor and geography when enabling staging mode, with separate HTTP Basic and contributor credentials.
* Bound image reads and add a common `ApiException` base while preserving existing exception handlers.
* Exclude examples, the demo, tests, dependencies and development configuration from distribution archives.
* Verify cache isolation across environments and flavors; remove the test helper cache that mixed production and staging data.
* Add PHP 8.5 to CI, restrict workflow permissions and remove PHPUnit notices from unnecessary mocks.
* Clarify that product reads, writes and image uploads use v3.6 while search and facets keep their existing endpoints.
* Update the French and English documentation, publish the demo source, modernize examples and update the demo to the renamed package.

### Validation

* PHPUnit on PHP 8.5: 71 tests, 215 assertions, no failures or notices; 4 pre-existing incomplete tests and 2 skipped tests.
* PHPStan level 8 and strict Composer validation pass.
* GitHub Actions passes on PHP 8.1, 8.2, 8.3, 8.4 and 8.5; the demo PHP and JavaScript suites pass with the renamed package.

## [1.0.0](https://github.com/TaciteOFF/openfoodfacts-php/compare/v0.4.0...v1.0.0) (2026-08-19)


### ⚠ BREAKING CHANGES

* uploadImage() now returns the v3 response envelope and works for all flavors; non-numeric barcodes are rejected with InvalidParameterException.

### Features

* migrate product read, write and image upload to API v3.6 ([2e69df6](https://github.com/TaciteOFF/openfoodfacts-php/commit/2e69df601bd38f34623d8856868bdea8794e8d4a))


### Bug Fixes

* ext-gd as dev. dependency ([db75b14](https://github.com/TaciteOFF/openfoodfacts-php/commit/db75b14e79bc61674d8162e386cde3e3cbe9072b))
* harden v3 write path (redirects, partial failures, staging auth) ([ece667d](https://github.com/TaciteOFF/openfoodfacts-php/commit/ece667dfd62a72801dbe92c74174557292a54dda))
* **workflow:** replace deprecated set-output command ([9e0361e](https://github.com/TaciteOFF/openfoodfacts-php/commit/9e0361ea09176e5834016a8650828a41a4e82548))

## [0.4.0](https://github.com/openfoodfacts/openfoodfacts-php/compare/v0.3.0...v0.4.0) (2024-11-06)


### Features

* add search api ([#62](https://github.com/openfoodfacts/openfoodfacts-php/issues/62)) ([8bfd9cb](https://github.com/openfoodfacts/openfoodfacts-php/commit/8bfd9cb6c55f1c84a8a8d1f42ead2b5c507f7664))
* docker update ([#52](https://github.com/openfoodfacts/openfoodfacts-php/issues/52)) ([10d9aa9](https://github.com/openfoodfacts/openfoodfacts-php/commit/10d9aa9ab35a10d77dd8482501e0ecc9d944b861))
* rebase ([1cc05fe](https://github.com/openfoodfacts/openfoodfacts-php/commit/1cc05fe4f9f0e96945685adcc11c3ae5ff8edbb9))


### Bug Fixes

* phpstan ([37daf85](https://github.com/openfoodfacts/openfoodfacts-php/commit/37daf85241a8d9cb06603a72c337ef9ef92b52c1))
* update ([76d2892](https://github.com/openfoodfacts/openfoodfacts-php/commit/76d289209d72228be296618952a58156c2cad379))

## [0.3.0](https://www.github.com/openfoodfacts/openfoodfacts-php/compare/v0.2.4...v0.3.0) (2024-05-01)


### Features

* upgrade to php 8.1 ([8407ea5](https://www.github.com/openfoodfacts/openfoodfacts-php/commit/8407ea5155c21b2509fc1ca074784c6f4db40475))


### Bug Fixes

* ci ([56e2cf7](https://www.github.com/openfoodfacts/openfoodfacts-php/commit/56e2cf7b0188a65faa14aa7d495336f1cc930cbe))
