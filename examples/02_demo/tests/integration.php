<?php
/**
 * PHP integration suite: `composer test`.
 *
 * Runs in three stages, none of which ever touches a real Open Food Facts account or writes
 * anything to OFF:
 *
 *  1. HTTP stage - boots a throwaway `php -S` server on a random local port against `public/`
 *     and drives `api.php` over real HTTP with a cookie jar. This covers the session lifecycle,
 *     the CSRF check, the server-side staging restriction (including the case where the browser
 *     lies about the environment in the body), input validation, logout and password masking.
 *
 *  2. Patch stage - calls `validateProductPatch()` directly with one valid multilingual patch
 *     (empty label list, zero nutrient kept as a string) and a table of payloads that must all
 *     be rejected: computed fields, read-only tags, credentials, aggregated nutrition, a text
 *     field without a language suffix, an `estimate` source, and a numeric `value`.
 *
 *  3. Wrapper stage - instantiates the *real* fork through the application's own `api()`
 *     factory and swaps only the Guzzle handler for a MockHandler. This asserts the v3.6 URLs,
 *     the standard User-Agent plus the application `X-User-Agent` (and that an existing header
 *     is preserved case-insensitively), staging authentication, the base64 image payload,
 *     language and pagination parameters, patch fidelity, partial-failure handling, and that a
 *     PATCH redirected towards production is blocked before it leaves the process.
 *
 * Failure mode: any failed assertion throws, so the script exits non-zero.
 *
 * @package off-lab/playground
 * @license MIT
 */
