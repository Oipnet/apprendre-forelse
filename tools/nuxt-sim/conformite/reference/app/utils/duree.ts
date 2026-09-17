export function formatDuree(minutes: number): string {
	return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`
}
