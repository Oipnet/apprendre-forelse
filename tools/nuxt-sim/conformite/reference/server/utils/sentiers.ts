import sentiers from '../data/sentiers.json'

export function trouverSentier(slug: string) {
	return sentiers.find((sentier) => sentier.slug === slug)
}

export const NOMBRE_DE_SENTIERS = sentiers.length
