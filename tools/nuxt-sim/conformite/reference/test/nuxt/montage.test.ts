import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import { createError, readBody } from 'h3'
import Fiche from '~/components/carnet/Fiche.vue'
import Reservation from '~/components/carnet/Reservation.vue'
import Unite from '~/components/carnet/Unite.vue'
import SentierCarte from '~/components/SentierCarte.vue'

describe('mountSuspended', () => {
	it('rend un composant avec ses props', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		expect(carte.html()).toBe('<article class="carte">\n  <h2>Le lac Noir</h2>\n  <p>1200 m · Difficile</p>\n</article>')
		expect(carte.find('h2').text()).toBe('Le lac Noir')
		expect(carte.props()).toEqual({ nom: 'Le lac Noir', denivele: 1200 })
	})

	it('change les props', async () => {
		const carte = await mountSuspended(SentierCarte, { props: { nom: 'Le lac Noir', denivele: 1200 } })
		await carte.setProps({ denivele: 300 })
		expect(carte.find('p').text()).toBe('300 m · Facile')
	})

	it('sans registerEndpoint, useFetch reçoit un 404', async () => {
		const fiche = await mountSuspended(Fiche, { props: { nom: 'Lac' } })
		expect(fiche.find('.meteo').text()).toBe('erreur 404')
		expect(fiche.find('.route').text()).toBe('/')
	})

	it('avec registerEndpoint, la route option et un clic', async () => {
		registerEndpoint('/api/carnet/meteo', () => ({ ciel: 'beau' }))
		const fiche = await mountSuspended(Fiche, { props: { nom: 'Lac' }, route: '/sentiers' })
		expect(fiche.find('.meteo').text()).toBe('beau')
		expect(fiche.find('.route').text()).toBe('/sentiers')
		await fiche.find('button').trigger('click')
		expect(fiche.find('button').text()).toBe('choisir 1')
		expect(fiche.emitted('choisi')).toEqual([['Lac']])
		expect(fiche.emitted()).toHaveProperty('choisi')
	})

	it('le composant garde son état : vm et setupState', async () => {
		const fiche = await mountSuspended(Fiche, { props: { nom: 'Lac' } })
		expect(fiche.vm.compte).toBe(0)
		await fiche.find('button').trigger('click')
		expect((fiche.vm as any).compte).toBe(1)
	})

	it('app.config, runtimeConfig, NuxtLink, useState et composants auto-importés', async () => {
		registerEndpoint('/api/carnet/meteo', () => ({ ciel: 'nuageux' }))
		const unite = await mountSuspended(Unite)
		expect(unite.find('.unite').text()).toBe('2350 m · Port-Bigorneau')
		expect(unite.find('a').attributes('href')).toBe('/sentiers')
		expect(unite.find('h2').text()).toBe('Enfant')
		// La donnée de useFetch est gardée d'un montage à l'autre (même clé) : pas de nouvelle requête.
		expect(unite.find('.meteo').text()).toBe('beau')
		useState('carnet-unite').value = 'ft'
		await nextTick()
		expect(unite.find('.unite').text()).toBe('2350 ft · Port-Bigorneau')
	})

	it('l’état reste d’un montage à l’autre dans le même fichier', async () => {
		const unite = await mountSuspended(Unite)
		expect(unite.find('.unite').text()).toBe('2350 ft · Port-Bigorneau')
	})
})

describe('le formulaire de réservation', () => {
	it('poste, affiche la confirmation et rafraîchit l’état', async () => {
		let libres = 12
		registerEndpoint('/api/carnet/etat', () => ({ libres }))
		const recu: unknown[] = []
		registerEndpoint('/api/carnet/reservations', {
			method: 'POST',
			handler: async (event) => {
				const corps = await readBody(event)
				recu.push(corps)
				libres -= corps.couchettes
				return { numero: 42 }
			},
		})
		const formulaire = await mountSuspended(Reservation)
		expect(formulaire.find('.etat').text()).toBe('12 couchettes libres')
		await formulaire.find('input[name="nom"]').setValue('Aimé')
		await formulaire.find('input[name="couchettes"]').setValue('3')
		await formulaire.find('form').trigger('submit')
		await vi.waitFor(() => expect(formulaire.find('.message').text()).toBe('Réservation n° 42'))
		expect(recu).toEqual([{ nom: 'Aimé', couchettes: 3 }])
		expect(formulaire.emitted('reservee')).toEqual([[42]])
		await vi.waitFor(() => expect(formulaire.find('.etat').text()).toBe('9 couchettes libres'))
	})

	it('affiche les erreurs 422 champ par champ', async () => {
		registerEndpoint('/api/carnet/etat', () => ({ libres: 2 }))
		registerEndpoint('/api/carnet/reservations', {
			method: 'POST',
			handler: async () => {
				throw createError({ statusCode: 422, statusMessage: 'Unprocessable Entity', data: { nom: 'Le nom est obligatoire.', couchettes: 'Plus que 2 couchettes.' } })
			},
		})
		const formulaire = await mountSuspended(Reservation)
		await formulaire.find('form').trigger('submit')
		await vi.waitFor(() => expect(formulaire.find('.erreur-nom').text()).toBe('Le nom est obligatoire.'))
		expect(formulaire.find('.erreur-couchettes').text()).toBe('Plus que 2 couchettes.')
		expect(formulaire.find('.message').exists()).toBe(false)
		expect(formulaire.emitted('reservee')).toBeUndefined()
	})

	it('affiche le message d’une autre erreur', async () => {
		registerEndpoint('/api/carnet/reservations', { method: 'POST', handler: () => { throw new Error('panne') } })
		const formulaire = await mountSuspended(Reservation)
		await formulaire.find('form').trigger('submit')
		await flushPromises()
		await vi.waitFor(() => expect(formulaire.find('.message').text()).toBe('[POST] "/api/carnet/reservations": 500'))
	})
})
