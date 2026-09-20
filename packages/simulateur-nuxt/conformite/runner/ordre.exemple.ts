import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const journal: string[] = []

beforeAll(() => { journal.push('beforeAll racine') })
beforeEach(() => {
	journal.push('beforeEach racine')
	return () => journal.push('nettoyage racine')
})
afterEach(() => { journal.push('afterEach racine') })

describe('le refuge', () => {
	beforeAll(() => { journal.push('beforeAll refuge') })
	beforeEach(() => { journal.push('beforeEach refuge') })
	afterEach(() => { journal.push('afterEach refuge') })
	afterAll(() => { journal.push('afterAll refuge') })

	it('ouvre ses portes', () => {
		expect(journal).toEqual(['beforeAll racine', 'beforeAll refuge', 'beforeEach racine', 'beforeEach refuge'])
	})

	describe('le dortoir', () => {
		it('garde l’ordre des hooks', () => {
			expect(journal.slice(4)).toEqual(['afterEach refuge', 'afterEach racine', 'nettoyage racine', 'beforeEach racine', 'beforeEach refuge'])
		})
	})
})

describe('assertions', () => {
	it('toBe réussi', () => { expect(2350).toBe(2350) })
	it('toBe raté', () => { expect(3012).toBe(2350) })
	it('toEqual raté sur un objet', () => { expect({ nom: 'lac Noir', denivele: 450 }).toEqual({ nom: 'lac Noir', denivele: 500 }) })
	it('toContain raté', () => { expect(['bar', 'lieu']).toContain('sole') })
	it('toMatchObject réussi', () => { expect({ id: 1, nom: 'col', tags: ['moyen'] }).toMatchObject({ nom: 'col' }) })
	it('toThrow réussi', () => { expect(() => { throw new Error('névé') }).toThrow('névé') })
	it('toHaveLength raté', () => { expect('refuge').toHaveLength(3) })
	it('not.toBeNull raté', () => { expect(null).not.toBeNull() })
	it('resolves réussi', async () => { await expect(Promise.resolve(32)).resolves.toBe(32) })
	it('rejects raté', async () => { await expect(Promise.resolve(32)).rejects.toThrow() })
	it('toHaveBeenCalledWith raté', () => {
		const signaler = vi.fn()
		signaler('brèche du Bouc')
		expect(signaler).toHaveBeenCalledWith('arête')
	})
	it('expect.any réussi', () => { expect({ couchettes: 32 }).toEqual({ couchettes: expect.any(Number) }) })
	it('erreur JavaScript', () => { (undefined as any).couchettes })
	it('expect.assertions raté', () => {
		expect.assertions(2)
		expect(true).toBe(true)
	})
	it('délai dépassé', async () => { await new Promise((resolve) => setTimeout(resolve, 200)) }, 20)
	it.skip('ignoré', () => { expect(1).toBe(2) })
	it.todo('à écrire')
	it.each([[450, 'facile'], [1200, 'difficile']])('dénivelé %i : %s', (denivele, niveau) => {
		expect(denivele < 1000 ? 'facile' : 'difficile').toBe(niveau)
	})
	it.each([{ nom: 'lac Noir', altitude: 2100 }])('altitude de $nom', ({ altitude }) => { expect(altitude).toBeGreaterThan(3000) })
})

describe('describe asynchrone', async () => {
	await new Promise((resolve) => setTimeout(resolve, 5))
	it('est collecté après son await', () => { expect(true).toBe(true) })
})

describe.skip('suite ignorée', () => {
	it('ne tourne pas', () => { expect(1).toBe(2) })
})

describe('beforeAll qui échoue', () => {
	beforeAll(() => { throw new Error('la benne est en panne') })
	it('ne peut pas tourner', () => { expect(1).toBe(1) })
})
