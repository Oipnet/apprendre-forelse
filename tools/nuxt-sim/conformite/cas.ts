/**
 * Requêtes envoyées à la fois au vrai Nuxt (conformite/reference, `nuxi dev`) et au simulateur.
 * Chaque cas vise un comportement que les exercices peuvent rencontrer. Avant d'imiter un nouveau
 * comportement dans le simulateur, on ajoute ici le cas (et la route de référence qui le déclenche).
 *
 * Les pages sont comparées sans les scripts et préchargements du <head>, propres à Vite : le reste du
 * document (corps rendu, configuration publique, charge utile) doit être identique à l'octet près.
 */
export interface Cas {
	nom: string;
	methode?: string;
	chemin: string;
	entetes?: Record<string, string>;
	corps?: string;
	/**
	 * « statut » : page HTML d'erreur de Nuxt, seuls le statut, sa raison et le type de contenu sont comparés.
	 * « page » : page rendue, comparée sans les scripts ni les liens du <head>.
	 */
	comparer?: 'tout' | 'statut' | 'page';
	/** Millisecondes à attendre avant d'interroger les deux serveurs (expiration d'un cache). */
	attendre?: number;
}

const JSON_ACCEPTE = { accept: 'application/json' };
const NAVIGATEUR = { accept: 'text/html,application/xhtml+xml' };

