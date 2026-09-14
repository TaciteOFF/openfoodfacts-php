/**
 * product-editor.js - draft state and patch building for staging writes.
 *
 * Like product-data.js this module is pure: no DOM, no network. It owns the model behind the
 * edit dialog and is exercised directly by `tests/product-editor.test.cjs`.
 *
 * Model
 * -----
 * A *draft* is `{product, changes, nutrition}`:
 *  - `product` is a deep clone of the product as last read, used as the reference for diffing;
 *  - `changes` maps a writable field name to its new value;
 *  - `nutrition` maps a set identity (source/preparation/base) to the nutrients edited in it.
 *
 * The central rule is *diff against the original*: `setField()` and `setNutrient()` remove an
 * entry as soon as the user types the original value back, so `patch()` only ever contains
 * fields that genuinely changed. A patch that would be empty disables the save button.
 *
 * What is writable is intentionally a subset of what is displayed, and it mirrors the
 * server-side allow-list in `app/product-patch.php` - the server remains the authority, this
 * module only avoids offering an edit that would be rejected:
 *  - localized texts, language-independent texts, and taxonomy lists;
 *  - nutrients coming from a `packaging` or `manufacturer` input set;
 *  - never `nova-group`, never computed values, never aggregated sets.
 *
 * @license MIT
 */
