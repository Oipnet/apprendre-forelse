export default defineNuxtConfig({
	compatibilityDate: '2026-09-01',
	devtools: { enabled: false },
	telemetry: false,
	runtimeConfig: {
		cleMeteo: 'cle-par-defaut',
		amont: 'http://amont.invalide',
		public: {
			nomDuPort: 'Port-Bigorneau',
			amont: '',
		},
	},
})
