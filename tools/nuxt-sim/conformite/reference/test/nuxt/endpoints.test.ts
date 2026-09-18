import { describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

describe('registerEndpoint', () => {
	it('un chemin inconnu : le 404 de h3', async () => {
		const reponse = await fetch('/api/rien')
		expect(reponse.status).toBe(404)
		expect(reponse.headers.get('content-type')).toBe('application/json')
		expect(await reponse.text()).toBe('{\n  "statusCode": 404,\n  "statusMessage": "Cannot find any path matching /api/rien.",\n  "stack": []\n}')
		const erreur = await $fetch('/api/rien').catch((e) => e)
		expect(erreur.message).toBe('[GET] "/api/rien": 404 Cannot find any path matching /api/rien.')
		expect(erreur.statusCode).toBe(404)
	})

	it('une route par méthode, et le corps de la requête', async () => {
		registerEndpoint('/api/post', { method: 'POST', handler: async (event) => ({ methode: event.method, chemin: event.path }) })
		expect(await $fetch('/api/post', { method: 'POST', body: { a: 1 } })).toEqual({ methode: 'POST', chemin: '/_/api/post' })
		const erreur = await $fetch('/api/post').catch((e) => e)
		expect(erreur.message).toBe('[GET] "/api/post": 404 Cannot find any path matching /_/api/post.')
	})

	it('once, la dernière enregistrée, et le retrait', async () => {
		const retirer = registerEndpoint('/api/compte', () => 'premier')
		registerEndpoint('/api/compte', { handler: () => 'une fois', once: true })
		expect(await $fetch('/api/compte')).toBe('une fois')
		expect(await $fetch('/api/compte')).toBe('premier')
		retirer()
		expect((await fetch('/api/compte')).status).toBe(404)
	})

	it('la query et les réponses', async () => {
		registerEndpoint('/api/query', async (event) => {
			const { getQuery, setResponseStatus } = await import('h3')
			setResponseStatus(event, 201)
			return getQuery(event)
		})
		const reponse = await fetch('/api/query?x=1&y=2')
		expect(reponse.status).toBe(201)
		expect(await reponse.text()).toBe('{"x":"1","y":"2"}')
		registerEndpoint('/api/vide', () => null)
		expect((await fetch('/api/vide')).status).toBe(204)
	})

	it('une erreur levée', async () => {
		registerEndpoint('/api/erreur', async () => {
			const { createError } = await import('h3')
			throw createError({ statusCode: 409, statusMessage: 'Conflict', message: 'Complet', data: { reste: 0 } })
		})
		const reponse = await fetch('/api/erreur')
		expect(reponse.status).toBe(409)
		expect(await reponse.json()).toEqual({ statusCode: 409, statusMessage: 'Conflict', stack: [], data: { reste: 0 } })
	})
})
