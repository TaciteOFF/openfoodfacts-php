/**
 * Node test suite for public/assets/product-editor.js - draft and patch model.
 *
 * The fixture carries the two situations the editor has to get right: a product whose main
 * language is French with only a generic `product_name` (so an English draft must start empty
 * rather than copy the French text), and two nutrition input sets - an editable `packaging` one
 * holding a nutrient at zero, and an `estimate` one that must stay read-only.
 *
 * Run with: node --test tests/*.test.cjs
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const E = require('../public/assets/product-editor.js');
const product = {
  lang:'fr', product_name:'Nom générique FR', ingredients_text_fr:'Lait', ingredients_text_en:'Milk',
  labels_tags:['en:organic'], nutrition:{input_sets:{0:{source:'packaging', preparation:'as_sold', per:'100g',
    nutrients:{fat:{value:5,value_string:'5.0',unit:'g'},salt:{value:0,value_string:'0',unit:'g'}}},
    1:{source:'estimate',preparation:'as_sold',per:'100g',nutrients:{fat:{value:9,unit:'g'}}}}}
};
test('une langue absente reste vide, sans copie du texte de repli',()=>{
  assert.equal(E.original(product,'product_name_fr'),'Nom générique FR');
  assert.equal(E.original(product,'product_name_en'),'');
  assert.equal(E.original(product,'ingredients_text_en'),'Milk');
});
test('les brouillons FR et EN sont indépendants et les champs intacts sont omis',()=>{
  const d=E.createDraft(product);
  E.setField(d,'ingredients_text_fr','Lait, cacao');
  E.setField(d,'ingredients_text_en','Milk, cocoa');
  E.setField(d,'labels_tags',['en:organic']);
  assert.deepEqual(E.patch(d),{ingredients_text_fr:'Lait, cacao',ingredients_text_en:'Milk, cocoa'});
  E.setField(d,'ingredients_text_fr','Lait');
  assert.deepEqual(E.patch(d),{ingredients_text_en:'Milk, cocoa'});
  assert.equal(product.ingredients_text_fr,'Lait');
});
test('vider un texte ou une liste produit un patch explicite',()=>{
  const d=E.createDraft(product);
  E.setField(d,'ingredients_text_fr',''); E.setField(d,'labels_tags',[]);
  assert.deepEqual(E.patch(d),{ingredients_text_fr:'',labels_tags:[]});
});
test('les tags sources sont préférés aux tags agrégés',()=>{
  assert.deepEqual(E.original({labels_tags:['en:organic','en:inferred'],tags_sources:{labels:{packaging:{tags:['en:organic']}}}},'labels_tags'),['en:organic']);
});
test('les estimations ne sont pas proposées comme sources modifiables',()=>{
  const sets=E.sourceSets(product);
  assert.equal(sets.length,1);sets[0].nutrients.fat.value=999;
  assert.equal(product.nutrition.input_sets[0].nutrients.fat.value,5);
});
test('un zéro est envoyé en value_string, sans valeur calculée ni nutriments intacts',()=>{
  const d=E.createDraft(product),set=E.sourceSets(product)[0];
  E.setNutrient(d,set,'fat',{value:'0',unit:'g',modifier:''});
  assert.deepEqual(E.patch(d),{nutrition:{input_sets:[{source:'packaging',preparation:'as_sold',per:'100g',nutrients:{fat:{value_string:'0',unit:'g',modifier:''}}}]}});
  E.setNutrient(d,set,'fat',{value:'5.0',unit:'g',modifier:''});
  assert.deepEqual(E.patch(d),{});
});
test('la portion et le modificateur sont conservés, une nouvelle source reste isolée',()=>{
  const d=E.createDraft(product),set={source:'manufacturer',preparation:'prepared',per:'serving',per_quantity:30,per_unit:'g',nutrients:{}};
  E.setNutrient(d,set,'fiber',{value:'0',unit:'g',modifier:'<'});
  const patch=E.patch(d).nutrition.input_sets[0];
  assert.equal(patch.per_quantity,30);assert.equal(patch.per_unit,'g');
  assert.deepEqual(patch.nutrients.fiber,{value_string:'0',unit:'g',modifier:'<'});
});
test('le JSON complémentaire ne peut pas écraser un brouillon',()=>{
  const d=E.createDraft(product);E.setField(d,'ingredients_text_fr','Nouveau');
  assert.throws(()=>E.patch(d,{ingredients_text_fr:'Autre'}),/déjà modifié/);
  assert.throws(()=>E.patch(d,JSON.parse('{"__proto__":{}}')),/interdit/);
  assert.equal(E.patch(d,{packagings_complete:0}).packagings_complete,0);
});

test('les boutons ciblent les sources et excluent les trois champs en lecture seule',()=>{
  assert.equal(E.fieldForRow('quantity'),'quantity');
  assert.equal(E.fieldForRow('categories'),'categories_tags');
  assert.equal(E.fieldForRow('ingredients_text'),'ingredients_text');
  for(const key of ['expiration_date','additives_tags','ingredients_analysis_tags']) assert.equal(E.fieldForRow(key),null);
});
test('seuls les nutriments saisis de la source exacte peuvent être modifiés',()=>{
  assert.equal(E.nutrientSource(product,'salt','0').nutrients.salt.value,0);
  assert.equal(E.nutrientSource(product,'fat','1'),null);
  assert.equal(E.nutrientSource(product,'salt','9'),null);
  assert.equal(E.nutrientSource(product,'nova-group','0'),null);
});
test('chaque ligne affiche son action et les champs exclus restent sans bouton',()=>{
  const D=require('../public/assets/product-data.js');
  const html=D.attributes({quantity:0,categories_tags:['en:beverages'],expiration_date:'2027',additives_tags:['en:e330'],ingredients_analysis_tags:['en:vegan']},'fr',(key)=>E.fieldForRow(key)?'<button>Modifier</button>':'');
  assert.equal((html.match(/<button>/g)||[]).length,2);
  for(const key of ['expiration_date','additives_tags','ingredients_analysis_tags']) assert.match(html,new RegExp('data-attribute="'+key+'"[^]*?</div>'));
});
