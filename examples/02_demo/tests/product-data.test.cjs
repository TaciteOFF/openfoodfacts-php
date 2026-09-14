/**
 * Node test suite for public/assets/product-data.js - rendering rules, no DOM, no network.
 *
 * The fixture below is deliberately hostile: zeros, a value expressed only as the string
 * "0.000", a scientific-notation number, a null value, a nutrient id the module does not know,
 * an empty translation, a `product_name_debug` suffix that is not a language, a tag list
 * serialised as an object keyed by index, a `quantity` of 0 and a nested unknown field.
 *
 * Each test therefore pins one guarantee of the demo: no value is dropped because it looks
 * empty, no number is reformatted, unknown fields stay visible, external content is escaped,
 * and only Open Food Facts image hosts are accepted.
 *
 * Run with: node --test tests/*.test.cjs
 */
'use strict';
const assert = require('node:assert/strict');
const D = require('../public/assets/product-data.js');
// Tiny test harness: `node --test` collects them, and the counter is printed at the end.
let count = 0;
function test(name, fn) { fn(); count++; console.log('OK : '+name); }
const p = {
  code:'1234', lang:'fr', languages_codes:{fr:2,en:2,ar:1,nl:1},
  product_name_fr:'Produit français',product_name_en:'English product',product_name_ar:'منتج',
  ingredients_text_fr:'Eau',ingredients_text_en:'Water',product_name_debug:'not a locale',product_name_de:'',
  labels_tags:{1:'en:organic',0:'fr:label-rouge'},quantity:0,
  nutrition:{aggregated_set:{per:'100ml',preparation:'as_sold',nutrients:{
    fat:{value:0,unit:'g',source:'packaging',source_index:0},
    salt:{value:0,modifier:'<',unit:'g',value_computed:0,source:'estimate'},
    'unknown-nutrient':{value:'0.000',unit:'mg'},fiber:{value:null,unit:'g'},
    energy:{value:1.23e-8,unit:'kJ'}
  }},input_sets:{0:{per:'serving',source:'packaging',nutrients:{proteins:{value:0,value_string:'0.00',unit:'g'}}}}},
  zero:0,flag:false,empty:'',nothing:null,nested:{'new-field':{value:0}},nutriments:{'fat_100g':0}
};
test('toutes les langues réelles, sans suffixes techniques ni traductions vides',()=>assert.deepEqual(D.languages(p),['ar','en','fr','nl']));
test('nom et ingrédients basculent vers la langue sélectionnée',()=>{assert.equal(D.localized(p,'product_name','en'),'English product');assert.equal(D.localized(p,'ingredients_text','en'),'Water');assert.equal(D.localized(p,'product_name','ar'),'منتج');});
test('repli explicite en absence de traduction',()=>assert.equal(D.localized(p,'product_name','nl'),'Produit français'));
test('tous les nutriments, y compris inconnus et sans valeur numérique',()=>assert.equal(D.nutrientRows(p.nutrition.aggregated_set).length,5));
test('0 g, précision, modificateurs et valeurs calculées sont conservés',()=>{
 const html=D.nutrientTable(p.nutrition.aggregated_set);
 assert.match(html,/data-nutrient="fat"[\s\S]*?<div>0 g<\/div>/);
 assert.match(html,/&lt;0 g/);assert.match(html,/0 g<small>calculée/);assert.match(html,/0\.000 mg/);assert.match(html,/1\.23e-8 kJ/);
});
test('sources et portions conservent aussi zéro',()=>{const html=D.nutrition(p);assert.match(html,/serving/);assert.match(html,/0 g/);assert.match(html,/0\.00 g/);assert.match(html,/fat_100g/);});
test('labels sous forme de tableau associatif et quantité zéro',()=>{const html=D.attributes(p,'fr');assert.match(html,/en:organic/);assert.match(html,/fr:label-rouge/);assert.match(html,/>0<\/span><\/dd>/);});
test('arborescence exhaustive, booléens, zéro, null, chaîne vide et champs imbriqués',()=>{const html=D.tree(p);for(const expected of ['zero','false','new-field','nothing','null','&quot;&quot;']) assert.ok(html.includes(expected),expected);});
test('valeurs HTML externes échappées',()=>{const html=D.attributes({ingredients_text_fr:'<img src=x onerror=alert(1)>',labels_tags:['<script>']},'fr');assert.ok(!html.includes('<img'));assert.ok(!html.includes('<script>'));assert.match(html,/&lt;img/);});
test('photos localisées et domaines OFF uniquement',()=>{const q={selected_images:{front:{display:{fr:'https://images.openfoodfacts.org/fr.jpg',en:'https://images.openfoodfacts.org/en.jpg'}}}};assert.match(D.photo(q,'en'),/en.jpg$/);assert.equal(D.safeImage('javascript:alert(1)'),'');assert.equal(D.safeImage('https://evil.example/x.jpg'),'');});
test('les libellés suivent la langue de l’interface, les données restent intactes',()=>{
 const I=require('../public/assets/i18n.js');
 try {
  I.setLocale('en');
  const html=D.attributes(p,'en')+D.nutrition(p);
  assert.match(html,/Ingredients/);assert.match(html,/>Fat</);assert.match(html,/Nutrition sources/);
  assert.match(html,/0 g<small>computed/);
  // Data is untouched by the locale: zeros, precision and unknown ids stay as they are.
  assert.match(html,/0\.000 mg/);assert.match(html,/unknown-nutrient/);
 } finally { I.setLocale('fr'); }
});
test('une langue inconnue ne change pas le catalogue actif',()=>{
 const I=require('../public/assets/i18n.js');
 I.setLocale('xx');assert.equal(I.getLocale(),'fr');assert.equal(I.t('data.nutrition'),'Nutrition');
});
console.log(`${count} tests de données produit réussis.`);
