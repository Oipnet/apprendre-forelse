// Script d'exemple : il s'ajoute au JavaScript du moteur, sur les pages du site seulement (pas sur la page
// d'exercice ni dans l'atelier). Sans lui, la page est complète : il n'apporte que du mouvement.
(() => {
	// Qui a demandé moins de mouvement n'en a pas : ni la marée, ni l'objectif qui se coche.
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}
	document.documentElement.classList.add('bigorneau-motion');

	// Le dernier objectif de l'illustration se valide sous les yeux du visiteur (data-demo-goal, posé par le moteur).
	const goal = document.querySelector('[data-demo-goal]');
	const mark = goal?.querySelector('i');
	if (goal && mark) {
		setTimeout(() => {
			goal.classList.add('is-done');
			mark.textContent = '✓';
		}, 1500);
	}
})();
