# openfoodfacts-php — demo

Démo développeur pour tester exclusivement le wrapper PHP [TaciteOFF/openfoodfacts-php](https://github.com/TaciteOFF/openfoodfacts-php).

**Démo en ligne : <https://openfoodfacts-php-demo.fly.dev/>** — ce dossier en est le code source complet, conservé dans le dépôt du wrapper sous `examples/02_demo`.

Interface disponible en **français et en anglais**, via le sélecteur FR/EN de l’en-tête. Le code, les commentaires et la documentation sont en anglais ; la version anglaise de ce document est [README.md](README.md).

## Démarrage

Prérequis : PHP 8.1 ou supérieur, Composer 2, extensions JSON et Fileinfo, accès HTTPS sortant. Version locale vérifiée : PHP 8.5.

```sh
composer install
composer start
```

Ouvrir http://127.0.0.1:8080. Le point d’entrée HTTP est le dossier `public/`.

## Fonctionnalités

- Interface bilingue (FR / EN). La première visite suit l’en-tête `Accept-Language` du navigateur, puis le choix est conservé dans la session PHP. La langue de l’interface est indépendante de la langue du produit : passer la page en anglais ne change pas la traduction des données demandée à Open Food Facts. Le changement recharge la page, afin que le rendu serveur et les messages de l’API reviennent dans la même langue.
- Lecture complète d’un produit (`fields=null`) : labels, caractéristiques, ingrédients, allergènes, emballage, photos et scores. Tous les champs renvoyés par le wrapper sont aussi consultables dans une arborescence exhaustive.
- Sélecteur des langues déclarées par le produit : un changement recharge la fiche via `getProduct(..., lc, ..., tagsLc)`, actualise les textes et les photos disponibles. Le champ générique renvoyé par OFF sert de repli si une traduction manque.
- Tableau de tous les nutriments de `nutrition.aggregated_set` (schéma 1004), sans liste limitative : zéros, décimales, modificateurs, unités, base, sources et valeurs calculées. Les jeux `input_sets` et les champs `nutriments` legacy restent consultables séparément. Aucune valeur n’est supprimée parce qu’elle vaut zéro ; les unités des sources ne sont pas converties ni devinées.
- Recherche de produit ou de marque, 12 résultats par page et ouverture d’une fiche depuis les résultats.
- Production en lecture seule ; staging activé via `activeTestMode()`.
- Identifiants OFF dans la session PHP ; déconnexion et effacement lors d’un changement d’environnement ; expiration après 30 minutes d’inactivité.
- Édition **staging uniquement** via `updateProduct()` : bouton en fin de chaque ligne modifiable (nom, quantité, catégories, labels, etc.). Le dialogue ne contient que le champ choisi ; son brouillon est conservé entre les langues et à la réouverture de cette même ligne. Passer à une autre ligne repart de ses valeurs actuelles. Aperçu du patch limité au champ choisi, puis relecture automatique du produit après enregistrement. Additifs, analyse des ingrédients et date indiquée restent en lecture seule, avec rejet côté serveur.
- Nutrition modifiable dans les sources `packaging` et `manufacturer` : action sur chaque nutriment saisi, avec sa source précise, son unité, son modificateur et conservation des zéros. Les valeurs uniquement calculées ne proposent pas d’édition. Les patches utilisent `nutrition.input_sets` et `value_string` ; les estimations et agrégats ne sont pas recopiés.
- Upload **staging uniquement**, JPEG/PNG jusqu’à 5 Mo, aperçu, type de photo et langue. Le serveur vérifie la taille, le type réel et le contenu de l’image.
- Réponse JSON issue du wrapper, copie, durée de la requête et traitement des erreurs partielles.
- Interface responsive, navigation au clavier, onglets ARIA, dialogue natif et états d’attente/erreur.

### Limite de la connexion

`Api::authentification()` enregistre les identifiants mais **ne fait aucune requête de validation**. Le wrapper n’expose pas de méthode de connexion qui vérifie le compte. La démo affiche donc « identifiants enregistrés, non vérifiés » et ne les marque comme acceptés qu’après un upload ou une modification réussie. Aucun endpoint OFF direct n’a été ajouté pour contourner cette limite. La connexion à un compte de production n’est pas vérifiable dans cette interface de consultation.

Le compte contributeur staging est distinct de la protection HTTP Basic `off` / `off` du serveur de test. Cette protection est gérée par le wrapper. Les identifiants d’un compte production ne sont jamais reportés automatiquement vers le staging.

### Architecture et exclusivité du wrapper

Le JavaScript ne contacte que `api.php` sur le même serveur. Tous les appels de données OFF utilisent le paquet VCS demandé : `getProduct()`, `search()`, `authentification()`, `activeTestMode()` `uploadImage()` et `updateProduct()`. Guzzle, dépendance du wrapper, est configuré comme transport pour les délais réseau, l’en-tête d’identification et le blocage des écritures hors du domaine HTTPS staging, y compris après redirection. Il n’y a ni cURL OFF direct, ni autre SDK, ni fallback vers une autre API.

Les photos sont affichées depuis les URL renvoyées par le wrapper ; seuls les domaines d’images OFF sont acceptés. Les liens externes ouvrent la documentation et les fiches OFF. Les photos staging peuvent être indisponibles dans le navigateur ; un état de remplacement est prévu.

`composer.lock` fixe la révision du fork utilisée. L’installation vient du dépôt TaciteOFF, et non de la version upstream sur Packagist.

## Déploiement

Le dépôt fournit une image de production : Apache + mod_php, `public/` comme racine web, le reste hors de la racine web. Aucune modification du code n’est nécessaire : sessions, upload de 5 Mo et limitation par session fonctionnent comme en local.

```sh
docker build -t off-lab .
docker run --rm -p 8080:8080 -e OFF_USER_AGENT="Ma démo - Web - 1.0 - https://…" off-lab
```

Le serveur écoute sur `$PORT` (8080 par défaut), la variable injectée par Fly.io, Render et Railway. Configurations prêtes à l’emploi : [`fly.toml`](fly.toml), [`render.yaml`](render.yaml), [`railway.json`](railway.json). Définir `OFF_USER_AGENT` ; c’est la seule variable nécessaire.

**Conserver une seule instance.** Les sessions PHP résident sur le système de fichiers de l’instance : une deuxième réplique donnerait une autre session aux utilisateurs, d’où des échecs CSRF aléatoires, la perte des identifiants et une limitation de débit réinitialisée. Les trois fichiers de configuration fixent une instance. Passer à l’échelle suppose d’abord de déplacer les sessions vers Redis (`session_set_save_handler`).

**TLS.** Ces plateformes terminent TLS en périphérie et transmettent du HTTP : PHP ne verrait pas HTTPS et retirerait l’attribut `Secure` du cookie de session. Le vhost reconvertit l’en-tête `X-Forwarded-Proto` du proxy en `HTTPS=on`. Si le conteneur est exposé directement, supprimer cette ligne et terminer TLS en amont.

### Vercel et plateformes serverless

Non supporté, pour deux raisons concrètes :

- **Les sessions.** Les fonctions Vercel ont un système de fichiers en lecture seule avec un `/tmp` éphémère, sont archivées à l’inactivité et se répartissent sur des microVM isolées. Comme chaque POST est vérifié par CSRF contre la session, les utilisateurs subiraient des « session expirée » aléatoires, la perte des identifiants et le contournement de la limitation qui protège les serveurs Open Food Facts. Il faudrait un stockage de session externe ; placer l’état dans un cookie enverrait le mot de passe OFF au navigateur, ce que ce projet ne fait jamais.
- **La taille d’upload.** La limite de 4,5 Mo sur le corps des requêtes rejette l’upload documenté de 5 Mo par une erreur 413, avant même l’exécution de PHP.

PHP y est disponible via le runtime communautaire `vercel-php` : une variante en lecture seule se déploierait, mais les fonctions compte, upload et édition ne fonctionneraient pas comme prévu.

## Hébergement PHP

Sans utiliser l’image ci-dessus, configurer Apache/Nginx avec PHP-FPM, **HTTPS**, et `public/` comme racine web. Ne pas exposer la racine du projet. Le serveur `php -S` est réservé au développement local.

Configuration recommandée :

```ini
upload_max_filesize = 6M
post_max_size = 8M
memory_limit = 128M
max_execution_time = 40
session.cookie_httponly = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
```

Configurer également une limite de corps suffisante dans le reverse proxy (ex. `client_max_body_size 8m`). Le serveur doit reconnaître HTTPS pour émettre le cookie Secure, notamment derrière un proxy. Prévoir un stockage des sessions PHP privé avec nettoyage des sessions expirées ; les mots de passe ne sont pas stockés dans les fichiers du projet, le navigateur ou les journaux applicatifs.

Définir `OFF_USER_AGENT` avec le nom et un contact pour votre déploiement, par exemple `Ma démo PHP - Web - 1.0 - https://mon-domaine.example/contact`. Par défaut : `openfoodfacts-php-demo/1.0 (+https://github.com/TaciteOFF/openfoodfacts-php)`. Cette identification alimente le `User-Agent` standard du wrapper et le `X-User-Agent` ajouté aux requêtes sortantes vers OFF. Un `X-User-Agent` déjà présent est conservé (comparaison insensible à la casse). Pour une instance publique à fort trafic, appliquer aussi une limitation globale au niveau du reverse proxy : les limites intégrées ne sont que par session (lecture : 1 s ; recherche : 6 s ; upload et modification : 5 s).

L’hébergement Sites/Cloudflare Workers disponible dans cette session n’exécute pas PHP natif. Le livrable est donc une application PHP locale prête à transférer vers un hébergeur PHP, pas une URL publique déployée.

## Vérifications

```sh
composer test
# Tests du rendu de données (Node.js, sans dépendance npm)
node --test tests/*.test.cjs
```

La suite démarre son propre serveur PHP sur un port local temporaire. Elle teste les sessions, CSRF, la restriction staging côté serveur, les entrées invalides, la déconnexion, le masquage du mot de passe et la langue de l’interface (changement, détection via `Accept-Language`, page rendue, messages traduits et parité stricte des clés entre les deux catalogues). Elle teste aussi le véritable wrapper avec un transport simulé pour vérifier les URL v3.6, l’authentification staging, la photo base64, la langue, la pagination, les patches multilingues, les zéros nutritionnels, les erreurs partielles et les redirections d’écriture hors staging. Aucun compte réel et aucune écriture OFF ne sont utilisés dans les tests.

Lecture réelle vérifiée manuellement en production et en staging avec le code-barres `3057640385148` ; recherche réelle « chocolat » validée avec 12 résultats sur la première page. Affichage mobile vérifié à 320 et 390 px, sans débordement horizontal. La fiche multilingue `3017620422003` a aussi été vérifiée : 67 nutriments agrégés, 348 champs, labels et fibres à 0 g ; passages FR → EN → NL avec rechargement réel des ingrédients. L’édition par ligne a été vérifiée à 390 px : quantité seule, catégories seules, ingrédients FR/EN et sel à zéro ; les champs exclus restent sans bouton. Les brouillons de test ont été réinitialisés sans envoi. Les écritures réelles restent à vérifier avec un compte staging ; les tests utilisent un transport simulé et ne modifient aucun produit OFF.

## Licences

Code de l’application : MIT. Le wrapper conserve sa licence MIT. Données Open Food Facts : ODbL ; photos : CC BY-SA, avec attribution via la fiche produit. Outil indépendant, non officiel.
