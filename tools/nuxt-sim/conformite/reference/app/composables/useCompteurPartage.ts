// Déclaré au chargement du module : partagé par toutes les requêtes du serveur.
const visites = ref(0)

export function useCompteurPartage() {
	return visites
}
