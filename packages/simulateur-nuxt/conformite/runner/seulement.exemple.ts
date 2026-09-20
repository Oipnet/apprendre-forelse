import { describe, expect, it } from 'vitest'

it('hors du only', () => { expect(1).toBe(1) })

describe.only('la suite choisie', () => {
	it('tourne', () => { expect(1).toBe(1) })
	it.skip('reste ignoré', () => { expect(1).toBe(2) })
})

describe('une autre suite', () => {
	it.only('tourne aussi', () => { expect(2).toBe(2) })
	it('est ignoré', () => { expect(1).toBe(1) })
})
