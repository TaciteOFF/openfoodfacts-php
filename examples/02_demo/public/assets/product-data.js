/**
 * product-data.js - pure read/render helpers for an Open Food Facts product.
 *
 * This module holds every function that turns the JSON returned by the wrapper into HTML
 * fragments, plus the small predicates the UI needs (available languages, safe image URLs,
 * localized field lookup). It is deliberately free of DOM access and of network calls, which
 * is what makes it testable under Node (`tests/product-data.test.cjs`) as a CommonJS module
 * while the browser loads the same file as a classic script exposing `window.OFFProduct`.
 *
 * Rendering principles, all of them observable in the tests:
 *  - nothing is hidden because it looks empty: `0`, `false` and `"0.00"` are real values and
 *    are displayed as such; only null/undefined/"" count as missing;
 *  - no unit conversion, no rounding, no re-computation - the numbers shown are the numbers
 *    Open Food Facts returned, including their modifier (`<`, `~`, ...) and their source;
 *  - unknown or future fields are never dropped: whatever is not covered by a dedicated
 *    section still appears in the exhaustive `tree()` view;
 *  - every value is escaped with `esc()` before it reaches an HTML string.
 *
 * Every label it prints comes from the shared catalogue in `i18n.js`, so the rendered product
 * follows the interface language (French or English) without any change to the data handling.
 *
 * @license MIT
 */