(function(root){
'use strict';
// Translation catalogue: CommonJS under Node (tests), the global exposed by i18n.js in the browser.
const I = (typeof module !== 'undefined' && module.exports) ? require('./i18n.js') : root.OFFI18n;
/** Free-text fields that exist per language (`product_name_fr`, ...). */
const texts = ['product_name','generic_name','ingredients_text','packaging_text','conservation_conditions','preparation'];
/** Taxonomy list fields, edited as one tag per line. */
const tags = ['brands_tags','labels_tags','categories_tags','countries_tags','origins_tags','stores_tags','allergens_tags','traces_tags','manufacturing_places_tags','purchase_places_tags','packaging_tags'];
/** Text fields shared by every language. */
const globals = ['quantity','serving_size','link'];
/** Structural deep copy; product payloads are plain JSON so this is sufficient. */
const clone = value => JSON.parse(JSON.stringify(value));
/**
 * Map a displayed row key to the field that can actually be written, or null when the row is
 * read-only (additives, ingredients analysis, expiration date, computed scores...).
 *
 * The `key+'_tags'` branch lets a human-readable row such as `labels` open the editor on the
 * taxonomy field `labels_tags`, which is what Open Food Facts expects on write.
 *
 * @param {string} key Row key as rendered by product-data.js.
 * @returns {?string} Writable field name, or null.
 */
function fieldForRow(key) {
 if(texts.includes(key) || globals.includes(key) || tags.includes(key)) return key;
 if(tags.includes(key+'_tags')) return key+'_tags';
 return null;
}
/**
 * Resolve the input set a nutrient may be edited in, or null when it must stay read-only.
 *
 * A nutrient is editable only when it belongs to a `packaging` or `manufacturer` set and
 * already holds a recorded or typed value. `nova-group` is excluded explicitly, and a value
 * that only exists as `value_computed` is not editable because OFF derives it.
 *
 * @param {object} p Product data.
 * @param {string} id Nutrient id.
 * @param {string|number} sourceKey Key of the set inside `nutrition.input_sets`.
 * @returns {?object} The input set, or null.
 */
function nutrientSource(p,id,sourceKey) {
 const set=p.nutrition?.input_sets?.[sourceKey], n=set?.nutrients?.[id];
 if(id==='nova-group' || !['packaging','manufacturer'].includes(set?.source) || !n || (n.value_string==null && n.value==null)) return null;
 return set;
}
/**
 * Original value of a writable field, in the exact shape the editor manipulates.
 *
 * Three cases:
 *  - a localized text (`<field>_<lc>`): the translation, falling back to the generic field only
 *    when the requested language *is* the product's main language, so editing `product_name_en`
 *    on a French product starts empty instead of pre-filling the French text;
 *  - a taxonomy list: the packaging-sourced tags when OFF exposes them in `tags_sources`,
 *    otherwise the plain `*_tags` list, always normalised to an array;
 *  - anything else: the raw value, or '' when absent.
 *
 * @returns {string|string[]} Comparable original value.
 */
function original(p,key) {
 const localized = /^(.*)_([a-z]{2,3}(?:-[a-z]{2})?)$/.exec(key);
 if(localized && texts.includes(localized[1])) return p[key] ?? (p.lang === localized[2] ? p[localized[1]] ?? '' : '');
 if(tags.includes(key)) {
  const name=key.slice(0,-5), source=p.tags_sources?.[name]?.packaging?.tags;
  return Object.values(source ?? p[key] ?? {});
 }
 return p[key] ?? '';
}
/**
 * Deep copies of every nutrition input set that may be edited (packaging / manufacturer).
 * @returns {object[]}
 */
function sourceSets(p) {
 return Object.values(p.nutrition?.input_sets ?? {}).filter(s=>['packaging','manufacturer'].includes(s.source)).map(s=>clone(s));
}
/**
 * Start a fresh draft from a freshly read product.
 * @param {object} p Product data as returned by `getProduct()`.
 * @returns {{product:object,changes:Object<string,*>,nutrition:Object<string,object>}}
 */
function createDraft(p) { return {product:clone(p),changes:{},nutrition:{}}; }
/**
 * Record (or un-record) a text/tag change.
 *
 * Typing the original value back removes the entry entirely, which is what keeps the generated
 * patch minimal: only the field the user actually modified is ever sent.
 */
function setField(draft,key,value) {
 if(JSON.stringify(value) === JSON.stringify(original(draft.product,key))) delete draft.changes[key];
 else draft.changes[key]=value;
}
/**
 * Record (or un-record) a nutrient change inside one input set.
 *
 * Sets are identified by the triple (source, preparation, base) rather than by their index, so
 * a draft stays attached to the right set even if OFF reorders `input_sets` between two reads.
 * Comparison is done on the textual value, which is how "0" vs "0.00" stays meaningful, and an
 * edit reverted to its original value deletes the nutrient - and the set, once it is empty.
 *
 * @param {object} draft Draft to mutate.
 * @param {object} set The input set being edited.
 * @param {string} id Nutrient id.
 * @param {{value:string|number,unit:string,modifier?:string}} n New values.
 */
function setNutrient(draft,set,id,n) {
 const identity=JSON.stringify([set.source,set.preparation,set.per]);
 const source=set.nutrients?.[id] ?? {};
 const before={value:String(source.value_string ?? source.value ?? ''),unit:source.unit ?? '',modifier:source.modifier ?? ''};
 const after={value:String(n.value),unit:n.unit,modifier:n.modifier ?? ''};
 if(JSON.stringify(before)===JSON.stringify(after)) {
  if(draft.nutrition[identity]) {delete draft.nutrition[identity].nutrients[id]; if(!Object.keys(draft.nutrition[identity].nutrients).length) delete draft.nutrition[identity];}
 } else {
  draft.nutrition[identity] ??= {source:set.source,preparation:set.preparation,per:set.per,...(set.per_quantity!=null?{per_quantity:set.per_quantity}:{}),...(set.per_unit?{per_unit:set.per_unit}:{}),nutrients:{}};
  draft.nutrition[identity].nutrients[id]=after;
 }
}
/**
 * Build the JSON patch sent to the `update` action.
 *
 * Nutrition is emitted as `nutrition.input_sets` with `value_string`, never as an aggregate:
 * Open Food Facts recomputes aggregated values, estimates and scores from these inputs.
 * The per-set metadata (`per_quantity`, `per_unit`) is only included when the original set
 * carried it.
 *
 * `extra` allows callers to merge additional fields; a collision with a field already edited in
 * the form is an error rather than a silent overwrite, and prototype-polluting keys are refused.
 *
 * @param {object} draft Current draft.
 * @param {Object<string,*>} [extra] Extra fields to merge.
 * @returns {Object<string,*>} Patch body, ready for `validateProductPatch()` on the server.
 * @throws {Error} On a duplicated or forbidden key.
 */
function patch(draft,extra={}) {
 const result={...draft.changes};
 if(Object.keys(draft.nutrition).length) result.nutrition={input_sets:Object.values(draft.nutrition).map(set=>({...set,nutrients:Object.fromEntries(Object.entries(set.nutrients).map(([id,n])=>[id,{value_string:String(n.value),unit:n.unit,modifier:n.modifier}]))}))};
 for(const [key,value] of Object.entries(extra)) {
  if(Object.hasOwn(result,key)) throw new Error(I.t('editor.duplicate_field', {field:key}));
  if(['__proto__','constructor','prototype'].includes(key)) throw new Error(I.t('editor.forbidden_field'));
  result[key]=value;
 }
 return result;
}
// Dual export: CommonJS for the Node test suite, `window.OFFEditor` for the browser.
const api={texts,tags,globals,fieldForRow,nutrientSource,original,sourceSets,createDraft,setField,setNutrient,patch};
if(typeof module!=='undefined'&&module.exports) module.exports=api; else root.OFFEditor=api;
})(typeof window!=='undefined'?window:globalThis);
