/**
 * i18n.js - client-side translation catalogue.
 *
 * Counterpart of `app/i18n.php`: this file holds every string the *browser* produces at runtime
 * (status messages, rendered product sections, nutrient labels, editor help texts), while PHP
 * owns the static markup and the API messages. Keys are shared between both catalogues wherever
 * a label is printed once by PHP and then rewritten by JavaScript.
 *
 * The active locale comes from `<html lang>`, which `index.php` renders from the session, so the
 * page is never momentarily in the wrong language. Under Node - where there is no document - the
 * locale defaults to French and can be set explicitly with `setLocale()`, which is what the test
 * suites use to assert both languages.
 *
 * Loaded as a classic script before product-data.js, product-editor.js and app.js, and required
 * as a CommonJS module by the tests.
 *
 * @license MIT
 */
(function (root) {
  'use strict';
  /** Supported interface locales; the first one is the fallback. */
  const LOCALES = ['fr', 'en'];

  const catalogue = {
    fr: {
      // --- Shared with app/i18n.php (printed by PHP, then rewritten here) ---
      'ui.account': 'Compte',
      'ui.no_result': 'Aucun résultat.',
      'ui.choose_photo': 'Choisir une photo',
      'ui.copy_json': 'Copier le JSON',
      'ui.pending': 'EN ATTENTE',
      'ui.no_request': 'Aucune requête',
      'ui.no_request_comment': '// Aucune requête',
      'ui.editor_title': 'Modifier',

      // --- Session state and gates ---
      'state.env_prod': 'fr.openfoodfacts.org · lecture seule',
      'state.env_staging': 'world.openfoodfacts.net · lecture / écriture',
      'state.environment': 'Environnement : {environment}',
      'state.verified': '{username} · Identifiants acceptés lors du dernier envoi.',
      'state.unverified': '{username} · Identifiants enregistrés, non vérifiés.',
      'state.env_changed': 'Environnement changé · identifiants effacés.',
      'state.credentials_cleared': 'Identifiants effacés.',
      'gate.staging_only': 'Upload réservé au staging.',
      'gate.credentials': 'Identifiants staging requis.',
      'gate.switch_staging': 'Passer en staging',
      'gate.account': 'Compte staging',

      // --- Requests and the response panel ---
      'request.busy': 'Une requête est déjà en cours.',
      'request.running': 'EN COURS',
      'request.in_progress': 'Requête en cours…',
      'request.waiting': '// En attente de la réponse Open Food Facts…',
      'request.no_json': 'Le serveur n’a pas renvoyé de JSON. Vérifiez la configuration PHP et la limite de taille des fichiers.',
      'request.truncated': '\n… Aperçu limité. « Copier le JSON » copie la réponse complète.',
      'request.ok': 'OK · {status}',
      'request.error_status': 'ERREUR · {status}',
      'request.error': 'ERREUR',
      'request.duration': '{duration} ms · {environment}',
      'request.failed': 'La requête a échoué.',
      'request.timeout': 'Le serveur met trop de temps à répondre. Réessayez dans quelques instants.',
      'request.backend': 'Le backend PHP est indisponible. Lancez l’application avec Composer, puis rechargez la page.',
      'request.copied': 'Copié !',
      'request.copy_failed': 'La copie est indisponible. Vous pouvez sélectionner le JSON dans le panneau.',
      'env.prod': 'production',
      'env.staging': 'staging',

      // --- Product and search ---
      'product.loading': 'Chargement…',
      'product.error': 'Erreur',
      'product.unnamed': 'Produit sans nom',
      'product.no_photo': 'Photo absente',
      'product.photo_unavailable': 'Photo indisponible',
      'product.off_page': 'Fiche OFF ↗',
      'product.photos': 'Photos',
      'product.all_fields': 'Tous les champs',
      'product.fallback_language': ' (repli)',
      'search.loading': 'Recherche…',
      'search.count': '{count} résultats · {query}',
      'search.page': 'Page {page}',
      'search.interrupted': 'Recherche interrompue',

      // --- Upload ---
      'upload.account_required': 'Un compte staging est nécessaire.',
      'upload.sending': 'Envoi en cours…',
      'upload.warnings': 'Upload avec avertissements : voir JSON.',
      'upload.success': 'Upload réussi · staging.',
      'upload.bad_file': 'Choisissez une photo JPEG ou PNG de 5 Mo maximum.',

      // --- Row editor ---
      'editor.edit': 'Modifier',
      'editor.edit_aria': 'Modifier {label}',
      'editor.title': 'Modifier · {label}',
      'editor.context': '{code} · staging',
      'editor.help_localized': 'Brouillon conservé par langue. Vider le champ efface le texte de cette langue.',
      'editor.help_tags': 'Un tag par ligne (ex. en:organic). Champ commun à toutes les langues.',
      'editor.bad_language': 'Code langue invalide (ex. fr, en, pt).',
      'editor.saving': 'Enregistrement…',
      'editor.saved_warnings': 'Enregistré avec avertissements.',
      'editor.saved': 'Enregistré en staging.',
      'editor.reread_failed': ' Relecture impossible : {message}',
      'editor.source_missing': 'Source indisponible. Rechargez la fiche.',
      'editor.nutrient': 'Nutriment',
      'editor.value': 'Valeur',
      'editor.unit': 'Unité',
      'editor.modifier': 'Mod.',
      'editor.value_aria': '{id} valeur',
      'editor.unit_aria': '{id} unité',
      'editor.modifier_aria': '{id} modificateur',
      'editor.duplicate_field': 'Champ déjà modifié dans le formulaire : {field}',
      'editor.forbidden_field': 'Champ interdit.',

      // --- Rendered product sections (product-data.js) ---
      'data.information': 'Informations',
      'data.nutrition': 'Nutrition',
      'data.nutrition_aria': 'Données nutritionnelles',
      'data.nutrient': 'Nutriment',
      'data.value': 'Valeur',
      'data.basis': 'Base',
      'data.source': 'Source',
      'data.edit': 'Modifier',
      'data.computed': 'calculée',
      'data.entered': 'saisie',
      'data.input_sets': 'Sources nutritionnelles',
      'data.legacy': 'legacy',

      // --- Curated information rows ---
      'attr.generic_name': 'Dénomination',
      'attr.brands': 'Marques',
      'attr.quantity': 'Quantité',
      'attr.serving_size': 'Portion',
      'attr.labels': 'Labels',
      'attr.labels_tags': 'Labels · tags',
      'attr.categories': 'Catégories',
      'attr.categories_tags': 'Catégories · tags',
      'attr.ingredients_text': 'Ingrédients',
      'attr.allergens': 'Allergènes',
      'attr.allergens_tags': 'Allergènes · tags',
      'attr.traces': 'Traces',
      'attr.traces_tags': 'Traces · tags',
      'attr.additives_tags': 'Additifs',
      'attr.ingredients_analysis_tags': 'Analyse des ingrédients',
      'attr.origins': 'Origines',
      'attr.origins_tags': 'Origines · tags',
      'attr.manufacturing_places': 'Fabrication',
      'attr.manufacturing_places_tags': 'Fabrication · tags',
      'attr.countries': 'Pays',
      'attr.countries_tags': 'Pays · tags',
      'attr.stores': 'Magasins',
      'attr.stores_tags': 'Magasins · tags',
      'attr.packaging': 'Emballage',
      'attr.packaging_text': 'Détails emballage',
      'attr.packaging_tags': 'Emballage · tags',
      'attr.conservation_conditions': 'Conservation',
      'attr.preparation': 'Préparation',
      'attr.expiration_date': 'Date indiquée',
      'attr.link': 'Lien fabricant',
      'attr.product_name': 'Nom',

      // --- Nutrient display names ---
      'nutrient.energy': 'Énergie',
      'nutrient.energy-kcal': 'Énergie (kcal)',
      'nutrient.energy-kj': 'Énergie (kJ)',
      'nutrient.fat': 'Matières grasses',
      'nutrient.saturated-fat': 'Acides gras saturés',
      'nutrient.monounsaturated-fat': 'Acides gras mono-insaturés',
      'nutrient.polyunsaturated-fat': 'Acides gras polyinsaturés',
      'nutrient.trans-fat': 'Acides gras trans',
      'nutrient.carbohydrates': 'Glucides',
      'nutrient.sugars': 'Sucres',
      'nutrient.added-sugars': 'Sucres ajoutés',
      'nutrient.fiber': 'Fibres',
      'nutrient.proteins': 'Protéines',
      'nutrient.salt': 'Sel',
      'nutrient.sodium': 'Sodium',
      'nutrient.calcium': 'Calcium',
      'nutrient.iron': 'Fer',
      'nutrient.magnesium': 'Magnésium',
      'nutrient.potassium': 'Potassium',
      'nutrient.cholesterol': 'Cholestérol',
      'nutrient.starch': 'Amidon',
      'nutrient.water': 'Eau',
      'nutrient.alcohol': 'Alcool',
      'nutrient.nova-group': 'Groupe NOVA'
    },
    en: {
      // --- Shared with app/i18n.php (printed by PHP, then rewritten here) ---
      'ui.account': 'Account',
      'ui.no_result': 'No result.',
      'ui.choose_photo': 'Choose a photo',
      'ui.copy_json': 'Copy JSON',
      'ui.pending': 'IDLE',
      'ui.no_request': 'No request yet',
      'ui.no_request_comment': '// No request yet',
      'ui.editor_title': 'Edit',

      // --- Session state and gates ---
      'state.env_prod': 'fr.openfoodfacts.org · read-only',
      'state.env_staging': 'world.openfoodfacts.net · read / write',
      'state.environment': 'Environment: {environment}',
      'state.verified': '{username} · Credentials accepted on the last write.',
      'state.unverified': '{username} · Credentials stored, not verified.',
      'state.env_changed': 'Environment changed · credentials cleared.',
      'state.credentials_cleared': 'Credentials cleared.',
      'gate.staging_only': 'Upload is staging-only.',
      'gate.credentials': 'Staging credentials required.',
      'gate.switch_staging': 'Switch to staging',
      'gate.account': 'Staging account',

      // --- Requests and the response panel ---
      'request.busy': 'A request is already running.',
      'request.running': 'RUNNING',
      'request.in_progress': 'Request in progress…',
      'request.waiting': '// Waiting for the Open Food Facts response…',
      'request.no_json': 'The server did not return JSON. Check the PHP configuration and the upload size limits.',
      'request.truncated': '\n… Preview truncated. “Copy JSON” copies the full response.',
      'request.ok': 'OK · {status}',
      'request.error_status': 'ERROR · {status}',
      'request.error': 'ERROR',
      'request.duration': '{duration} ms · {environment}',
      'request.failed': 'The request failed.',
      'request.timeout': 'The server is taking too long to answer. Try again in a moment.',
      'request.backend': 'The PHP backend is unavailable. Start the application with Composer, then reload the page.',
      'request.copied': 'Copied!',
      'request.copy_failed': 'Copying is unavailable. You can select the JSON in the panel instead.',
      'env.prod': 'production',
      'env.staging': 'staging',

      // --- Product and search ---
      'product.loading': 'Loading…',
      'product.error': 'Error',
      'product.unnamed': 'Unnamed product',
      'product.no_photo': 'No photo',
      'product.photo_unavailable': 'Photo unavailable',
      'product.off_page': 'OFF page ↗',
      'product.photos': 'Photos',
      'product.all_fields': 'All fields',
      'product.fallback_language': ' (fallback)',
      'search.loading': 'Searching…',
      'search.count': '{count} results · {query}',
      'search.page': 'Page {page}',
      'search.interrupted': 'Search interrupted',

      // --- Upload ---
      'upload.account_required': 'A staging account is required.',
      'upload.sending': 'Uploading…',
      'upload.warnings': 'Upload completed with warnings: see the JSON.',
      'upload.success': 'Upload succeeded · staging.',
      'upload.bad_file': 'Choose a JPEG or PNG photo of 5 MB maximum.',

      // --- Row editor ---
      'editor.edit': 'Edit',
      'editor.edit_aria': 'Edit {label}',
      'editor.title': 'Edit · {label}',
      'editor.context': '{code} · staging',
      'editor.help_localized': 'Draft kept per language. Emptying the field deletes the text for that language.',
      'editor.help_tags': 'One tag per line (e.g. en:organic). Shared by every language.',
      'editor.bad_language': 'Invalid language code (e.g. fr, en, pt).',
      'editor.saving': 'Saving…',
      'editor.saved_warnings': 'Saved with warnings.',
      'editor.saved': 'Saved to staging.',
      'editor.reread_failed': ' Could not re-read the product: {message}',
      'editor.source_missing': 'Source unavailable. Reload the product.',
      'editor.nutrient': 'Nutrient',
      'editor.value': 'Value',
      'editor.unit': 'Unit',
      'editor.modifier': 'Mod.',
      'editor.value_aria': '{id} value',
      'editor.unit_aria': '{id} unit',
      'editor.modifier_aria': '{id} modifier',
      'editor.duplicate_field': 'Field already edited in the form: {field}',
      'editor.forbidden_field': 'Field not allowed.',

      // --- Rendered product sections (product-data.js) ---
      'data.information': 'Information',
      'data.nutrition': 'Nutrition',
      'data.nutrition_aria': 'Nutrition data',
      'data.nutrient': 'Nutrient',
      'data.value': 'Value',
      'data.basis': 'Basis',
      'data.source': 'Source',
      'data.edit': 'Edit',
      'data.computed': 'computed',
      'data.entered': 'entered',
      'data.input_sets': 'Nutrition sources',
      'data.legacy': 'legacy',

      // --- Curated information rows ---
      'attr.generic_name': 'Generic name',
      'attr.brands': 'Brands',
      'attr.quantity': 'Quantity',
      'attr.serving_size': 'Serving size',
      'attr.labels': 'Labels',
      'attr.labels_tags': 'Labels · tags',
      'attr.categories': 'Categories',
      'attr.categories_tags': 'Categories · tags',
      'attr.ingredients_text': 'Ingredients',
      'attr.allergens': 'Allergens',
      'attr.allergens_tags': 'Allergens · tags',
      'attr.traces': 'Traces',
      'attr.traces_tags': 'Traces · tags',
      'attr.additives_tags': 'Additives',
      'attr.ingredients_analysis_tags': 'Ingredient analysis',
      'attr.origins': 'Origins',
      'attr.origins_tags': 'Origins · tags',
      'attr.manufacturing_places': 'Manufacturing',
      'attr.manufacturing_places_tags': 'Manufacturing · tags',
      'attr.countries': 'Countries',
      'attr.countries_tags': 'Countries · tags',
      'attr.stores': 'Stores',
      'attr.stores_tags': 'Stores · tags',
      'attr.packaging': 'Packaging',
      'attr.packaging_text': 'Packaging details',
      'attr.packaging_tags': 'Packaging · tags',
      'attr.conservation_conditions': 'Storage',
      'attr.preparation': 'Preparation',
      'attr.expiration_date': 'Printed date',
      'attr.link': 'Manufacturer link',
      'attr.product_name': 'Name',

      // --- Nutrient display names ---
      'nutrient.energy': 'Energy',
      'nutrient.energy-kcal': 'Energy (kcal)',
      'nutrient.energy-kj': 'Energy (kJ)',
      'nutrient.fat': 'Fat',
      'nutrient.saturated-fat': 'Saturated fat',
      'nutrient.monounsaturated-fat': 'Monounsaturated fat',
      'nutrient.polyunsaturated-fat': 'Polyunsaturated fat',
      'nutrient.trans-fat': 'Trans fat',
      'nutrient.carbohydrates': 'Carbohydrates',
      'nutrient.sugars': 'Sugars',
      'nutrient.added-sugars': 'Added sugars',
      'nutrient.fiber': 'Fibre',
      'nutrient.proteins': 'Proteins',
      'nutrient.salt': 'Salt',
      'nutrient.sodium': 'Sodium',
      'nutrient.calcium': 'Calcium',
      'nutrient.iron': 'Iron',
      'nutrient.magnesium': 'Magnesium',
      'nutrient.potassium': 'Potassium',
      'nutrient.cholesterol': 'Cholesterol',
      'nutrient.starch': 'Starch',
      'nutrient.water': 'Water',
      'nutrient.alcohol': 'Alcohol',
      'nutrient.nova-group': 'NOVA group'
    }
  };

  // In the browser the locale comes from <html lang>, rendered by index.php from the session.
  let locale = LOCALES.includes(root.document?.documentElement?.lang) ? root.document.documentElement.lang : LOCALES[0];

  /** Active interface locale. */
  function getLocale() { return locale; }

  /**
   * Override the active locale. Used by the test suites, and by app.js before it re-renders
   * anything after an interface language change.
   * @param {string} next One of the supported locales; anything else is ignored.
   */
  function setLocale(next) { if (LOCALES.includes(next)) locale = next; }

  /**
   * Translate a key, interpolating `{name}` placeholders.
   *
   * An unknown key falls back to French and then to the key itself, so a missing translation is
   * visible rather than silently empty.
   *
   * @param {string} key Catalogue key.
   * @param {Object<string,string|number>} [params] Placeholder values.
   * @returns {string}
   */
  function t(key, params) {
    const text = catalogue[locale]?.[key] ?? catalogue[LOCALES[0]][key] ?? key;
    if (!params) return text;
    return text.replace(/\{(\w+)\}/g, (match, name) => Object.hasOwn(params, name) ? String(params[name]) : match);
  }

  /**
   * Display name of a nutrient id, falling back to the raw id for anything the catalogue does
   * not know - which is deliberate: unknown nutrients must stay visible under their real id.
   * @param {string} id Nutrient id, e.g. `saturated-fat`.
   */
  function nutrient(id) {
    const key = 'nutrient.' + id;
    return catalogue[locale]?.[key] ?? catalogue[LOCALES[0]][key] ?? id;
  }

  const api = {LOCALES, t, nutrient, getLocale, setLocale};
  // Dual export: CommonJS for the Node test suites, `window.OFFI18n` for the browser.
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.OFFI18n = api;
})(typeof window !== 'undefined' ? window : globalThis);