declare(strict_types=1);
require dirname(__DIR__) . '/public/bootstrap.php';
require dirname(__DIR__) . '/app/product-patch.php';
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenFoodFacts\Api;
/** Minimal assertion helper: print the label on success, throw on failure. */
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('ÉCHEC : ' . $label);
    echo "OK : $label\n";
}
// --- Stage 1: real HTTP against a throwaway PHP server -------------------------------------
// A random high port avoids collisions when the suite runs concurrently; the server log is kept
// in a temp file so a startup failure is not silently swallowed.
$port = random_int(20000, 40000);
$log = tmpfile();
$process = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=8M', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public'], [0 => ['pipe','r'], 1 => $log, 2 => $log], $pipes);
if (!is_resource($process)) throw new RuntimeException('Serveur de test indisponible.');
$http = new Client(['base_uri' => 'http://127.0.0.1:' . $port, 'cookies' => new CookieJar(), 'http_errors' => false, 'timeout' => 5]);
$csrf = '';
try {
    // Wait up to 3 s for the server to accept connections.
    for ($i=0;$i<30;$i++) {
        try { $response = $http->get('api.php'); break; } catch (Throwable $e) { usleep(100000); }
    }
    $state = json_decode((string)$response->getBody(),true)['state'];
    $csrf = $state['csrf'];
    check($state['environment'] === 'prod' && $state['username'] === null,'session initiale en production');
    // POST helper: sends JSON with the session CSRF token unless an explicit token is given.
    $send = function(array $data, ?string $token = null) use($http,&$csrf): array {
        $r = $http->post('api.php',['headers'=>['X-CSRF-Token'=>$token ?? $csrf],'json'=>$data]);
        return [$r->getStatusCode(),json_decode((string)$r->getBody(),true)];
    };
    [$status] = $send(['action'=>'environment','environment'=>'staging'],'invalid');
    check($status === 403,'CSRF invalide rejeté');
    [$status] = $send(['action'=>'upload','environment'=>'staging','barcode'=>'3057640385148']);
    check($status === 403,'upload production bloqué même avec staging dans le corps');
    [$status] = $send(['action'=>'update','environment'=>'staging','barcode'=>'3057640385148','language'=>'fr','product'=>['product_name_fr'=>'Test']]);
    check($status===403,'modification production bloquée malgré le corps staging');
    [$status,$r] = $send(['action'=>'login','username'=>'test-only','password'=>'test-only-never-transmitted']);
    check($status===200 && !$r['state']['credentialsVerified'],'identifiants explicitement non vérifiés');
    check(!str_contains(json_encode($r),'test-only-never-transmitted'),'mot de passe absent des réponses');
    [$status,$r] = $send(['action'=>'environment','environment'=>'staging']);
    check($status===200 && $r['state']['username']===null,'changement environnement efface les identifiants');
    [$status] = $send(['action'=>'upload','barcode'=>'3057640385148']);
    check($status===401,'upload staging sans identifiants rejeté');
    [$status] = $send(['action'=>'update','barcode'=>'3057640385148','language'=>'fr','product'=>['product_name_fr'=>'Test']]);
    check($status===401,'modification staging sans identifiants rejetée');
    [$status] = $send(['action'=>'product','barcode'=>'../invalid']);
    check($status===400,'code-barres invalide rejeté');
    [$status] = $send(['action'=>'login','username'=>['invalid'],'password'=>'test']);
    check($status===400,'type de champ invalide rejeté');
    [$status] = $send(['action'=>'environment','environment'=>'https://evil.invalid']);
    check($status===400,'environnement hors liste rejeté');
    $send(['action'=>'login','username'=>'test-only','password'=>'test-only-never-transmitted']);
    [$status] = $send(['action'=>'update','barcode'=>'3057640385148','language'=>'fr','product'=>['product_name_fr'=>'Test']],'invalid');
    check($status===403,'modification sans CSRF valide rejetée');
    [$status] = $send(['action'=>'update','barcode'=>'3057640385148','language'=>'fr','product'=>['nutriscore_score'=>0]]);
    check($status===400,'champ calculé rejeté avant tout appel au wrapper');
    [$status,$r] = $send(['action'=>'logout']);
    check($status===200 && $r['state']['username']===null,'déconnexion efface les identifiants');
    check($http->request('PUT','api.php')->getStatusCode()===405,'méthode inattendue rejetée');
    // Interface language: stored server-side, reflected in the state and in the messages.
    [$status,$r] = $send(['action'=>'interface','language'=>'en']);
    check($status===200 && $r['state']['interfaceLanguage']==='en','langue d’interface enregistrée en session');
    // `environment` carries no rate limit, so this only exercises the message language.
    [$status,$r] = $send(['action'=>'environment','environment'=>'nope']);
    check($status===400 && $r['message']==='Invalid environment.','message d’erreur servi en anglais');
    check(str_contains((string)$http->get('api.php')->getBody(),'"interfaceLanguage":"en"'),'état GET expose la langue d’interface');
    $page = (string)$http->get('index.php')->getBody();
    check(str_contains($page,'<html lang="en"') && str_contains($page,'>Barcode<') && !str_contains($page,'>Code-barres<'),'page rendue en anglais');
    [$status] = $send(['action'=>'interface','language'=>'de']);
    check($status===400,'langue d’interface hors liste rejetée');
    [$status,$r] = $send(['action'=>'interface','language'=>'fr']);
    check($status===200 && $r['state']['interfaceLanguage']==='fr','retour au français');
    $page = (string)$http->get('index.php')->getBody();
    check(str_contains($page,'<html lang="fr"') && str_contains($page,'>Code-barres<'),'page rendue en français');
} finally { proc_terminate($process); proc_close($process); fclose($log); }

