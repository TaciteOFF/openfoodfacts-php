<?php
/**
 * Server-side translation catalogue for the interface.
 *
 * The demo ships in two interface languages, French and English. This file holds every string
 * the *server* produces:
 *  - the static markup rendered by `public/index.php`;
 *  - every user-facing message returned by `public/api.php`, `public/bootstrap.php` and
 *    `app/product-patch.php`.
 *
 * Dynamic strings produced by the browser live in `public/assets/i18n.js` instead. A handful of
 * labels exist in both catalogues on purpose: they are printed once by PHP for the first paint
 * and then rewritten by JavaScript as the state changes (the environment note, for instance).
 * Keys are identical on both sides, so the two files stay easy to diff.
 *
 * The interface language is *not* the product language: `ui_locale()` decides in which language
 * the page speaks, while the language selector inside the Product tab decides which translation
 * of the product data is requested from Open Food Facts.
 *
 * Resolution order for the interface language: the value stored in the session (set through the
 * `interface` action of api.php), then the browser's `Accept-Language`, then French.
 *
 * @package off-lab/playground
 * @license MIT
 */
declare(strict_types=1);

/** Interface languages this demo ships with. The first one is the default. */
const UI_LOCALES = ['fr', 'en'];

/**
 * Current interface locale.
 *
 * @return string One of UI_LOCALES.
 */
