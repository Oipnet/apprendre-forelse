/**
 * Le runtime tel que la page le voit pendant que PHP démarre : chaque appel attend `pret`, puis part. L'éditeur, la
 * session et l'explorateur se montent sans attendre le runtime ; une frappe faite pendant le chargement est écrite dans
 * PHP dès qu'il est là, après le projet de départ. Si `pret` échoue, chaque appel échoue avec lui.
 */
export function apresDemarrage<T extends object>(runtime: T, pret: Promise<unknown>): T {
	return new Proxy(runtime, {
		get(cible, nom) {
			const valeur: unknown = Reflect.get(cible, nom);
			if (typeof valeur !== 'function') return valeur;
			return (...args: unknown[]) => pret.then(() => (valeur as (...a: unknown[]) => unknown).apply(cible, args));
		},
	});
}