// --- Stage 2: the write allow-list, called directly ------------------------------------------
// One patch that must pass, exercising the three delicate cases at once: two languages, an
// intentionally emptied tag list, and a nutrient at zero transmitted as the string "0".
// --- Interface catalogue ---------------------------------------------------------------------
// Both locales must define exactly the same keys, otherwise a translation silently falls back.
$catalogue = ui_catalogue();
check(array_keys($catalogue) === UI_LOCALES, 'catalogue limité aux langues déclarées');
foreach (UI_LOCALES as $locale) {
    check(array_keys($catalogue[$locale]) === array_keys($catalogue['fr']), "clés identiques pour la langue $locale");
    check(!in_array('', array_map('strval', $catalogue[$locale]), true), "aucune traduction vide en $locale");
}
check(ui_locale_from_browser('en-GB,en;q=0.9,fr;q=0.8')==='en' && ui_locale_from_browser('fr-FR,fr;q=0.9')==='fr'
    && ui_locale_from_browser('de,es;q=0.8')==='fr' && ui_locale_from_browser('')==='fr','langue d’interface déduite d’Accept-Language');
$_SESSION['ui']='en';
check(t('err.barcode')!==t('patch.too_large') && str_contains(t('patch.bad_text',['field'=>'product_name_fr']),'product_name_fr'),'traduction et interpolation des paramètres');
$_SESSION['ui']='fr';

$patch=['ingredients_text_fr'=>'Lait, cacao','ingredients_text_en'=>'Milk, cocoa','labels_tags'=>[],
    'nutrition'=>['input_sets'=>[['source'=>'packaging','preparation'=>'as_sold','per'=>'100g','nutrients'=>['fiber'=>['value_string'=>'0','unit'=>'g','modifier'=>'']]]]]];
check(validateProductPatch($patch)===$patch,'patch multilingue avec labels vides et nutriment zéro accepté');
// Every payload below must be rejected, one reason per entry.
foreach ([[], ['expiration_date'=>'2027-01-01'], ['additives_tags'=>['en:e330']], ['ingredients_analysis_tags'=>['en:vegan']], ['password'=>'secret'], ['nutrition'=>['aggregated_set'=>[]]], ['product_name'=>'Texte sans langue'], ['product_type'=>'beauty'], ['nutrition'=>['input_sets'=>[['source'=>'estimate','preparation'=>'as_sold','per'=>'100g','nutrients'=>['fat'=>['value_string'=>'0','unit'=>'g']]]]]], ['nutrition'=>['input_sets'=>[['source'=>'packaging','preparation'=>'as_sold','per'=>'100g','nutrients'=>['fat'=>['value'=>0,'unit'=>'g']]]]]]] as $invalid) {
    try { validateProductPatch($invalid); throw new RuntimeException('Patch invalide accepté'); }
    catch (InvalidArgumentException $e) { check(true,'validation du patch invalide : '.(array_key_first($invalid)??'vide')); }
}

