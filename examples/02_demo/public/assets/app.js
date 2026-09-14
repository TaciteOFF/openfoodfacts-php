/**
 * app.js - UI controller of the demo.
 *
 * Loaded after product-data.js (`window.OFFProduct`, rendering) and product-editor.js
 * (`window.OFFEditor`, draft/patch model), which it wires to the DOM. This file owns:
 *  - the single `request()` helper, the only place that performs network I/O;
 *  - the tab / environment / account / upload interactions;
 *  - product read and rendering, search with pagination;
 *  - the staging edit dialog, from opening a row to reloading the product after a write.
 *
 * Rules worth knowing before changing anything here:
 *  - the browser only ever talks to `api.php` on the same origin; there is no direct call to
 *    Open Food Facts anywhere in the front end, and the CSP would block one;
 *  - the server is the authority on what is allowed. Every guard in this file (staging-only
 *    writes, credentials required, allow-listed fields) exists to keep the UI honest, and is
 *    re-checked server-side;
 *  - only one request runs at a time (`busy`): controls are disabled for its whole duration and
 *    restored to their previous state afterwards, never blindly re-enabled;
 *  - a failed write is never retried automatically, because a write may have partially applied.
 *
 * Every visible string comes from the catalogue in `assets/i18n.js`, through the `T()` helper.
 * The interface language is chosen in the header, stored server-side, and applied by reloading
 * the page so PHP re-renders the static markup in the same locale.
 *
 * @license MIT
 */
'use strict';
const $ = s => document.querySelector(s);
/** querySelectorAll as a real array. */
const $$ = s => [...document.querySelectorAll(s)];
/**
 * Mirror of the server session. Replaced wholesale by the `state` block of every API response,
 * so the UI can never drift from what the server actually enforces. The CSRF token is seeded
 * from the <meta> tag rendered by index.php.
 */
let state = {environment: 'prod', username: null, credentialsVerified: false, csrf: $('meta[name="csrf-token"]').content};
// Rendering helpers (product-data.js) and draft/patch model (product-editor.js).
const D = window.OFFProduct;
const E = window.OFFEditor;
const I = window.OFFI18n;
/** Shorthand for a translated string; the locale comes from <html lang>. */
const T = (key, params) => I.t(key, params);
// Editor state: the product currently displayed, its draft, the scope the draft belongs to
// (environment/barcode/field/source/nutrient), the field being edited, and the saving flag.
let loadedProduct = null, editorDraft = null, editorScope = '', editorField = null, editorSaving = false;
// Read state: last successfully loaded barcode, client-side read throttle (mirrors the 1 s
// server limit), and the timer used to defer a language switch that arrives too early.
let loadedBarcode = '', nextReadAt = 0, languageRefreshTimer;
// Global UI state: one request at a time, last raw response (for the JSON panel and copy
// button), search pagination, and the object URL of the local upload preview.
let busy = false, rawResponse = null, searchPage = 1, lastQuery = '', previewUrl = null;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon = name => `<svg aria-hidden="true"><use href="#${name}"/></svg>`;
const empty = (title, description = '') => `<div class="empty-state">${esc(description || title)}</div>`;
/**
 * Safe front image for a product summary or a search result.
 * Same host allow-list as `OFFProduct.safeImage()`; returns '' when nothing usable is found.
 */
function imageUrl(product) {
  const candidate = product.image_front_url || product.image_url || product.image_front_small_url;
  try { const u = new URL(candidate); return u.protocol === 'https:' && ['images.openfoodfacts.org', 'images.openfoodfacts.net'].includes(u.hostname) ? u.href : ''; } catch { return ''; }
}
/**
 * Re-render everything that depends on session state: environment toggle, environment note,
 * account label and dialog, edit availability, and the upload gate.
 *
 * Called after every response and after every local change, so the read-only/production and
 * staging modes can never be displayed inconsistently. The upload gate is rebuilt with DOM
 * nodes rather than innerHTML because it carries an event listener.
 *
 * @param {?object} [next] New state coming from the server; omit to re-render the current one.
 */
