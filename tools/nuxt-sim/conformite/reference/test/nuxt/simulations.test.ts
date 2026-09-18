import { describe, expect, it, vi } from 'vitest'
import { mockComponent, mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import Fiche from '~/components/carnet/Fiche.vue'
import Unite from '~/components/carnet/Unite.vue'

const { routeSimulee } = vi.hoisted(() => ({ routeSimulee: { path: '/simulee' } }))

mockNuxtImport('useRoute', () => vi.fn(() => routeSimulee))
mockNuxtImport('useCarnetUnite', (original) => vi.fn(original))
mockComponent('CarnetFiche', async () => {
	const { defineComponent, h } = await import('vue')
	return defineComponent({ props: { nom: String }, setup: (props) => () => h('p', { class: 'fiche-simulee' }, props.nom) })
})

describe('mockNuxtImport et mockComponent', () => {
	it('la racine de l’application de test n’utilise pas la simulation', () => {
		expect(vi.mocked(useRoute).mock.calls.length).toBe(0)
	})

	it('une fonction de Nuxt simulée', async () => {
		const chemin = await mountSuspended({ setup: () => { const route = useRoute(); return () => h('p', route.path) } })
		expect(chemin.text()).toBe('/simulee')
		expect(vi.mocked(useRoute).mock.calls.length).toBe(2)
	})

	it('mockComponent remplace aussi l’import direct du composant', async () => {
		const fiche = await mountSuspended(Fiche, { props: { nom: 'Lac' } })
		expect(fiche.html()).toBe('<p class="fiche-simulee">Lac</p>')
	})

	it('un composable du projet, avec l’original', async () => {
		const unite = await mountSuspended(Unite)
		expect(unite.find('.unite').text()).toBe('2350 m · Port-Bigorneau')
		expect(vi.mocked(useCarnetUnite)).toHaveBeenCalledTimes(1)
		vi.mocked(useCarnetUnite).mockReturnValue(ref('pieds'))
		const autre = await mountSuspended(Unite)
		expect(autre.find('.unite').text()).toBe('2350 pieds · Port-Bigorneau')
	})

	it('un composant simulé', async () => {
		const unite = await mountSuspended(Unite)
		expect(unite.find('.fiche-simulee').text()).toBe('Enfant')
	})
})
