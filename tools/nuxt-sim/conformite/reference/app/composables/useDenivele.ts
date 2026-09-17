export function useDenivele(metres: number) {
	const unite = useState<'m' | 'ft'>('unite', () => 'm')
	return computed(() => (unite.value === 'm' ? `${metres} m` : `${Math.round(metres * 3.28084)} ft`))
}

export const PAS_PAR_METRE = 1.3