// The actual fork, with its transport mocked: no write reaches OFF.
// Each mocked response below is consumed in order by the calls that follow.
$history=[];
$mock = new MockHandler([
    new Response(200,[],json_encode(['status'=>'success','product'=>['code'=>'3057640385148','product_name'=>'Fixture']])),
    new Response(200,[],json_encode(['status'=>'success','result'=>['id'=>'image_uploaded']])),
    new Response(200,[],json_encode(['status'=>'success_with_errors','errors'=>[['message'=>'partial failure']]])),
    new Response(200,[],json_encode(['products'=>[['code'=>'1234','product_name'=>'Fixture']],'count'=>1,'page'=>2,'page_size'=>12])),
    new Response(200,[], '{}'),
    new Response(200,[],json_encode(['status'=>'success','product'=>['code'=>'3057640385148']])),
    new Response(200,[],json_encode(['status'=>'success_with_errors','errors'=>[['message'=>'unknown nutrient']]])),
    new Response(307,['Location'=>'https://world.openfoodfacts.org/api/v3.6/product/3057640385148'], ''),
]);
// Exercise the application's actual factory/configuration; replace only the transport.
$api=api();
$clientProperty=new ReflectionProperty(Api::class, 'httpClient');
$client=$clientProperty->getValue($api);
$stack=$client->getConfig('handler');
$stack->setHandler($mock);
$stack->push(Middleware::history($history));
check($api->getProduct('3057640385148',null,'fr')->product_name==='Fixture','lecture via le fork réel');
check(str_contains((string)$history[0]['request']->getUri(),'/api/v3.6/product/3057640385148'),'lecture API v3.6');
check($history[0]['request']->getHeaderLine('X-User-Agent')===$api->userAgent,'X-User-Agent ajouté par la configuration applicative');
check($history[0]['request']->getHeaderLine('User-Agent')==='SDK PHP - '.$api->userAgent,'User-Agent standard du wrapper conservé');
$api->authentification('fixture-user','fixture-password');$api->activeTestMode();
$file=tempnam(sys_get_temp_dir(),'off-test-');
file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM3cAAAAASUVORK5CYII='));
try {
    $api->uploadImage('3057640385148','front',$file,'fr');
    $r=$history[1]['request'];$body=json_decode((string)$r->getBody(),true);
    check($r->getUri()->getHost()==='world.openfoodfacts.net','upload envoyé au staging par le wrapper');
    check($r->getHeaderLine('Authorization')==='Basic '.base64_encode('off:off'),'protection HTTP Basic staging distincte');
    check($body['user_id']==='fixture-user' && $body['password']==='fixture-password','identifiants contributeur conservés en staging');
    check(isset($body['selected']['front']['fr']) && base64_decode($body['image_data_base64'])===file_get_contents($file),'photo base64, type et langue transmis');
    try { $api->uploadImage('3057640385148','front',$file,'fr'); throw new RuntimeException('Réponse partielle non détectée'); }
    catch (OpenFoodFacts\Exception\ProductUpdateException $e) { check($e->getResponse()['status']==='success_with_errors','échec partiel conservé par le wrapper'); }
    $results=$api->search('chocolat',2,12);
    parse_str($history[3]['request']->getUri()->getQuery(),$query);
    check($results->searchCount()===1 && $query['page']==='2' && $query['page_size']==='12','recherche paginée via le wrapper');
    check($history[1]['request']->getHeaderLine('X-User-Agent')===$api->userAgent && $history[3]['request']->getHeaderLine('X-User-Agent')===$api->userAgent,'X-User-Agent présent sur upload staging et recherche');
    $client->get('https://example.invalid', ['headers'=>['x-user-agent'=>'Existing-App/2.0']]);
    check($history[4]['request']->getHeaderLine('X-User-Agent')==='Existing-App/2.0','X-User-Agent existant conservé indépendamment de la casse');
    $api->updateProduct('3057640385148',validateProductPatch($patch),['all'],'en',null,'en');
    $r=$history[5]['request'];$body=json_decode((string)$r->getBody(),true);
    check($r->getMethod()==='PATCH' && $r->getUri()->getHost()==='world.openfoodfacts.net','updateProduct PATCH envoyé exclusivement au staging');
    check($body['product']===$patch,'patch du wrapper préserve langues, labels vides et zéro value_string');
    check($body['lc']==='en' && $body['tags_lc']==='en','langue de réponse et de tags transmise');
    check($body['user_id']==='fixture-user' && $body['password']==='fixture-password' && $r->getHeaderLine('Authorization')==='Basic '.base64_encode('off:off'),'authentification staging de la modification');
    check($r->getHeaderLine('X-User-Agent')===$api->userAgent,'modification identifiée par X-User-Agent');
    try { $api->updateProduct('3057640385148',$patch); throw new RuntimeException('Écriture partielle acceptée'); }
    catch (OpenFoodFacts\Exception\ProductUpdateException $e) { check($e->getResponse()['errors'][0]['message']==='unknown nutrient','erreurs de modification partielle accessibles au frontend'); }
    try { $api->updateProduct('3057640385148',$patch); throw new RuntimeException('Redirection production acceptée'); }
    catch (InvalidArgumentException $e) { check(count($history)===8 && count($mock)===0,'redirection PATCH vers la production bloquée avant envoi'); }
} finally { unlink($file); }
echo "Tous les tests passent. Aucun envoi réel effectué.\n";