(function (root) {
  'use strict';
  // Translation catalogue: CommonJS under Node (tests), the global exposed by i18n.js in the browser.
  const I = (typeof module !== 'undefined' && module.exports) ? require('./i18n.js') : root.OFFI18n;
  /** Shorthand for a translated string. */
  const T = (key, params) => I.t(key, params);
  /** A value is present unless it is null, undefined or the empty string. `0` and `false` are values. */
  const hasValue = value => value !== null && value !== undefined && value !== '';
  /** Object.entries() that tolerates null/undefined and non-objects. */
  const entries = value => value && typeof value === 'object' ? Object.entries(value) : [];
  /** HTML-escape anything before interpolating it into a template string. */
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  /** Human-readable rendering of a leaf value; objects fall back to their JSON form. */
  const text = value => typeof value === 'object' ? JSON.stringify(value) : String(value);
  /**
   * Look up a localized field with the same fallback chain the wrapper uses.
   *
   * Order: the requested language, then the server-localized generic field (which OFF fills
   * according to the `lc` passed to `getProduct()`), then the product's own main language.
   *
   * @param {object} p Product data.
   * @param {string} field Base field name, e.g. `product_name`.
   * @param {string} lc Requested language code.
   * @returns {*|null} The value, or null when no translation exists.
   */
  const localized = (p, field, lc) => {
    if (hasValue(p[`${field}_${lc}`])) return p[`${field}_${lc}`];
    // The server-localized field is the wrapper's fallback if a translation is absent.
    if (hasValue(p[field])) return p[field];
    if (hasValue(p[`${field}_${p.lang}`])) return p[`${field}_${p.lang}`];
    return null;
  };
  /**
   * All language codes a product actually declares, deduplicated and sorted.
   *
   * Three sources are merged: `languages_codes`, the `<field>_<lc>` suffixes that carry a
   * non-empty value, and the languages of the selected images. Technical suffixes such as
   * `product_name_debug` are filtered out by the language-code pattern, and a suffix whose
   * translation is empty does not count as a declared language.
   *
   * @param {object} p Product data.
   * @returns {string[]} Sorted lowercase language codes.
   */
  function languages(p) {
    const found = new Set();
    const add = code => { if (/^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(code || '')) found.add(code.toLowerCase()); };
    entries(p.languages_codes).forEach(([lc]) => add(lc));
    entries(p).forEach(([key,value]) => {
      const match = /^(?:product_name|generic_name|ingredients_text|packaging_text)_([a-z]{2,3}(?:-[a-z]{2})?)$/i.exec(key);
      if (match && hasValue(value)) add(match[1]);
    });
    entries(p.images?.selected).forEach(([,images]) => entries(images).forEach(([lc]) => add(lc)));
    add(p.lang); return [...found].sort();
  }
  /**
   * Accept an image URL only when it is HTTPS on an official Open Food Facts image host.
   * Anything else (including a relative or malformed URL) yields an empty string.
   *
   * This mirrors the `img-src` directive of the Content-Security-Policy set in bootstrap.php,
   * so a tampered payload cannot make the page load a third-party image.
   */
  function safeImage(candidate) {
    try { const u = new URL(candidate); return u.protocol === 'https:' && ['images.openfoodfacts.org','images.openfoodfacts.net'].includes(u.hostname) ? u.href : ''; } catch { return ''; }
  }
  /**
   * Best available photo for one image field in one language.
   *
   * Prefers the language-specific selected image, then the generic `image_<field>_url`.
   *
   * @param {object} p Product data.
   * @param {string} lc Language code.
   * @param {'front'|'ingredients'|'nutrition'|'packaging'} [field] Image field.
   * @returns {string} A safe URL, or '' when no usable photo exists.
   */
  function photo(p, lc, field = 'front') {
    return safeImage(p.selected_images?.[field]?.display?.[lc]) || safeImage(p[`image_${field}_url`]);
  }
  /**
   * Flatten one nutrition set into display rows.
   *
   * A nutrient may carry several coexisting values: `value` (as recorded), `value_string` (the
   * text the contributor typed) and `value_computed` (derived by OFF). All of them are kept and
   * labelled, because telling them apart is exactly what this demo is meant to show.
   *
   * @param {object} set A `nutrition.aggregated_set` or one `nutrition.input_sets` entry.
   * @returns {Array<{id:string,label:string,values:Array<{key:string,value:*}>,unit:string,modifier:string,per:string,preparation:string,source:string,sourcePer:string,sourceIndex:?number}>}
   */
  function nutrientRows(set) {
    return entries(set?.nutrients).map(([id,nutrient]) => {
      const n = nutrient && typeof nutrient === 'object' ? nutrient : {value:nutrient};
      // Zero, false and strings such as "0.00" are values, not missing data.
      const values = ['value','value_computed','value_string'].filter(key => hasValue(n[key])).map(key => ({key, value:n[key]}));
      return {id,label:I.nutrient(id),values,unit:n.unit ?? '',modifier:n.modifier ?? '',per:set.per ?? n.source_per ?? '',
        preparation:set.preparation ?? '',source:n.source ?? set.source ?? '',sourcePer:n.source_per ?? '',sourceIndex:n.source_index ?? null};
    });
  }
  /**
   * Render one nutrition set as a table.
   *
   * @param {object} set The set to render.
   * @param {?function(string, *):string} [edit] Callback returning the HTML of the per-row edit
   *   button; when null the extra column is omitted entirely (read-only rendering).
   * @param {?string} [sourceKey] Key of the set inside `input_sets`, forwarded to `edit`.
   * @returns {string} HTML, or '' when the set holds no nutrient.
   */
  function nutrientTable(set, edit = null, sourceKey = null) {
    const rows = nutrientRows(set);
    if (!rows.length) return '';
    return `<div class="table-scroll" tabindex="0" role="region" aria-label="${esc(T('data.nutrition_aria'))}"><table class="data-table nutrition-table"><thead><tr><th scope="col">${esc(T('data.nutrient'))}</th><th scope="col">${esc(T('data.value'))}</th><th scope="col">${esc(T('data.basis'))}</th><th scope="col">${esc(T('data.source'))}</th>${edit ? `<th scope="col" class="edit-column"><span class="sr-only">${esc(T('data.edit'))}</span></th>` : ''}</tr></thead><tbody>${rows.map(r => `<tr data-nutrient="${esc(r.id)}"><th scope="row">${esc(r.label)}${r.label !== r.id ? `<code>${esc(r.id)}</code>` : ''}</th><td>${r.values.length ? r.values.map(v => `<div>${v.key === 'value' || v.key === 'value_string' ? esc(r.modifier) : ''}${esc(v.value)}${r.unit !== '' ? ' '+esc(r.unit) : ''}${v.key !== 'value' ? `<small>${esc(T(v.key === 'value_computed' ? 'data.computed' : 'data.entered'))}</small>` : ''}</div>`).join('') : '—'}</td><td>${esc(r.per || '—')}${r.preparation ? `<small>${esc(r.preparation)}</small>` : ''}</td><td>${esc(r.source || '—')}${r.sourceIndex !== null ? `<small>input_sets[${esc(r.sourceIndex)}]</small>` : ''}${r.sourcePer ? `<small>${esc(r.sourcePer)}</small>` : ''}</td>${edit ? `<td class="edit-column">${edit(r.id,sourceKey ?? r.sourceIndex)}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;
  }
  /**
   * Full nutrition section: the aggregated set first, then every raw input set, then the
   * legacy `nutriments` object when present.
   *
   * @param {object} p Product data.
   * @param {?function(string, *):string} [edit] Row-edit renderer, see nutrientTable().
   * @returns {string} HTML, or '' when the product carries no nutrition data at all.
   */
  function nutrition(p, edit = null) {
    let html = nutrientTable(p.nutrition?.aggregated_set,edit);
    const sets = entries(p.nutrition?.input_sets);
    if (sets.length) html += `<details class="data-section"><summary>${esc(T('data.input_sets'))} <span>${sets.length}</span></summary>${sets.map(([key,set]) => `<h5>input_sets[${esc(key)}] · ${esc(set.source ?? '')}${hasValue(set.source_description) ? ' · '+esc(set.source_description) : ''}</h5>${nutrientTable(set,edit,key)}`).join('')}</details>`;
    if (entries(p.nutriments).length) html += `<details class="data-section" ${!html ? 'open' : ''}><summary>nutriments <span>${esc(T('data.legacy'))}</span></summary>${tree(p.nutriments)}</details>`;
    // Unknown future nutrition fields remain visible in the exhaustive field tree.
    return html ? `<section class="data-section"><h3>${esc(T('data.nutrition'))}</h3>${html}</section>` : '';
  }
  /**
   * Exhaustive, recursive rendering of any JSON value as nested <details> lists.
   *
   * This is the escape hatch that guarantees no field is ever invisible, including fields the
   * demo knows nothing about. Empty strings are shown as `""` and nulls as `null` so that the
   * difference with a missing key stays readable.
   */
  function tree(value) {
    if (value === null) return '<code class="null-value">null</code>';
    if (typeof value !== 'object') return `<span class="field-value">${esc(value === '' ? '""' : text(value))}</span>`;
    const items = entries(value);
    if (!items.length) return `<code>${Array.isArray(value) ? '[]' : '{}'}</code>`;
    return `<dl class="field-tree">${items.map(([key,item]) => `<div class="field-node"><dt>${esc(key)}</dt><dd>${item !== null && typeof item === 'object' && entries(item).length ? `<details><summary>${Array.isArray(item) ? 'Array' : 'Object'} <span>(${entries(item).length})</span></summary>${tree(item)}</details>` : tree(item)}</dd></div>`).join('')}</dl>`;
  }
  /**
   * Render a taxonomy field as a list of tag chips. Accepts an array, an object keyed by index
   * (which is how OFF sometimes serialises these lists) or a single scalar.
   */
  function tags(value) {
    if (!hasValue(value)) return '';
    const values = typeof value === 'object' ? Object.values(value) : [value];
    return values.filter(hasValue).map(v => `<span class="data-tag">${esc(typeof v === 'object' ? text(v) : v)}</span>`).join('');
  }
  /**
   * The "Informations" section: a curated, ordered list of the fields a contributor reads or
   * edits most often. Rows whose value is missing or empty are skipped.
   *
   * Order matters: each human-readable field is immediately followed by its `*_tags`
   * counterpart, so the raw taxonomy values stay visible next to the localized text.
   *
   * @param {object} p Product data.
   * @param {string} lc Language code used for localized lookups.
   * @param {function(string, string):string} [edit] Renderer for the per-row edit button.
   * @returns {string} HTML, or '' when no row has a value.
   */
  function attributes(p, lc, edit = () => '') {
    // Display order of the curated rows; each label is resolved through the `attr.*` catalogue.
    const fields = [
      'generic_name', 'brands', 'quantity', 'serving_size',
      'labels', 'labels_tags', 'categories', 'categories_tags',
      'ingredients_text', 'allergens', 'allergens_tags',
      'traces', 'traces_tags', 'additives_tags', 'ingredients_analysis_tags',
      'origins', 'origins_tags', 'manufacturing_places', 'manufacturing_places_tags',
      'countries', 'countries_tags', 'stores', 'stores_tags',
      'packaging', 'packaging_text', 'packaging_tags',
      'conservation_conditions', 'preparation', 'expiration_date', 'link'
    ];
    const rows = fields.map(key => ({key,label:T('attr.'+key),value:localized(p,key,lc)})).filter(r => hasValue(r.value) && (typeof r.value !== 'object' || entries(r.value).length));
    return rows.length ? `<section class="data-section"><h3>${esc(T('data.information'))}</h3><dl class="attributes">${rows.map(r => `<div data-attribute="${esc(r.key)}"><dt>${esc(r.label)}</dt><dd><span class="attribute-value" dir="auto">${r.key.endsWith('_tags') ? tags(r.value) : esc(text(r.value))}</span>${edit(r.key,r.label)}</dd></div>`).join('')}</dl></section>` : '';
  }
  // Dual export: CommonJS for the Node test suite, `window.OFFProduct` for the browser.
  const api = {hasValue,localized,languages,safeImage,photo,nutrientRows,nutrientTable,nutrition,tree,attributes};
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.OFFProduct = api;
})(typeof window !== 'undefined' ? window : globalThis);