function ui_locale(): string {
    if (isset($_SESSION['ui']) && in_array($_SESSION['ui'], UI_LOCALES, true)) return $_SESSION['ui'];
    return ui_locale_from_browser($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
}

/**
 * Pick an interface locale from an `Accept-Language` header.
 *
 * Quality values are honoured, unknown languages are ignored, and French is the fallback.
 * Kept separate from `ui_locale()` so the integration suite can test it without a session.
 *
 * @param string $header Raw header value, possibly empty.
 * @return string One of UI_LOCALES.
 */
function ui_locale_from_browser(string $header): string {
    $best = ['fr', -1.0];
    foreach (explode(',', $header) as $part) {
        $bits = explode(';q=', trim($part));
        $tag = strtolower(trim($bits[0]));
        $quality = isset($bits[1]) && is_numeric($bits[1]) ? (float)$bits[1] : 1.0;
        $language = explode('-', $tag)[0];
        if (in_array($language, UI_LOCALES, true) && $quality > $best[1]) $best = [$language, $quality];
    }
    return $best[0];
}

/**
 * Translate one key into the current interface locale.
 *
 * Placeholders are written `{name}` and replaced from `$params`. A missing key returns the key
 * itself, which makes an oversight visible in the interface instead of printing an empty string.
 *
 * @param string $key Catalogue key, e.g. `err.barcode`.
 * @param array<string,string|int> $params Placeholder values.
 */
function t(string $key, array $params = []): string {
    static $catalogue = null;
    $catalogue ??= ui_catalogue();
    $locale = ui_locale();
    $text = $catalogue[$locale][$key] ?? $catalogue['fr'][$key] ?? $key;
    if (!$params) return $text;
    $replacements = [];
    foreach ($params as $name => $value) $replacements['{' . $name . '}'] = (string)$value;
    return strtr($text, $replacements);
}

/**
 * Translate and HTML-escape in one call, for use inside `index.php`.
 *
 * @param string $key Catalogue key.
 * @param array<string,string|int> $params Placeholder values.
 */
function e(string $key, array $params = []): string {
    return htmlspecialchars(t($key, $params), ENT_QUOTES);
}

/**
 * The catalogue itself: one flat map of key => text per locale.
 *
 * Sections, in order: interface markup (`ui.*`), API messages (`err.*`, `msg.*`) and write
 * validation messages (`patch.*`).
 *
 * @return array<string,array<string,string>>
 */
function ui_catalogue(): array {
    return [
        'fr' => [
            // --- Interface markup rendered by index.php ---
            'ui.meta_description' => 'Démo développeur du wrapper PHP TaciteOFF/openfoodfacts-php.',
            'ui.nav_links' => 'Liens',
            'ui.interface_language' => 'Langue de l’interface',
            'ui.account' => 'Compte',
            'ui.env_group' => 'Environnement Open Food Facts',
            'ui.production' => 'Production',
            'ui.staging' => 'Staging',
            'ui.env_note_prod' => 'fr.openfoodfacts.org · lecture seule',
            'ui.features' => 'Fonctionnalités',
            'ui.tab_product' => 'Produit',
            'ui.tab_search' => 'Recherche',
            'ui.tab_upload' => 'Upload',
            'ui.barcode' => 'Code-barres',
            'ui.barcode_placeholder' => 'Ex. 3057640385148',
            'ui.run' => 'Exécuter',
            'ui.examples' => 'Exemples',
            'ui.language' => 'Langue',
            'ui.no_result' => 'Aucun résultat.',
            'ui.search_label' => 'Produit ou marque',
            'ui.search_placeholder' => 'Ex. chocolat, yaourt, Bjorg…',
            'ui.previous' => 'Précédent',
            'ui.next' => 'Suivant',
            'ui.upload_barcode_placeholder' => 'Code-barres en staging',
            'ui.image_field' => 'Champ image',
            'ui.field_front' => 'Face avant',
            'ui.field_ingredients' => 'Ingrédients',
            'ui.field_nutrition' => 'Nutrition',
            'ui.field_packaging' => 'Emballage',
            'ui.choose_photo' => 'Choisir une photo',
            'ui.photo_constraint' => 'JPEG ou PNG · 5 Mo maximum',
            'ui.photo_preview_alt' => 'Aperçu de la photo à envoyer',
            'ui.send' => 'Envoyer',
            'ui.upload_help' => 'Crée le produit si absent et sélectionne la photo pour le champ et la langue indiqués.',
            'ui.lang_fr' => 'Français',
            'ui.lang_en' => 'Anglais',
            'ui.lang_de' => 'Allemand',
            'ui.lang_es' => 'Espagnol',
            'ui.lang_it' => 'Italien',
            'ui.response_aria' => 'Réponse du wrapper',
            'ui.response' => 'Réponse',
            'ui.pending' => 'EN ATTENTE',
            'ui.no_request_comment' => '// Aucune requête',
            'ui.no_request' => 'Aucune requête',
            'ui.copy_json' => 'Copier le JSON',
            'ui.footer_wrapper' => 'Wrapper',
            'ui.footer_data' => 'Données',
            'ui.footer_photos' => 'Photos CC BY-SA',
            'ui.account_dialog_title' => 'Compte OFF',
            'ui.close' => 'Fermer',
            'ui.auth_notice' => 'ne valide pas le compte. Validation lors d’une écriture.',
            'ui.username' => 'Identifiant OFF',
            'ui.password' => 'Mot de passe',
            'ui.account_help' => 'Session serveur · expiration après 30 min d’inactivité · effacement au changement d’environnement.',
            'ui.save' => 'Enregistrer',
            'ui.logout' => 'Déconnexion',
            'ui.editor_title' => 'Modifier',
            'ui.editor_close' => 'Fermer l’éditeur',
            'ui.new_language' => 'Nouvelle langue',
            'ui.new_language_placeholder' => 'ex. pt',
            'ui.add' => 'Ajouter',
            'ui.patch' => 'Patch',
            'ui.reset' => 'Réinitialiser',
            'ui.save_staging' => 'Enregistrer en staging',
            'ui.noscript' => 'JavaScript requis.',

            // --- Messages returned by the API ---
            'label.redacted' => '[masqué]',
            'err.invalid_value' => 'Valeur invalide : {field}',
            'err.barcode' => 'Saisissez un code-barres de 4 à 24 chiffres.',
            'err.write_blocked' => 'Écriture bloquée : destination hors staging.',
            'err.method' => 'Méthode non autorisée.',
            'err.session_expired' => 'Session expirée. Rechargez la page.',
            'err.bad_request' => 'Requête invalide.',
            'err.bad_environment' => 'Environnement invalide.',
            'err.bad_interface_language' => 'Langue d’interface invalide.',
            'err.credentials_required' => 'Identifiant et mot de passe requis.',
            'err.unknown_action' => 'Action inconnue.',
            'err.write_production' => 'Les écritures sont interdites en production. Passez en staging.',
            'err.account_required' => 'Renseignez votre compte staging avant l’envoi.',
            'err.rate_limited' => 'Patientez quelques secondes avant de réessayer.',
            'err.bad_language' => 'Langue invalide.',
            'err.query_short' => 'Saisissez au moins deux caractères.',
            'err.bad_page' => 'Page invalide.',
            'err.bad_photo_field' => 'Type de photo ou langue invalide.',
            'err.file_missing' => 'Fichier absent ou trop volumineux (5 Mo maximum).',
            'err.file_too_large' => 'La photo dépasse 5 Mo.',
            'err.file_type' => 'Choisissez une image JPEG ou PNG valide.',
            'err.not_found' => 'Aucun produit trouvé pour ce code-barres dans cet environnement.',
            'err.update_refused' => 'Écriture refusée ou partiellement appliquée. Consultez la réponse et relisez le produit avant de réessayer.',
            'err.bad_json' => 'Requête JSON invalide.',
            'err.upstream' => 'Open Food Facts n’a pas pu traiter la demande. Vérifiez l’environnement, vos identifiants pour un envoi, ou réessayez plus tard.',
            'err.failed' => 'La demande a échoué. Réessayez dans quelques instants.',
            'msg.credentials_stored' => 'Identifiants enregistrés en session, non vérifiés.',

            // --- Write validation (app/product-patch.php) ---
            'patch.object_required' => 'Le patch doit être un objet JSON non vide (100 champs maximum).',
            'patch.too_large' => 'Patch trop volumineux (150 Ko maximum).',
            'patch.bad_text' => 'Texte invalide : {field}',
            'patch.bad_list' => 'Liste invalide : {field}',
            'patch.bad_tag' => 'Tag invalide : {field}',
            'patch.nutrition_input_sets' => 'Utilisez nutrition.input_sets (liste non vide). Les agrégats sont calculés par OFF.',
            'patch.bad_set' => 'Jeu nutritionnel invalide.',
            'patch.bad_source' => 'Source, préparation ou base nutritionnelle invalide.',
            'patch.bad_per_quantity' => 'Quantité de portion invalide.',
            'patch.bad_per_unit' => 'Unité de portion invalide.',
            'patch.nutrients_required' => 'Objet nutrients non vide requis.',
            'patch.bad_nutrient' => 'Nutriment invalide ou champ calculé interdit.',
            'patch.bad_value' => 'Valeur nutritionnelle invalide : {field}',
            'patch.bad_unit' => 'Unité ou modificateur invalide : {field}',
            'patch.json_list' => 'Liste JSON requise : {field}',
            'patch.packagings_complete' => 'packagings_complete : 0 ou 1 requis.',
            'patch.field_readonly' => 'Champ non modifiable : {field}. Utilisez les champs sources ou les suffixes de langue.',
        ],
        'en' => [
            // --- Interface markup rendered by index.php ---
            'ui.meta_description' => 'Developer demo of the TaciteOFF/openfoodfacts-php PHP wrapper.',
            'ui.nav_links' => 'Links',
            'ui.interface_language' => 'Interface language',
            'ui.account' => 'Account',
            'ui.env_group' => 'Open Food Facts environment',
            'ui.production' => 'Production',
            'ui.staging' => 'Staging',
            'ui.env_note_prod' => 'fr.openfoodfacts.org · read-only',
            'ui.features' => 'Features',
            'ui.tab_product' => 'Product',
            'ui.tab_search' => 'Search',
            'ui.tab_upload' => 'Upload',
            'ui.barcode' => 'Barcode',
            'ui.barcode_placeholder' => 'e.g. 3057640385148',
            'ui.run' => 'Run',
            'ui.examples' => 'Examples',
            'ui.language' => 'Language',
            'ui.no_result' => 'No result.',
            'ui.search_label' => 'Product or brand',
            'ui.search_placeholder' => 'e.g. chocolate, yoghurt, Bjorg…',
            'ui.previous' => 'Previous',
            'ui.next' => 'Next',
            'ui.upload_barcode_placeholder' => 'Barcode on staging',
            'ui.image_field' => 'Image field',
            'ui.field_front' => 'Front',
            'ui.field_ingredients' => 'Ingredients',
            'ui.field_nutrition' => 'Nutrition',
            'ui.field_packaging' => 'Packaging',
            'ui.choose_photo' => 'Choose a photo',
            'ui.photo_constraint' => 'JPEG or PNG · 5 MB maximum',
            'ui.photo_preview_alt' => 'Preview of the photo to upload',
            'ui.send' => 'Upload',
            'ui.upload_help' => 'Creates the product when missing, and selects the photo for the given field and language.',
            'ui.lang_fr' => 'French',
            'ui.lang_en' => 'English',
            'ui.lang_de' => 'German',
            'ui.lang_es' => 'Spanish',
            'ui.lang_it' => 'Italian',
            'ui.response_aria' => 'Wrapper response',
            'ui.response' => 'Response',
            'ui.pending' => 'IDLE',
            'ui.no_request_comment' => '// No request yet',
            'ui.no_request' => 'No request yet',
            'ui.copy_json' => 'Copy JSON',
            'ui.footer_wrapper' => 'Wrapper',
            'ui.footer_data' => 'Data',
            'ui.footer_photos' => 'Photos CC BY-SA',
            'ui.account_dialog_title' => 'OFF account',
            'ui.close' => 'Close',
            'ui.auth_notice' => 'does not validate the account. Validation happens on a write.',
            'ui.username' => 'OFF username',
            'ui.password' => 'Password',
            'ui.account_help' => 'Server-side session · expires after 30 min of inactivity · cleared when the environment changes.',
            'ui.save' => 'Save',
            'ui.logout' => 'Log out',
            'ui.editor_title' => 'Edit',
            'ui.editor_close' => 'Close the editor',
            'ui.new_language' => 'New language',
            'ui.new_language_placeholder' => 'e.g. pt',
            'ui.add' => 'Add',
            'ui.patch' => 'Patch',
            'ui.reset' => 'Reset',
            'ui.save_staging' => 'Save to staging',
            'ui.noscript' => 'JavaScript required.',

            // --- Messages returned by the API ---
            'label.redacted' => '[redacted]',
            'err.invalid_value' => 'Invalid value: {field}',
            'err.barcode' => 'Enter a barcode of 4 to 24 digits.',
            'err.write_blocked' => 'Write blocked: destination outside staging.',
            'err.method' => 'Method not allowed.',
            'err.session_expired' => 'Session expired. Reload the page.',
            'err.bad_request' => 'Invalid request.',
            'err.bad_environment' => 'Invalid environment.',
            'err.bad_interface_language' => 'Invalid interface language.',
            'err.credentials_required' => 'Username and password are required.',
            'err.unknown_action' => 'Unknown action.',
            'err.write_production' => 'Writes are not allowed in production. Switch to staging.',
            'err.account_required' => 'Enter your staging account before sending.',
            'err.rate_limited' => 'Wait a few seconds before trying again.',
            'err.bad_language' => 'Invalid language.',
            'err.query_short' => 'Enter at least two characters.',
            'err.bad_page' => 'Invalid page.',
            'err.bad_photo_field' => 'Invalid photo type or language.',
            'err.file_missing' => 'File missing or too large (5 MB maximum).',
            'err.file_too_large' => 'The photo exceeds 5 MB.',
            'err.file_type' => 'Choose a valid JPEG or PNG image.',
            'err.not_found' => 'No product found for this barcode in this environment.',
            'err.update_refused' => 'Write refused or only partially applied. Check the response and re-read the product before trying again.',
            'err.bad_json' => 'Invalid JSON request.',
            'err.upstream' => 'Open Food Facts could not process the request. Check the environment, your credentials for a write, or try again later.',
            'err.failed' => 'The request failed. Try again in a moment.',
            'msg.credentials_stored' => 'Credentials stored in the session, not verified.',

            // --- Write validation (app/product-patch.php) ---
            'patch.object_required' => 'The patch must be a non-empty JSON object (100 fields maximum).',
            'patch.too_large' => 'Patch too large (150 KB maximum).',
            'patch.bad_text' => 'Invalid text: {field}',
            'patch.bad_list' => 'Invalid list: {field}',
            'patch.bad_tag' => 'Invalid tag: {field}',
            'patch.nutrition_input_sets' => 'Use nutrition.input_sets (non-empty list). Aggregates are computed by OFF.',
            'patch.bad_set' => 'Invalid nutrition set.',
            'patch.bad_source' => 'Invalid nutrition source, preparation or base.',
            'patch.bad_per_quantity' => 'Invalid serving quantity.',
            'patch.bad_per_unit' => 'Invalid serving unit.',
            'patch.nutrients_required' => 'A non-empty nutrients object is required.',
            'patch.bad_nutrient' => 'Invalid nutrient, or computed field not writable.',
            'patch.bad_value' => 'Invalid nutrition value: {field}',
            'patch.bad_unit' => 'Invalid unit or modifier: {field}',
            'patch.json_list' => 'A JSON list is required: {field}',
            'patch.packagings_complete' => 'packagings_complete: 0 or 1 required.',
            'patch.field_readonly' => 'Field not writable: {field}. Use the source fields or the language suffixes.',
        ],
    ];
}
