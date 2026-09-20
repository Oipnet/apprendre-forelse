export default defineNuxtConfig({
	compatibilityDate: '2026-09-01',
	devtools: { enabled: false },
	telemetry: false,
	app: {
		head: { titleTemplate: '%s · Référence', htmlAttrs: { lang: 'fr' } },
	},
	runtimeConfig: {
		cleMeteo: 'cle-par-defaut',
		amont: 'http://amont.invalide',
		public: {
			nomDuPort: 'Port-Bigorneau',
			amont: '',
		},
	},
})
