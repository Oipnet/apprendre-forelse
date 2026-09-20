/**
 * Ce que le bundler fournit à ce paquet.
 *
 * Ces déclarations vivaient dans le playground du temps où il connaissait Nuxt. Elles appartiennent au
 * paquet : c'est lui qui produit ces modules (voir src/node/vite.ts), et lui qui les consomme.
 */

/** Un worker importé comme URL : `import url from './x.ts?worker&url'` (Vite). */
declare module '*?worker&url' {
	const url: string;
	export default url;
}

/** Module navigateur du simulateur (Vue, vue-router, runtime de Nuxt), livré au worker comme une chaîne. */
declare module 'virtual:nuxt-sim-client' {
	const code: string;
	export default code;
}

/** Runtime de l'environnement de test des composants, évalué à neuf pour chaque fichier de test. */
declare module 'virtual:nuxt-sim-test-runtime' {
	const code: string;
	export default code;
}