function applyState(next) {
  if (next) state = next;
  $$('[data-env]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.env === state.environment)));
  const stage = state.environment === 'staging';
  $('#environment-note').textContent = stage ? T('state.env_staging') : T('state.env_prod');
  $('#account-label').textContent = state.username || T('ui.account');
  $('#account-environment').textContent = T('state.environment', {environment: T(stage ? 'env.staging' : 'env.prod')});
  $('#account-form').hidden = Boolean(state.username);
  $('#account-saved').hidden = !state.username;
  $('#account-state').textContent = state.username ? T(state.credentialsVerified ? 'state.verified' : 'state.unverified', {username: state.username}) : '';
  updateEditAvailability();
  $('#upload-fields').disabled = !(stage && state.username);
  $('#upload-gate').hidden = Boolean(stage && state.username);
  $('#upload-gate').replaceChildren();
  if (!stage || !state.username) {
    const info = document.createElement('div');
    info.textContent = T(!stage ? 'gate.staging_only' : 'gate.credentials');
    const button = document.createElement('button'); button.className = 'button'; button.type = 'button';
    button.textContent = T(!stage ? 'gate.switch_staging' : 'gate.account');
    button.addEventListener('click', () => !stage ? changeEnvironment('staging') : openAccount());
    $('#upload-gate').append(info, button);
  }
}
/** Single non-blocking status line under the workspace (aria-live). Empty string clears it. */
function flash(message = '') { $('#feedback').textContent = message; }
/**
 * Perform the one and only kind of network call this app makes: a POST to `api.php`.
 *
 * Behaviour:
 *  - refuses to start while another request is in flight;
 *  - disables every control for the duration and restores each one to its *previous* disabled
 *    state afterwards, so a control that was already disabled stays disabled;
 *  - attaches the CSRF token, and lets FormData set its own multipart Content-Type;
 *  - aborts after 35 s, slightly above the server-side 25 s wrapper timeout;
 *  - refreshes the local `state` from the response;
 *  - when `inspect` is true, feeds the "Réponse" panel: raw JSON (truncated past 80 000 chars
 *    for rendering only - the copy button always copies the full payload), status and duration.
 *
 * @param {object|FormData} payload Request body.
 * @param {boolean} [inspect] Whether this call should be shown in the JSON panel.
 * @returns {Promise<object>} The parsed `{ok:true, ...}` envelope.
 * @throws {Error} With `.response` set when the server returned a structured error.
 */
async function request(payload, inspect = false) {
  if (busy) throw new Error(T('request.busy'));
  busy = true;
  const buttons = $$('button, input, select, textarea').map(b => [b, b.disabled]);
  buttons.forEach(([b]) => b.disabled = true);
  const started = performance.now();
  if (inspect) {
    $('#response-status').textContent = T('request.running');
    $('#response-time').textContent = T('request.in_progress');
    $('#response-json').textContent = T('request.waiting');
    rawResponse = null;
  }
  try {
    const form = payload instanceof FormData;
    const res = await fetch('api.php', {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':state.csrf, ...(form ? {} : {'Content-Type':'application/json'})}, body:form ? payload : JSON.stringify(payload), signal:AbortSignal.timeout(35000)});
    let result;
    try { result = await res.json(); } catch { throw new Error(T('request.no_json')); }
    if (result.state) state = result.state;
    if (inspect) {
      rawResponse = result.data ?? {message:result.message, ...(result.details ? {details:result.details} : {})};
      const json = JSON.stringify(rawResponse, null, 2);
      $('#response-json').textContent = json.length > 80000 ? json.slice(0,80000) + T('request.truncated') : json;
      $('#response-status').textContent = T(result.ok ? 'request.ok' : 'request.error_status', {status: res.status});
      $('#response-time').textContent = T('request.duration', {duration: result.meta?.duration ?? Math.round(performance.now()-started), environment: T(state.environment === 'prod' ? 'env.prod' : 'env.staging')});
    }
    if (!res.ok || !result.ok) throw Object.assign(new Error(result.message || T('request.failed')), {response:result});
    return result;
  } catch (error) {
    if (error.name === 'TimeoutError') error = new Error(T('request.timeout'));
    if (inspect && rawResponse === null) {
      rawResponse = {message:error.message};
      $('#response-json').textContent = JSON.stringify(rawResponse,null,2);
      $('#response-status').textContent = T('request.error');
      $('#response-time').textContent = `${Math.round(performance.now()-started)} ms`;
    }
    throw error;
  } finally {
    busy = false;
    buttons.forEach(([b, disabled]) => b.disabled = disabled);
    $('#copy-json').disabled = rawResponse === null;
    applyState();
  }
}
/**
 * Activate one of the three tool tabs (product / search / upload) and update the ARIA state.
 * The method name in the response panel follows the tab, unless a request is running or a
 * response is already displayed.
 */
function selectTab(name, focus = false) {
  $$('[data-tab]').forEach(b => { const selected = b.dataset.tab === name; b.setAttribute('aria-selected',String(selected)); b.tabIndex = selected ? 0 : -1; if (selected && focus) b.focus(); });
  $$('[role="tabpanel"]').forEach(p => p.hidden = p.id !== `panel-${name}`);
  if (!busy && rawResponse === null) $('#request-method').textContent = {product:'getProduct()', search:'search()', upload:'uploadImage()'}[name];
}
// Tabs: click plus the standard arrow/Home/End keyboard pattern for an ARIA tablist.
$$('[data-tab]').forEach(b => {
  b.addEventListener('click',() => selectTab(b.dataset.tab));
  b.addEventListener('keydown',e => {
    const tabs = $$('[data-tab]'); let i = tabs.indexOf(b);
    if (e.key === 'ArrowRight') i = (i+1)%tabs.length;
    else if (e.key === 'ArrowLeft') i = (i+tabs.length-1)%tabs.length;
    else if (e.key === 'Home') i = 0;
    else if (e.key === 'End') i = tabs.length-1;
    else return;
    e.preventDefault(); selectTab(tabs[i].dataset.tab,true);
  });
});
/**
 * Switch between production and staging.
 *
 * The server drops the stored credentials and rotates the session id; the UI mirrors that by
 * clearing every result, the loaded product, any pending draft, the upload form and the JSON
 * panel. Nothing from the previous environment is allowed to survive the switch.
 */
async function changeEnvironment(env) {
  if (env === state.environment || busy) return;
  try {
    clearTimeout(languageRefreshTimer);
    loadedBarcode = '';
    loadedProduct = null; editorDraft = null; editorScope = '';
    await request({action:'environment',environment:env});
    $('#product-result').innerHTML = empty(T('ui.no_result'));
    $('#search-result').replaceChildren(); $('#pagination').hidden = true;
    $('#upload-form').reset(); $('#upload-result').textContent = ''; clearPhoto();
    $('#password').value = ''; $('#account-message').textContent = ''; rawResponse = null;
    $('#response-json').textContent = T('ui.no_request_comment');
    $('#response-status').textContent = T('ui.pending'); $('#response-time').textContent = T('ui.no_request'); $('#copy-json').disabled = true;
    flash(T('state.env_changed'));
  } catch (e) { flash(e.message); }
}
$$('[data-env]').forEach(b => b.addEventListener('click', () => changeEnvironment(b.dataset.env)));
/**
 * Change the interface language.
 *
 * The choice is stored in the PHP session, then the page is reloaded: the server re-renders the
 * static markup and the API messages in the new locale, and `i18n.js` picks it up from
 * <html lang>. Reloading also guarantees no half-translated screen, at the cost of re-reading
 * the displayed product - an acceptable trade for a demo, and an explicit one.
 *
 * @param {string} language One of the locales supported by i18n.js.
 */
async function changeInterfaceLanguage(language) {
  if (busy || language === I.getLocale()) return;
  try { await request({action:'interface', language}); location.reload(); }
  catch (e) { flash(e.message); }
}
$$('[data-ui-lang]').forEach(b => b.addEventListener('click', () => changeInterfaceLanguage(b.dataset.uiLang)));
/**
 * Rebuild the language selector from the languages the product actually declares.
 * The currently requested language is always present, marked "(repli)" when the product does
 * not declare it, so the user can see that the displayed text is a fallback.
 */
function syncLanguages(p, lc) {
  const available = D.languages(p);
  const choices = [...new Set([lc, ...available])].sort();
  const names = new Intl.DisplayNames([I.getLocale()], {type:'language'});
  $('#product-language').innerHTML = choices.map(code => `<option value="${esc(code)}">${esc(code.toUpperCase())} · ${esc(names.of(code))}${available.includes(code) ? '' : esc(T('product.fallback_language'))}</option>`).join('');
  $('#product-language').value = lc;
}
/**
 * Edit button for an information row, rendered only in staging and only for writable fields.
 * @returns {string} HTML for the button, or '' when the row is read-only.
 */
function rowEdit(key,label) {
  const field=E.fieldForRow(key);
  return field && state.environment==='staging' ? `<button class="row-edit" type="button" data-edit-field="${esc(field)}" data-edit-title="${esc(label)}" aria-label="${esc(T('editor.edit_aria', {label}))}">${esc(T('editor.edit'))}</button>` : '';
}
/**
 * Edit button for a nutrient row, rendered only in staging and only when the nutrient belongs
 * to an editable input set (packaging / manufacturer).
 * @returns {string} HTML for the button, or '' when the nutrient is read-only.
 */
function nutrientEdit(id,sourceKey) {
  return E.nutrientSource(loadedProduct,id,sourceKey) && state.environment==='staging' ? `<button class="row-edit" type="button" data-edit-source="${esc(sourceKey)}" data-edit-id="${esc(id)}" aria-label="${esc(T('editor.edit_aria', {label:id}))}">${esc(T('editor.edit'))}</button>` : '';
}
/**
 * Render a complete product: summary (photo, name, scores, link to the OFF page), curated
 * information rows, nutrition, photo gallery, and the exhaustive field tree.
 *
 * Edit buttons are injected through the `rowEdit` / `nutrientEdit` callbacks, so the read-only
 * rendering in product-data.js stays identical in production. Images that fail to load - which
 * happens with staging photos - are replaced by a text placeholder rather than a broken icon.
 *
 * @param {object} p Product data from `getProduct()`.
 * @param {string} lc Language the product was requested in.
 */
function renderProduct(p, lc) {
  loadedProduct = p;
  const image = D.photo(p, lc) || imageUrl(p);
  const name = D.localized(p, 'product_name', lc) ?? T('product.unnamed');
  const code = String(p.code ?? '');
  const url = `https://${state.environment === 'staging' ? 'world.openfoodfacts.net' : 'fr.openfoodfacts.org'}/product/${encodeURIComponent(code)}`;
  const scores = [['nutriscore_grade','Nutri-Score'],['nova_group','NOVA'],['environmental_score_grade','Green-Score']]
    .filter(([key]) => D.hasValue(p[key])).map(([key,label]) => `<div class="score">${label}<strong>${esc(String(p[key]).toUpperCase())}</strong></div>`).join('');
  const gallery = ['ingredients','nutrition','packaging'].map(field => ({field,url:D.photo(p,lc,field)})).filter(item => item.url);
  syncLanguages(p, lc);
  $('#product-result').innerHTML = `<div class="product-summary"><div class="product-image">${image ? `<img src="${esc(image)}" alt="${esc(name)}">` : `<span class="image-missing">${esc(T('product.no_photo'))}</span>`}</div><div><span class="product-brand">${esc(p.brands ?? '')}</span><div class="product-name-line"><h4 dir="auto">${esc(name)}</h4>${rowEdit('product_name',T('attr.product_name'))}</div><p class="product-meta">${D.hasValue(p.quantity) ? esc(p.quantity)+' · ' : ''}${esc(code)}</p><div class="scores">${scores}</div><a class="product-link" href="${esc(url)}" target="_blank" rel="noreferrer">${esc(T('product.off_page'))}</a></div></div>
    ${D.attributes(p,lc,rowEdit)}${D.nutrition(p,state.environment==='staging' ? nutrientEdit : null)}
    ${gallery.length ? `<details class="data-section"><summary>${esc(T('product.photos'))} <span>${gallery.length}</span></summary><div class="product-gallery">${gallery.map(item => `<figure><a href="${esc(item.url)}" target="_blank" rel="noreferrer"><img src="${esc(item.url)}" alt="${esc(item.field)}" loading="lazy"></a><figcaption>${esc(item.field)}</figcaption></figure>`).join('')}</div></details>` : ''}
    <details class="data-section all-fields"><summary>${esc(T('product.all_fields'))} <span>${Object.keys(p).length}</span></summary>${D.tree(p)}</details>`;
  $$('#product-result [data-edit-field]').forEach(b=>b.addEventListener('click',()=>openEditor({key:b.dataset.editField,title:b.dataset.editTitle})));
  $$('#product-result [data-edit-source]').forEach(b=>b.addEventListener('click',()=>openEditor({key:'nutrition',title:b.dataset.editId,nutrient:b.dataset.editId,sourceKey:b.dataset.editSource})));
  updateEditAvailability();
  $$('#product-result img').forEach(img => img.addEventListener('error', () => {
    const missing = document.createElement('span'); missing.className = 'image-missing'; missing.textContent = T('product.photo_unavailable'); img.replaceWith(missing);
  }, {once:true}));
}

/**
 * Read a product through the wrapper's `getProduct()` and render it.
 *
 * A client-side 1.1 s throttle mirrors the server limit: a read asked for too early is deferred
 * rather than rejected, which is what makes rapid language switching feel continuous.
 */
async function getProduct() {
  if (busy || !$('#product-form').reportValidity()) return;
  clearTimeout(languageRefreshTimer);
  if (Date.now() < nextReadAt) { languageRefreshTimer = setTimeout(getProduct, nextReadAt - Date.now()); return; }
  nextReadAt = Date.now() + 1100;
  const requestedCode = $('#barcode-input').value;
  const requestedLanguage = $('#product-language').value;
  flash(); $('#request-method').textContent = 'getProduct()';
  $('#product-result').innerHTML = `<div class="empty-state"><span class="spinner"></span><p>${esc(T('product.loading'))}</p></div>`;
  $('#product-result').setAttribute('aria-busy','true');
  try {
    const result = await request({action:'product', barcode:requestedCode, language:requestedLanguage}, true);
    renderProduct(result.data, requestedLanguage);
    loadedBarcode = requestedCode;
    $('#upload-barcode').value = requestedCode;
  } catch(e) { $('#product-result').innerHTML = empty(T('product.error'), e.message); flash(e.message); }
  finally { $('#product-result').setAttribute('aria-busy','false'); }
}
$('#product-language').addEventListener('change', () => {
  if (loadedBarcode) $('#barcode-input').value = loadedBarcode;
  getProduct();
});
$('#product-form').addEventListener('submit', e => {e.preventDefault();getProduct();});
$$('[data-example]').forEach(b => b.addEventListener('click',() => {$('#barcode-input').value = b.dataset.example; getProduct();}));
/**
 * Run a paginated search through the wrapper's `search()` (12 results per page) and render the
 * result grid. Clicking a result fills the barcode field and loads the full product.
 */
async function search(page) {
  if (busy) return;
  flash(); $('#request-method').textContent = 'search()'; $('#search-result').innerHTML = `<div class="empty-state"><span class="spinner"></span><p>${esc(T('search.loading'))}</p></div>`; $('#pagination').hidden = true;
  try {
    const result = await request({action:'search',query:lastQuery,page},true);
    searchPage = page;
    const data = result.data;
    if (!data.products.length) { $('#search-result').innerHTML = empty(T('ui.no_result')); return; }
    $('#search-result').innerHTML = `<p class="result-count">${esc(T('search.count', {count: Number(data.count).toLocaleString(I.getLocale()), query: lastQuery}))}</p><div class="search-grid">${data.products.map(p => `<button class="search-item" data-code="${esc(p.code)}">${imageUrl(p) ? `<img src="${esc(imageUrl(p))}" alt="" loading="lazy">` : icon('barcode')}<span><strong>${esc(p.product_name || T('product.unnamed'))}</strong><small>${esc(p.brands || p.code)}</small></span></button>`).join('')}</div>`;
    $$('.search-item').forEach(b => b.addEventListener('click',() => {$('#barcode-input').value = b.dataset.code;selectTab('product',true);getProduct();}));
    $$('.search-item img').forEach(img => img.addEventListener('error',() => img.hidden = true,{once:true}));
    $('#pagination').hidden = data.count <= 12;
    $('#prev-page').disabled = page <= 1; $('#next-page').disabled = page * 12 >= data.count || page >= 100;
    $('#page-label').textContent = T('search.page', {page});
  } catch(e) {$('#search-result').innerHTML = empty(T('search.interrupted'),e.message);flash(e.message);}
}
$('#search-form').addEventListener('submit',e => {e.preventDefault();lastQuery = $('#search-input').value.trim();search(1);});
$('#prev-page').addEventListener('click',()=>search(searchPage-1));
$('#next-page').addEventListener('click',()=>search(searchPage+1));
/** Open the account dialog, always on a cleared message and a freshly rendered state. */
function openAccount() { $('#account-message').textContent=''; applyState(); $('#account-dialog').showModal(); }
$('#account-open').addEventListener('click',openAccount);
$('#account-close').addEventListener('click',()=>$('#account-dialog').close());
$('#account-dialog').addEventListener('close',()=>{$('#password').value='';});
$('#account-form').addEventListener('submit',async e => {
  e.preventDefault();
  try {const result = await request({action:'login',username:$('#username').value,password:$('#password').value});$('#password').value='';$('#account-message').textContent=result.message;}
  catch(e){$('#account-message').textContent=e.message;}
});
$('#logout').addEventListener('click',async()=>{
  try{await request({action:'logout'});$('#account-message').textContent=T('state.credentials_cleared');$('#password').value='';$('#username').value='';}
  catch(e){$('#account-message').textContent=e.message;}
});
/** Drop the local upload preview and revoke its object URL. */
function clearPhoto() {
  if(previewUrl) URL.revokeObjectURL(previewUrl); previewUrl=null;
  $('#photo-preview').hidden=true; $('#photo-preview').removeAttribute('src'); $('#file-label').textContent=T('ui.choose_photo');
}
/**
 * Show a local preview of the selected photo.
 * Type and size are checked here for immediate feedback only; `api.php` re-checks the real
 * content type with finfo and getimagesize before anything is sent to Open Food Facts.
 */
function previewPhoto() {
  clearPhoto(); const file=$('#photo-input').files[0]; if(!file)return;
  if(!['image/jpeg','image/png'].includes(file.type)||file.size>5*1024*1024){$('#photo-input').value='';flash(T('upload.bad_file'));return;}
  previewUrl=URL.createObjectURL(file);$('#photo-preview').src=previewUrl;$('#photo-preview').hidden=false;$('#file-label').textContent=file.name;flash();
}
$('#photo-input').addEventListener('change',previewPhoto);
// Drag and drop into the file input, disabled while the upload fieldset is gated.
$('#dropzone').addEventListener('dragover',e=>{e.preventDefault();if(!$('#upload-fields').disabled)$('#dropzone').classList.add('dragging');});
$('#dropzone').addEventListener('dragleave',()=>$('#dropzone').classList.remove('dragging'));
$('#dropzone').addEventListener('drop',e=>{e.preventDefault();$('#dropzone').classList.remove('dragging');if($('#upload-fields').disabled||busy)return;const transfer=new DataTransfer();if(e.dataTransfer.files[0])transfer.items.add(e.dataTransfer.files[0]);$('#photo-input').files=transfer.files;previewPhoto();});
$('#upload-form').addEventListener('submit',async e=>{
  e.preventDefault(); if(busy)return;
  if(state.environment!=='staging'||!state.username){flash(T('upload.account_required'));return;}
  const data=new FormData(e.target);data.set('action','upload');
  $('#request-method').textContent='uploadImage()';$('#upload-result').textContent=T('upload.sending');flash();
  try {const result=await request(data,true);$('#upload-result').textContent=T(result.data.status==='success_with_warnings' ? 'upload.warnings' : 'upload.success');$('#photo-input').value='';clearPhoto();}
  catch(e){$('#upload-result').textContent=e.message;flash(e.message);}
});
// Copy the full response, even when the panel only displays a truncated preview.
$('#copy-json').addEventListener('click',async()=>{
  if(rawResponse===null)return;
  try{await navigator.clipboard.writeText(JSON.stringify(rawResponse,null,2));$('#copy-json').textContent=T('request.copied');setTimeout(()=>$('#copy-json').textContent=T('ui.copy_json'),1800);}catch{flash(T('request.copy_failed'));}
});
// Boot: fetch the session state, render it, then load the default product so the page is never
// empty on arrival. A failure here means the PHP backend is not running.
(async()=>{
  try{const res=await fetch('api.php',{credentials:'same-origin'});const data=await res.json();applyState(data.state);await getProduct();}
  catch{flash(T('request.backend'));}
})();

/**
 * Central gate for everything edit-related: row buttons, the dialog fieldset and the save
 * button. Writes require staging, stored credentials, and no in-flight save.
 */
function updateEditAvailability() {
  const allowed = state.environment === 'staging';
  $$('#product-result .row-edit').forEach(b=>b.disabled=!allowed || busy);
  $('#editor-fields').disabled = !allowed || !state.username || editorSaving;
  if (!allowed || !state.username || editorSaving) $('#editor-save').disabled = true;
}
/** Current draft value for a field: the pending change if any, otherwise the original. */
function editorValue(key) { return Object.hasOwn(editorDraft.changes,key) ? editorDraft.changes[key] : E.original(editorDraft.product,key); }
/**
 * Render one editor control. Tag lists are edited as one entry per line in a textarea; short
 * texts (name, generic name) use an input, longer ones a textarea.
 */
function editorControl(key, title, multiline=false) {
  const value = editorValue(key);
  const content = Array.isArray(value) ? value.join('\n') : value;
  return `<label class="editor-label">${esc(title)}<code>${esc(key)}</code>${multiline ? `<textarea data-edit-key="${esc(key)}" rows="3" dir="auto">${esc(content)}</textarea>` : `<input data-edit-key="${esc(key)}" value="${esc(content)}" dir="auto">`}</label>`;
}
/**
 * Wire the inputs of the text editor to the draft. Tag fields are split on newlines, trimmed,
 * and emptied entries are dropped.
 */
function bindEditorFields(container) {
  $$(container+' [data-edit-key]').forEach(el => el.addEventListener('input', () => {
    const key=el.dataset.editKey;
    const value=E.tags.includes(key) ? el.value.split('\n').map(x=>x.trim()).filter(x=>x!=='') : el.value;
    E.setField(editorDraft,key,value); refreshEditorPatch();
  }));
}
/**
 * Render the editor for the currently selected language.
 * Localized fields are addressed as `<field>_<lc>`, so switching language inside the dialog
 * keeps the drafts of the other languages untouched in `draft.changes`.
 */
function renderEditorLanguage() {
  const lc=$('#editor-language').value;
  const key=E.texts.includes(editorField.key) ? `${editorField.key}_${lc}` : editorField.key;
  $('#editor-text-fields').innerHTML=editorControl(key,editorField.title,E.tags.includes(key) || (E.texts.includes(editorField.key) && !['product_name','generic_name'].includes(editorField.key)));
  bindEditorFields('#editor-text-fields');
}
/**
 * Render the single-nutrient editor: value, unit and modifier for one nutrient of one input
 * set. Only the nutrient the user clicked is editable, which keeps the generated patch minimal
 * and makes it obvious which source is being written.
 */
function renderEditorNutrition() {
  const set=E.nutrientSource(editorDraft.product,editorField.nutrient,editorField.sourceKey);
  if(!set){$('#editor-nutrients').textContent=T('editor.source_missing');return;}
  const identity=JSON.stringify([set.source,set.preparation,set.per]);
  const changes=editorDraft.nutrition[identity]?.nutrients ?? {};
  const ids=[editorField.nutrient];
  $('#editor-nutrients').innerHTML=`<div class="table-scroll"><table class="data-table"><thead><tr><th>${esc(T('editor.nutrient'))}</th><th>${esc(T('editor.value'))}</th><th>${esc(T('editor.unit'))}</th><th>${esc(T('editor.modifier'))}</th></tr></thead><tbody>${ids.map(id=>{
    const n=changes[id] ?? set.nutrients[id] ?? {};
    return `<tr data-edit-nutrient="${esc(id)}"><th scope="row">${esc(id)}</th><td><input aria-label="${esc(T('editor.value_aria', {id}))}" data-part="value" type="number" min="0" step="any" value="${esc(n.value_string ?? n.value ?? '')}"></td><td><input aria-label="${esc(T('editor.unit_aria', {id}))}" data-part="unit" value="${esc(n.unit ?? '')}"></td><td><select aria-label="${esc(T('editor.modifier_aria', {id}))}" data-part="modifier">${['','<','>','~','≤','≥'].map(m=>`<option value="${esc(m)}" ${m===(n.modifier??'')?'selected':''}>${esc(m||'=')}</option>`).join('')}</select></td></tr>`;
  }).join('')}</tbody></table></div>`;
  $$('#editor-nutrients input, #editor-nutrients select').forEach(el=>el.addEventListener('input',()=>{
    const row=el.closest('[data-edit-nutrient]');
    const raw=row.querySelector('[data-part=value]').value;
    E.setNutrient(editorDraft,set,row.dataset.editNutrient,{value:raw,unit:row.querySelector('[data-part=unit]').value,modifier:row.querySelector('[data-part=modifier]').value});
    refreshEditorPatch();
  }));
}
/**
 * Build the whole dialog for `editorField`: language list (product languages, the current one,
 * plus any language already present in the draft), title, contextual help, and either the text
 * control or the nutrient table.
 */
function buildEditor() {
  const lc=$('#product-language').value;
  const localized=E.texts.includes(editorField.key);
  const draftLanguages=Object.keys(editorDraft.changes).map(key=>key.slice(editorField.key.length+1)).filter(code=>/^[a-z]{2,3}(?:-[a-z]{2})?$/.test(code));
  const options=[...new Set([lc,...D.languages(editorDraft.product),...draftLanguages])].sort();
  $('#editor-language').innerHTML=options.map(code=>`<option value="${esc(code)}">${esc(code.toUpperCase())}</option>`).join('');
  $('#editor-language').value=lc;
  $('#editor-title').textContent=T('editor.title', {label: editorField.title});
  $('#editor-context').textContent=T('editor.context', {code: editorDraft.product.code});
  $('#editor-language-controls').hidden=!localized;
  $('#editor-text-fields').hidden=editorField.key==='nutrition';
  $('#editor-nutrients').hidden=editorField.key!=='nutrition';
  $('#editor-help').textContent=localized ? T('editor.help_localized') : E.tags.includes(editorField.key) ? T('editor.help_tags') : '';
  if(editorField.key==='nutrition') {
    const set=E.nutrientSource(editorDraft.product,editorField.nutrient,editorField.sourceKey);
    $('#editor-help').textContent=set ? `${set.source} / ${set.preparation} / ${set.per}` : '';
    renderEditorNutrition();
  } else renderEditorLanguage();
  refreshEditorPatch();
}
/**
 * Identity of a draft. Opening the same row again reuses the pending draft; opening a different
 * row, product or environment starts from the current values instead.
 */
function scopeForEditor(code) {return `${state.environment}/${code}/${editorField.key}/${editorField.sourceKey ?? ''}/${editorField.nutrient ?? ''}`;}
/**
 * Open the edit dialog for one row.
 *
 * Refuses outside staging, without a loaded product, or while a request is running; asks for
 * credentials first when none are stored; and re-checks that the field is actually writable
 * before showing anything.
 *
 * @param {{key:string,title:string,nutrient?:string,sourceKey?:string}} field Row descriptor.
 */
function openEditor(field) {
  if (state.environment !== 'staging' || !loadedProduct || busy) return;
  if (!state.username) { openAccount(); return; }
  if(field.key==='nutrition' ? !E.nutrientSource(loadedProduct,field.nutrient,field.sourceKey) : !E.fieldForRow(field.key)) return;
  editorField=field;
  const scope=scopeForEditor(loadedProduct.code);
  if (!editorDraft || scope!==editorScope) {
    editorDraft=E.createDraft(loadedProduct); editorScope=scope;
    $('#editor-message').textContent=''; $('#editor-response').hidden=true;
  }
  buildEditor(); $('#editor-dialog').showModal();
}
/** Patch for the current draft. Throws on a conflicting field, see OFFEditor.patch(). */
function editorPatch() { return E.patch(editorDraft); }
/**
 * Recompute the patch preview, the change counter and the enabled state of the save button.
 * An empty patch, a lost session or a non-staging environment all keep saving disabled.
 */
function refreshEditorPatch() {
  try {
    const patch=editorPatch();
    $('#editor-patch').textContent=JSON.stringify(patch,null,2);
    const count=Object.keys(patch).length;
    $('#editor-count').textContent=count;
    $('#editor-save').disabled=!count || editorSaving || state.environment!=='staging' || !state.username;
  } catch(e) {$('#editor-patch').textContent=e.message; $('#editor-save').disabled=true;}
}
$('#editor-language').addEventListener('change',renderEditorLanguage);
// Add a language that the product does not declare yet, e.g. to seed a first translation.
$('#editor-add-language').addEventListener('click',()=>{
  const code=$('#editor-new-language').value.trim().toLowerCase();
  if(!/^[a-z]{2,3}(?:-[a-z]{2})?$/.test(code)) {$('#editor-message').textContent=T('editor.bad_language');return;}
  if(![...$('#editor-language').options].some(o=>o.value===code)) $('#editor-language').add(new Option(code.toUpperCase(),code));
  $('#editor-language').value=code; $('#editor-new-language').value=''; $('#editor-message').textContent=''; renderEditorLanguage();
});
$('#editor-close').addEventListener('click',()=>{if(!editorSaving)$('#editor-dialog').close();});
$('#editor-dialog').addEventListener('cancel',e=>{if(editorSaving)e.preventDefault();});
$('#editor-reset').addEventListener('click',()=>{editorDraft=E.createDraft(loadedProduct);$('#editor-message').textContent='';$('#editor-response').hidden=true;buildEditor();});
// Save: send the patch through `updateProduct()`, then re-read the product so the displayed
// values come from Open Food Facts rather than from the local draft. The dialog stays open on
// a fresh draft, so consecutive edits of the same row remain possible.
$('#editor-form').addEventListener('submit',async e=>{
  e.preventDefault();
  if(editorSaving || busy || state.environment!=='staging' || !state.username)return;
  let patch; try{patch=editorPatch();}catch(error){$('#editor-message').textContent=error.message;return;}
  if(!Object.keys(patch).length)return;
  const code=String(editorDraft.product.code),lc=$('#editor-language').value;
  editorSaving=true; updateEditAvailability(); $('#editor-message').textContent=T('editor.saving'); $('#editor-response').hidden=true;
  $('#request-method').textContent='updateProduct()';
  try {
    const result=await request({action:'update',barcode:code,language:lc,product:patch},true);
    $('#editor-message').textContent=T(result.data.status==='success_with_warnings'?'editor.saved_warnings':'editor.saved');
    if(result.data.warnings?.length){$('#editor-response').textContent=JSON.stringify(result.data.warnings,null,2);$('#editor-response').hidden=false;}
    editorDraft=null; editorScope='';  $('#editor-patch').textContent='{}'; $('#editor-count').textContent='0';
    try {
      const read=await request({action:'product',barcode:code,language:lc},false);
      renderProduct(read.data,lc);loadedBarcode=code;
      editorDraft=E.createDraft(read.data);editorScope=scopeForEditor(code);buildEditor();
    } catch(error) {
      // The write succeeded even if the following read fails; never retry the write automatically.
      $('#editor-message').textContent+=T('editor.reread_failed', {message: error.message});
      $('#editor-fields').disabled=true;
    }
  } catch(error) {
    $('#editor-message').textContent=error.message;
    if(error.response?.data){$('#editor-response').textContent=JSON.stringify(error.response.data,null,2);$('#editor-response').hidden=false;}
  } finally {
    editorSaving=false;
    updateEditAvailability();
    if(editorDraft) refreshEditorPatch(); else {$('#editor-fields').disabled=true;$('#editor-save').disabled=true;}
  }
});
