<?php
/**
 * Server-side allow-list for product writes sent to `updateProduct()`.
 *
 * The browser is never trusted to decide what may be written. This file is the single place
 * where the shape of an accepted patch is defined, and `api.php` runs it *before* any call to
 * the wrapper, so an invalid patch never reaches Open Food Facts.
 *
 * The rules mirror the OFF v3.6 product schema used by the wrapper:
 *  - localized free text uses a language suffix (`product_name_fr`, `ingredients_text_en`, ...);
 *  - taxonomised lists use the `*_tags` fields and carry one canonical tag per entry
 *    (`en:organic`, `fr:label-rouge`, ...);
 *  - nutrition is written through `nutrition.input_sets`, never through the aggregated set:
 *    aggregates, estimates and derived scores are computed by Open Food Facts and are
 *    read-only here (see the explicit rejection of `nova-group` and of any unknown field).
 *
 * @package off-lab/playground
 * @license MIT
 */
declare(strict_types=1);

/**
 * Validate the product payload, never the outer SDK credentials/environment.
 *
 * @param mixed $patch Decoded `product` object from the request body.
 * @return array<string,mixed> The same patch, unchanged, once every field is accepted.
 * @throws InvalidArgumentException On any unknown field, wrong type, or out-of-range value.
 *         The message is user-facing, translated through `app/i18n.php`, and returned as a 400
 *         by `api.php`.
 */
function validateProductPatch(mixed $patch): array {
    // A patch is a JSON object (not a list), small enough to stay cheap to validate.
    if (!is_array($patch) || array_is_list($patch) || count($patch) > 100) {
        throw new InvalidArgumentException(t('patch.object_required'));
    }
    // Hard size ceiling, checked on the re-encoded payload rather than on the raw request body.
    if (strlen(json_encode($patch, JSON_THROW_ON_ERROR)) > 150000) throw new InvalidArgumentException(t('patch.too_large'));
    // Taxonomy list fields. Anything not listed here is refused, including `*_tags` fields that
    // Open Food Facts computes itself (additives_tags, ingredients_analysis_tags, ...).
    $tags = ['brands_tags','labels_tags','categories_tags','countries_tags','origins_tags','stores_tags','allergens_tags','traces_tags','manufacturing_places_tags','purchase_places_tags','packaging_tags'];
    foreach ($patch as $field => $value) {
        // 1. Localized free text (`<field>_<lc>`) and the three language-independent text fields.
        if (preg_match('/^(product_name|generic_name|ingredients_text|packaging_text|conservation_conditions|preparation)_[a-z]{2,3}(?:-[a-z]{2})?$/D', $field) || in_array($field, ['quantity','serving_size','link'], true)) {
            if (!is_string($value) || strlen($value) > 20000) throw new InvalidArgumentException(t('patch.bad_text', ['field' => $field]));
        // 2. Taxonomy lists: a JSON list of strings, each one a tag such as `en:organic`.
        } elseif (in_array($field, $tags, true)) {
            if (!is_array($value) || !array_is_list($value) || count($value) > 200) throw new InvalidArgumentException(t('patch.bad_list', ['field' => $field]));
            foreach ($value as $tag) if (!is_string($tag) || strlen($tag) > 300) throw new InvalidArgumentException(t('patch.bad_tag', ['field' => $field]));
        // 3. Nutrition, restricted to `nutrition.input_sets` (schema 1004).
        //    Each set describes where the numbers come from (packaging label or manufacturer),
        //    whether they apply to the product as sold or prepared, and the reference base.
        //    Values travel as `value_string` so that "0", "0.00" and trailing decimals are kept
        //    exactly as typed; no unit conversion or rounding happens on this side.
        } elseif ($field === 'nutrition') {
            if (!is_array($value) || array_diff(array_keys($value), ['input_sets']) || !isset($value['input_sets']) || !is_array($value['input_sets']) || !array_is_list($value['input_sets']) || !$value['input_sets'] || count($value['input_sets']) > 20) throw new InvalidArgumentException(t('patch.nutrition_input_sets'));
            foreach ($value['input_sets'] as $set) {
                if (!is_array($set) || array_diff(array_keys($set), ['source','preparation','per','per_quantity','per_unit','nutrients'])) throw new InvalidArgumentException(t('patch.bad_set'));
                // Only sources a contributor can legitimately transcribe are writable:
                // `estimate` and any aggregated/derived source stay read-only.
                if (!in_array($set['source'] ?? '', ['packaging','manufacturer'], true) || !in_array($set['preparation'] ?? '', ['as_sold','prepared'], true) || !in_array($set['per'] ?? '', ['100g','100ml','serving'], true)) throw new InvalidArgumentException(t('patch.bad_source'));
                if (isset($set['per_quantity']) && (!is_numeric($set['per_quantity']) || $set['per_quantity'] < 0)) throw new InvalidArgumentException(t('patch.bad_per_quantity'));
                if (isset($set['per_unit']) && !in_array($set['per_unit'], ['g','ml'], true)) throw new InvalidArgumentException(t('patch.bad_per_unit'));
                if (!isset($set['nutrients']) || !is_array($set['nutrients']) || array_is_list($set['nutrients']) || count($set['nutrients']) > 200) throw new InvalidArgumentException(t('patch.nutrients_required'));
                // Nutrient ids follow the OFF naming (`saturated-fat`, `energy-kj`, ...).
                // `nova-group` is explicitly rejected: it is computed by OFF from the ingredients.
                foreach ($set['nutrients'] as $id => $n) {
                    if ($id === 'nova-group' || !preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/D', $id) || !is_array($n) || array_diff(array_keys($n), ['value_string','unit','modifier'])) throw new InvalidArgumentException(t('patch.bad_nutrient'));
                    // The value must be a numeric *string*: finite and non-negative, but with its
                    // original textual form preserved (leading zeros, decimals, "0").
                    if (!isset($n['value_string']) || !is_string($n['value_string']) || !is_numeric($n['value_string']) || !is_finite((float)$n['value_string']) || (float)$n['value_string'] < 0) throw new InvalidArgumentException(t('patch.bad_value', ['field' => $id]));
                    if (!isset($n['unit']) || !is_string($n['unit']) || strlen($n['unit']) > 20 || !in_array($n['modifier'] ?? '', ['', '<', '>', '~', '≤', '≥'], true)) throw new InvalidArgumentException(t('patch.bad_unit', ['field' => $id]));
                }
            }
        // 4. Structured packaging components. The wrapper forwards the list as-is; OFF validates
        //    the individual component shapes, so only the outer JSON list type is checked here.
        } elseif (in_array($field, ['packagings','packagings_add'], true)) {
            if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException(t('patch.json_list', ['field' => $field]));
        // 5. Completion flag for the packaging section.
        } elseif ($field === 'packagings_complete') {
            if (!in_array($value, [0,1,false,true], true)) throw new InvalidArgumentException(t('patch.packagings_complete'));
        } else {
            // Default deny: computed scores (nutriscore, nova, environmental score), image
            // metadata and every unknown field end up here.
            throw new InvalidArgumentException(t('patch.field_readonly', ['field' => $field]));
        }
    }
    return $patch;
}
