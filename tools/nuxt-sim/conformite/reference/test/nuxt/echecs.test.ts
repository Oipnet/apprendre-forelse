import { describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import Fiche from '~/components/carnet/Fiche.vue'
import SentierCarte from '~/components/SentierCarte.vue'

describe('échecs', () => {
	it('texte attendu', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		expect(carte.find('h2').text()).toBe('Le lac Blanc')
	})

	it('élément absent', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		expect(carte.find('.absent').text()).toBe('x')
	})

	it('exists', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		expect(carte.find('.absent').exists()).toBe(true)
	})

	it('toContain sur le HTML', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		expect(carte.html()).toContain('Blanc')
	})

	it('emitted', async () => {
		registerEndpoint('/api/carnet/meteo', () => ({ ciel: 'beau' }))
		const fiche = await mountSuspended(Fiche, { props: { nom: 'Lac' } })
		expect(fiche.emitted('choisi')).toEqual([['Lac']])
	})

	it('une erreur dans le setup', async () => {
		await mountSuspended({ setup() { throw new Error('setup cassé') } })
	})

	it('une erreur de Nuxt dans le setup', async () => {
		await mountSuspended({ setup() { throw createError({ statusCode: 404, statusMessage: 'Absent' }) } })
	})

	it('$fetch vers une route serveur du projet', async () => {
		expect(await $fetch('/api/bonjour')).toBe('Bonjour')
	})
})