export const CAS: Cas[] = [
	// Valeurs rendues par un gestionnaire
	{ nom: 'objet rendu en JSON indenté', chemin: '/api/bonjour' },
	{ nom: 'chaîne rendue en text/html', chemin: '/api/texte' },
	{ nom: 'null rend 204', chemin: '/api/nul' },
	{ nom: 'rien rendu donne 204', chemin: '/api/rien' },
	{ nom: 'nombre rendu en JSON', chemin: '/api/nombre' },
	{ nom: 'tableau rendu en JSON', chemin: '/api/tableau' },
	{ nom: 'gestionnaire asynchrone', chemin: '/api/lent' },

	// Routage
	{ nom: 'fichier sans méthode : GET', chemin: '/api/methode' },
	{ nom: 'fichier sans méthode : DELETE', methode: 'DELETE', chemin: '/api/methode', entetes: JSON_ACCEPTE },
	{ nom: 'fichier sans méthode : HEAD', methode: 'HEAD', chemin: '/api/bonjour' },
	{ nom: 'index.get.ts', chemin: '/api/ports' },
	{ nom: 'barre oblique finale ignorée', chemin: '/api/ports/' },
	{ nom: 'paramètre [id]', chemin: '/api/ports/1' },
	{ nom: 'paramètre encodé dans le chemin', chemin: '/api/ports/%31' },
	{ nom: 'route statique prioritaire sur [id]', chemin: '/api/ports/speciaux' },
	{ nom: 'paramètre attrape-tout [...chemin]', chemin: '/api/fichiers/a/b%20c/d.txt' },
	{ nom: 'attrape-tout sur un seul segment', chemin: '/api/fichiers/carte.pdf' },
	{ nom: 'dossier de groupe (admin) ignoré', chemin: '/api/caisse' },
	{ nom: 'server/routes sans préfixe /api', chemin: '/sante' },

	// Lecture de la requête
	{ nom: 'getQuery : répétitions, vides, accents', chemin: '/api/recherche?a=1&b=x&b=y&c&d=&e=%C3%A9t%C3%A9&f[]=1&g=un+deux' },
	{ nom: 'getQuery sans paramètres', chemin: '/api/recherche' },
	{ nom: 'getHeader présent', chemin: '/api/entetes', entetes: { 'x-demande': 'sardines' } },
	{ nom: 'getHeader absent, setHeader', chemin: '/api/entetes' },
	{ nom: 'getRequestURL, hôte et protocole', chemin: '/api/requete?page=2' },
	{ nom: 'getRequestURL derrière un proxy', chemin: '/api/requete', entetes: { 'x-forwarded-proto': 'https', 'x-forwarded-host': 'criee.example' } },
	{ nom: 'useRuntimeConfig et variable NUXT_PUBLIC_…', chemin: '/api/config' },

	// Corps de requête
	{ nom: 'readBody JSON, setResponseStatus 201', methode: 'POST', chemin: '/api/ports', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{"nom":"Le Guilvinec"}' },
	{ nom: 'readBody JSON sans le champ attendu', methode: 'POST', chemin: '/api/ports', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{}' },
	{ nom: 'readBody sans corps', methode: 'POST', chemin: '/api/ports', entetes: JSON_ACCEPTE },
	{ nom: 'readBody formulaire urlencoded', methode: 'POST', chemin: '/api/formulaire', entetes: { 'content-type': 'application/x-www-form-urlencoded' }, corps: 'a=1&b=2&b=3&c=d%C3%A9j%C3%A0+vu' },
	{ nom: 'readBody text/plain', methode: 'POST', chemin: '/api/formulaire', entetes: { 'content-type': 'text/plain' }, corps: '12' },
	{ nom: 'readBody tableau JSON', methode: 'POST', chemin: '/api/formulaire', entetes: { 'content-type': 'application/json' }, corps: '[1,2]' },
	{ nom: 'readBody JSON invalide', methode: 'POST', chemin: '/api/formulaire', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{mauvais' },
	{ nom: 'readBody JSON avec charset (non strict)', methode: 'POST', chemin: '/api/formulaire', entetes: { 'content-type': 'application/json; charset=utf-8' }, corps: '{mauvais' },
	{ nom: 'readBody sans type de contenu', methode: 'POST', chemin: '/api/formulaire', corps: '{"a":1}' },
	{ nom: 'readBody « true » sans type de contenu', methode: 'POST', chemin: '/api/formulaire', corps: 'true' },

	// Partie serveur (chapitre 4 du parcours)
	{ nom: 'import JSON, server/utils et shared/utils auto-importés côté serveur', chemin: '/api/cabane/sentiers' },
	{ nom: 'getValidatedQuery valide', chemin: '/api/cabane/sentier?slug=lac-noir' },
	{ nom: 'getValidatedQuery : le validateur lève une erreur', chemin: '/api/cabane/sentier', entetes: JSON_ACCEPTE },
	{ nom: 'readValidatedBody : createError levé par le validateur', methode: 'POST', chemin: '/api/cabane/valide', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{}' },
	{ nom: 'readValidatedBody : le validateur rend false', methode: 'POST', chemin: '/api/cabane/valide?mode=faux', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{}' },
	{ nom: 'readValidatedBody : le validateur transforme le corps', methode: 'POST', chemin: '/api/cabane/valide?mode=transforme', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{"nom":"ada"}' },
	{ nom: 'readValidatedBody : JSON invalide', methode: 'POST', chemin: '/api/cabane/valide', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{mauvais' },
	{ nom: 'useStorage : écrire', methode: 'POST', chemin: '/api/cabane/registre', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{"nom":"Solène","couchettes":2}' },
	{ nom: 'useStorage : relire à la requête suivante', chemin: '/api/cabane/registre', entetes: JSON_ACCEPTE },

	// Réponses
	{ nom: 'setResponseStatus avec texte', chemin: '/api/statut' },
	{ nom: 'sendRedirect 301', chemin: '/api/redirection' },

	// Pages (chapitre 1 du parcours)
	{ nom: 'page : app.vue, layout par défaut, lien actif', chemin: '/', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : v-for et NuxtLink calculé', chemin: '/sentiers', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : barre oblique finale', chemin: '/sentiers/', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : paramètre [slug], useRoute, plusieurs racines', chemin: '/sentiers/lac-noir', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : query dans la charge utile', chemin: '/sentiers/lac-noir?x=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : definePageMeta et autre layout', chemin: '/topo', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page : layout inconnu (mauvaise casse), sans layout', chemin: '/topo-mal-nomme', entetes: NAVIGATEUR, comparer: 'page' },
	// Auto-imports, app.config et useState (chapitre 2 du parcours)
	{ nom: 'auto-imports : composants, composables, utils, shared, app.config, useState', chemin: '/sac', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'composant inconnu : commentaire et avertissements de Vue', chemin: '/sac/inconnu', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useState hors de setup : erreur 500', chemin: '/sac/hors-setup', entetes: JSON_ACCEPTE },
	{ nom: 'useState hors de setup : même erreur à la requête suivante (module non gardé)', chemin: '/sac/hors-setup?encore', entetes: JSON_ACCEPTE },
	{ nom: 'import vers un fichier absent : erreur 500', chemin: '/sac/mauvais-import', entetes: JSON_ACCEPTE },
	// Rendu serveur (chapitre 3 du parcours)
	{ nom: 'import.meta.server, onMounted et typeof window côté serveur', chemin: '/versant/garde', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'ClientOnly : repli en prop, en slot, ou vide', chemin: '/versant/client-only', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'état déclaré au chargement d’un module : première requête', chemin: '/versant/fuite', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'état déclaré au chargement d’un module : il fuit vers la requête suivante', chemin: '/versant/fuite?visiteur=2', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'window dans setup côté serveur : erreur 500', chemin: '/versant/window', entetes: JSON_ACCEPTE },
	{ nom: 'document vaut undefined côté serveur', chemin: '/versant/g-document', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'navigator vaut undefined côté serveur', chemin: '/versant/g-navigator', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'location vaut undefined côté serveur', chemin: '/versant/g-location', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'localStorage n’existe pas côté serveur', chemin: '/versant/g-localStorage', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page demandée en JSON : HTML quand même', chemin: '/topo', entetes: JSON_ACCEPTE, comparer: 'page' },
	// Chapitre 5 : récupérer les données (useFetch, useAsyncData, $fetch, callOnce)
	{ nom: 'useFetch : données rendues et transmises dans la charge utile', chemin: '/provisions', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useFetch : erreur 404 de l’API (NuxtError dans la charge utile), default', chemin: '/provisions/erreur', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useFetch : query réactive, transform et pick', chemin: '/provisions/choix', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useFetch : la clé change avec la query', chemin: '/provisions/choix?difficulte=facile', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'clés : deux useFetch identiques, une clé partagée par deux composants', chemin: '/provisions/partage', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: '$fetch dans le setup : hors charge utile, sans les en-têtes de la requête', chemin: '/provisions/direct', entetes: { ...NAVIGATEUR, 'user-agent': 'conformite' }, comparer: 'page' },
	{ nom: 'callOnce et useState', chemin: '/provisions/une-fois', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useLazyFetch et server: false côté serveur', chemin: '/provisions/paresse', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'diagnostics : gestionnaire qui rend undefined, même clé pour deux gestionnaires', chemin: '/provisions/avertissements', entetes: NAVIGATEUR, comparer: 'page' },
	// Écart connu : Nuxt compare le texte des gestionnaires après la transformation de Vite, qui renumérote les
	// imports par fichier (`(0,__vite_ssr_import_3__.$fetch)(…)`). Deux gestionnaires identiques écrits dans
	// deux fichiers déclenchent donc souvent NUXT_E3004 dans Nuxt, jamais dans le simulateur.
	{ nom: 'clé partagée entre un composant du layout et la page (dedupe cancel), await oublié', chemin: '/provisions/cle', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'clé partagée entre un composant du layout et la page (dedupe defer)', chemin: '/provisions/cle?dedupe=defer', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'même clé pour deux données différentes (dedupe defer)', chemin: '/provisions/collision?dedupe=defer', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'même clé : la page (dedupe cancel) remplace la donnée de l’en-tête (dedupe defer)', chemin: '/provisions/collision-annule?dedupe=defer', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'lazy côté serveur, server: false et message d’attente', chemin: '/provisions/attente', entetes: NAVIGATEUR, comparer: 'page' },

	// Chapitre 6 : API externes (une API « amont » locale, voir startUpstream), .env, cache de Nitro
	{ nom: 'API externe : $fetch côté serveur vers une URL absolue', chemin: '/api/amont/meteo' },
	{ nom: 'API externe en panne : FetchError converti en 502', chemin: '/api/amont/panne', entetes: JSON_ACCEPTE },
	{ nom: 'API externe : relances de ofetch (par défaut, retry: 2, POST)', chemin: '/api/amont/relances' },
	{ nom: 'API externe trop lente : timeout', chemin: '/api/amont/lent' },
	{ nom: 'API externe trop lente : pas de relance après le timeout', chemin: '/api/amont/lent-relance' },
	{ nom: 'clé lue dans le fichier .env', chemin: '/api/amont/cle' },
	{ nom: 'useFetch vers une URL absolue pendant le rendu, runtimeConfig public dans la page', chemin: '/provisions/amont', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'defineCachedEventHandler : premier appel, en-têtes de cache', chemin: '/api/cache/gestionnaire' },
	{ nom: 'defineCachedEventHandler : réponse gardée', chemin: '/api/cache/gestionnaire' },
	{ nom: 'defineCachedEventHandler : 304 avec If-Modified-Since', chemin: '/api/cache/gestionnaire', entetes: { 'if-modified-since': 'Wed, 01 Jan 2031 00:00:00 GMT' } },
	{ nom: 'defineCachedEventHandler expiré : ancienne réponse, rafraîchie en arrière-plan (swr)', chemin: '/api/cache/gestionnaire', attendre: 1500 },
	{ nom: 'defineCachedEventHandler : réponse rafraîchie', chemin: '/api/cache/gestionnaire' },
	{ nom: 'defineCachedEventHandler sans swr : premier appel', chemin: '/api/cache/sans-swr' },
	{ nom: 'defineCachedEventHandler sans swr expiré : nouvelle réponse tout de suite', chemin: '/api/cache/sans-swr', attendre: 1500 },
	{ nom: 'defineCachedFunction partagée par deux routes : première', chemin: '/api/cache/fonction-a' },
	{ nom: 'defineCachedFunction partagée par deux routes : seconde', chemin: '/api/cache/fonction-b' },
	{ nom: 'defineCachedEventHandler : une erreur n’est pas gardée (1)', chemin: '/api/cache/erreur', entetes: JSON_ACCEPTE },
	{ nom: 'defineCachedEventHandler : une erreur n’est pas gardée (2)', chemin: '/api/cache/erreur', entetes: JSON_ACCEPTE },

	// Chapitre 7 : middlewares de route et serveur, navigateTo, useCookie, error.vue, <head>
	{ nom: 'middleware nommé : navigateTo sans cookie, redirection 302', chemin: '/garde-fous/gardien', entetes: NAVIGATEUR },
	{ nom: 'middleware nommé : cookie présent, middleware global, useState', chemin: '/garde-fous/gardien', entetes: { ...NAVIGATEUR, cookie: 'gardien=aime' }, comparer: 'page' },
	{ nom: 'useCookie : écrire un cookie et en incrémenter un autre (set-cookie)', chemin: '/garde-fous/connexion?entrer=1', entetes: { ...NAVIGATEUR, cookie: 'visites=4' }, comparer: 'page' },
	{ nom: 'middleware en ligne : abortNavigation() donne la page d’erreur 404', chemin: '/garde-fous/en-ligne?bloquer=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'middleware en ligne : abortNavigation(createError) donne la page d’erreur 403', chemin: '/garde-fous/en-ligne?erreur=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'middleware en ligne : navigateTo avec redirectCode 301', chemin: '/garde-fous/en-ligne?ailleurs=1', entetes: NAVIGATEUR },
	{ nom: 'navigateTo dans le setup d’une page : redirection 301', chemin: '/garde-fous/deplace', entetes: NAVIGATEUR },
	{ nom: 'error.vue : createError fatal dans une page', chemin: '/garde-fous/erreur?fatale=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'error.vue : showError dans une page', chemin: '/garde-fous/erreur?montrer=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'error.vue : exception dans une page, 500', chemin: '/garde-fous/erreur?plante=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'error.vue : page inconnue, 404 avec data', chemin: '/garde-fous/inconnue', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'useHead et useSeoMeta : title, titleTemplate, meta, htmlAttrs, bodyAttrs', chemin: '/garde-fous/tete', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'server/middleware : 401 rendu par error.vue pour un navigateur', methode: 'PATCH', chemin: '/api/garde-fous/sentier', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'server/middleware : 401 en JSON', methode: 'PATCH', chemin: '/api/garde-fous/sentier', entetes: JSON_ACCEPTE },
	{ nom: 'server/middleware : jeton du gardien, event.context', methode: 'PATCH', chemin: '/api/garde-fous/sentier', entetes: { ...JSON_ACCEPTE, authorization: 'Bearer cabane-4271' } },
	{ nom: 'server/middleware : GET sans jeton', chemin: '/api/garde-fous/sentier', entetes: JSON_ACCEPTE },
	{ nom: 'cookies côté serveur : getCookie, parseCookies, setCookie', chemin: '/api/garde-fous/cookie', entetes: { cookie: 'gardien=aime; autre=1' } },
	{ nom: 'middleware : navigateTo sans return ne redirige pas', chemin: '/garde-fous/sans-return?oubli=1', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'app.head de nuxt.config : titleTemplate, lang, useSeoMeta', chemin: '/garde-fous/titre', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'createError sans throw dans une page : rien ne se passe', chemin: '/garde-fous/sans-throw', entetes: NAVIGATEUR, comparer: 'page' },
	{ nom: 'page inconnue en JSON', chemin: '/inconnu', entetes: JSON_ACCEPTE },
	{ nom: 'page inconnue vue par un navigateur', chemin: '/inconnu', entetes: NAVIGATEUR, comparer: 'statut' },
	{ nom: 'route d’API inconnue : 404 des pages', chemin: '/api/inexistant', entetes: JSON_ACCEPTE },
	{ nom: 'méthode non prévue : 404 des pages', methode: 'POST', chemin: '/api/ports/1', entetes: JSON_ACCEPTE },

	// Erreurs
	{ nom: 'createError 404 avec statusMessage', chemin: '/api/ports/2', entetes: JSON_ACCEPTE },
	{ nom: 'createError sous /api sans Accept', chemin: '/api/ports/2' },
	{ nom: 'createError avec message seul', chemin: '/api/message', entetes: JSON_ACCEPTE },
	{ nom: 'createError avec data', methode: 'POST', chemin: '/api/ports', entetes: { 'content-type': 'application/json', ...JSON_ACCEPTE }, corps: '{"nom":""}' },
	{ nom: 'statusMessage accentué', chemin: '/api/accent', entetes: JSON_ACCEPTE },
	{ nom: 'exception JavaScript non prévue', chemin: '/api/plante', entetes: JSON_ACCEPTE },
	{ nom: 'erreur hors /api demandée en JSON', chemin: '/panne', entetes: JSON_ACCEPTE },
	{ nom: 'erreur hors /api vue par un navigateur', chemin: '/panne', entetes: { accept: 'text/html' }, comparer: 'statut' },
	{ nom: 'erreur sous /api vue par un navigateur', chemin: '/api/ports/2', entetes: { accept: 'text/html,application/xhtml+xml' }, comparer: 'statut' },
];

/** Appels de `$fetch` tels qu'un test d'exercice les écrit (@nuxt/test-utils/e2e), comparés au vrai ofetch. */
export interface CasFetch {
	nom: string;
	chemin: string;
	options?: Record<string, unknown>;
}

export const CAS_FETCH: CasFetch[] = [
	{ nom: 'objet JSON', chemin: '/api/bonjour' },
	{ nom: 'texte', chemin: '/api/texte' },
	{ nom: 'réponse 204', chemin: '/api/nul' },
	{ nom: 'tableau', chemin: '/api/tableau' },
	{ nom: 'query en option', chemin: '/api/recherche', options: { query: { a: 1, b: ['x', 'y'], c: 'un deux' } } },
	{ nom: 'POST d’un objet (JSON)', chemin: '/api/ports', options: { method: 'POST', body: { nom: 'Le Guilvinec' } } },
	{ nom: 'POST refusé : FetchError 422 avec data', chemin: '/api/ports', options: { method: 'POST', body: {} } },
	{ nom: 'POST d’un texte brut', chemin: '/api/formulaire', options: { method: 'POST', body: 'du texte' } },
	{ nom: 'erreur 404 d’une route', chemin: '/api/ports/2' },
	{ nom: 'exception : 500', chemin: '/api/plante' },
	{ nom: 'erreur hors /api', chemin: '/panne' },
	{ nom: 'page HTML', chemin: '/sentiers/lac-noir' },
	{ nom: 'page inconnue', chemin: '/inconnu' },
	{ nom: 'en-tête personnalisé', chemin: '/api/entetes', options: { headers: { 'x-demande': 'sardines' } } },
	{ nom: 'responseType text sur du JSON', chemin: '/api/bonjour', options: { responseType: 'text' } },
	{ nom: 'redirection d’un middleware suivie', chemin: '/garde-fous/gardien' },
	{ nom: 'redirection non suivie (redirect: manual)', chemin: '/garde-fous/gardien', options: { redirect: 'manual' } },
	{ nom: 'cookie envoyé dans les en-têtes', chemin: '/garde-fous/gardien', options: { headers: { cookie: 'gardien=aime' } } },
];
