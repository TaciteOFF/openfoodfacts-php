# openfoodfacts-php — fork adapté à l'API v3.6

Fork de [openfoodfacts/openfoodfacts-php](https://github.com/openfoodfacts/openfoodfacts-php), le wrapper PHP pour [Open Food Facts](https://openfoodfacts.org/), la base de données ouverte sur les produits alimentaires.

Ce fork migre le wrapper vers l'**API Open Food Facts v3.6** (schéma produit 1004) et corrige plusieurs bugs de robustesse de la version upstream (v0.4.0), restée sur l'API v0 legacy.

## Installation

Le fork n'est pas publié sur Packagist : installez-le via un dépôt VCS dans votre `composer.json` :

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/TaciteOFF/openfoodfacts-php" }
  ],
  "require": {
    "openfoodfacts/openfoodfacts-php": "dev-develop"
  }
}
```

## Usage rapide

```php
// Le user agent (1er argument) est obligatoire — décrivez votre application
$api = new OpenFoodFacts\Api('MonApp - Web - 1.0 - https://example.org', 'food', 'fr');

// Lecture via l'API v3.6 : filtrez les champs (recommandé) et localisez la réponse
$product = $api->getProduct('3057640385148', ['product_name', 'nutriscore_grade'], 'fr');
echo $product->product_name;

// Écriture structurée (PATCH v3) et upload d'image (POST v3) — credentials requis
$api->authentification('utilisateur', 'mot-de-passe');
$api->updateProduct('3057640385148', ['product_name_fr' => 'Eau de Volvic', 'categories_tags' => ['en:waters']]);
$api->uploadImage('3057640385148', 'front', '/chemin/image.jpg', 'fr');
```

## Différences avec l'upstream

### 1. Migration vers l'API v3.6

Réf. : [changelog API et schéma produit](https://openfoodfacts.github.io/openfoodfacts-server/api/ref-api-and-product-schema-change-log/)

| Fonction | Upstream (v0.4.0) | Ce fork |
|---|---|---|
| Lecture produit | `GET /api/v0/product/{code}` | `GET /api/v3.6/product/{code}` (schéma produit 1004 épinglé) |
| Paramètres de lecture | aucun | `fields`, `lc`, `cc`, `tags_lc` (optionnels, rétrocompatibles) |
| Écriture produit | `cgi/product_jqm2.pl` (formulaire legacy) | nouvelle méthode `updateProduct()` : `PATCH /api/v3.6/product/{code}` en JSON structuré (champs par langue, tags, packagings, sélection d'images) |
| Upload d'image | `cgi/product_image_upload.pl` (multipart, food uniquement) | `POST /api/v3.6/product/{code}/images` (base64 + structure `selected` par champ/langue, tous les flavors) |
| Enveloppe de réponse | `status` 0/1 | enveloppe v3 (`status`, `result`, `errors[]`, `warnings[]`) avec messages d'erreur lisibles extraits de `errors[]` |
| Écriture partiellement rejetée | non détectable | `success_with_errors` → `ProductUpdateException` (l'enveloppe complète reste accessible via `getResponse()`) ; `success_with_warnings` journalisé via le logger |
| Produit d'un autre type (cross-flavor) | non géré | paramètre `product_type` de `getProduct()` (ex. `'all'`) : le serveur redirige vers le bon flavor, redirection suivie automatiquement |
| Redirections | `strict = false` (un PATCH/POST redirigé en 301/302 serait rétrogradé en GET sans corps) | mode `strict` : la méthode et le corps sont préservés sur les redirections |

Détails d'implémentation :

- `Api::API_VERSION = '3.6'` (constante publique) : toutes les routes produit sont versionnées, la structure de réponse reste stable même quand le serveur évolue.
- `addNewProduct()` (cgi legacy) est conservée mais **dépréciée** au profit de `updateProduct()`.
- `Document::__get` renvoie `null` pour un champ absent (au lieu d'un warning PHP) : indispensable avec la v3.6, qui supprime les champs `*_hierarchy` et `*_lc` au profit de `tags_sources`, et depuis la v3.1 renomme `ecoscore_*` en `environmental_score_*`.

### 2. Corrections de bugs (présents dans l'upstream)

- **Les réponses POST ne sont plus mises en cache** : dans l'upstream, un second envoi identique (`addNewProduct`, `uploadImage`) dans la durée de vie du cache ne faisait jamais la requête — une écriture silencieusement perdue. La clé de cache entrait de plus en collision pour tous les uploads (ressource non sérialisable par `json_encode`).
- **TTL sur le cache de lecture (1 h)** : l'upstream cachait les fiches produit sans expiration.
- **Décodage JSON strict** (`JSON_THROW_ON_ERROR`) et **vérification du statut HTTP** : une page HTML d'erreur du serveur provoquait un `TypeError` dans l'upstream.
- **Validation du code-barres** (chiffres uniquement) et encodage URL : plus d'injection possible de segments d'URL via `getProduct()`.
- `404` → `ProductNotFoundException`, credentials manquants → `MissingCredentialsException`, code-barres invalide → `InvalidParameterException`.
- **`activeTestMode()` distingue le htaccess du staging des identifiants du contributeur** : `off`/`off` est la protection htaccess (HTTP Basic) du serveur de test `.net`, pas un compte contributeur. Le SDK l'envoie dans l'en-tête `Authorization`, tandis que les identifiants du contributeur — fournis via `authentification()` — partent dans le corps des écritures v3 (`user_id`/`password`). L'upstream confondait les deux : `activeTestMode()` écrasait les identifiants du contributeur avec `off`/`off`, faisant échouer toute écriture sur le staging.

### 3. Documentation et exemples réparés

- Les exemples du README upstream ne compilaient plus (le constructeur exige `$userAgent` en premier argument depuis la 0.4.0) ; `cached_example.php` passait en plus un `int` à `getProduct(string)`.
- Le badge Travis CI mort a été retiré.

### 4. Tests

- Nouvelle suite unitaire `tests/Unit/OpenFoodFacts/ApiV3Test.php` (11 tests sur `MockHandler` Guzzle) : URL versionnée, enveloppe v3, 404, erreurs lisibles, corps PATCH, payload base64 — sans dépendre de l'API live.
- Tests d'intégration mis en cohérence (la restriction « upload food uniquement » n'existe plus en v3).

## Compatibilité

- PHP **8.1 → 8.4** (aucune syntaxe au-delà de 8.1 ; testé notamment sous 8.3).
- Dépendances inchangées : Guzzle 7, PSR-3, PSR-16.
- API publique rétrocompatible, à trois exceptions près : `uploadImage()` retourne désormais l'enveloppe v3 et fonctionne pour tous les flavors ; les codes-barres non numériques sont rejetés ; `activeTestMode()` ne pose plus `off`/`off` que comme htaccess du `.net` — pour écrire sur le staging, fournissez un compte contributeur via `authentification()`.
- Les nouvelles exceptions (`InvalidParameterException`, `UnknownException`, `ProductUpdateException`) étendent `BadRequestException` : un `catch (BadRequestException)` écrit contre l'upstream continue de les attraper.

## Licence et crédits

Code sous licence MIT, comme l'upstream. Les données Open Food Facts sont sous licence [OdBL](https://opendatacommons.org/licenses/odbl/summary/) : mentionnez la source et contribuez en retour les produits que vous ajoutez. Merci aux auteurs du wrapper original ([liste](https://github.com/openfoodfacts/openfoodfacts-php#authors)) et à la communauté Open Food Facts.
