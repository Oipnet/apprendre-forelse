/**
 * Fichiers modifiables d'un exercice. Une entrée peut être un motif (`migrations/*.php`) :
 * il couvre aussi les fichiers qu'une commande console va créer. `*` ne franchit pas un « / »,
 * comme côté plateforme (Exercise::isEditable).
 */
import type { FrameworkProfile } from './types.ts';

const motifs = new Map<string, RegExp>();

function enExpression(motif: string): RegExp {
	let expression = motifs.get(motif);
	if (!expression) {
		const source = motif
			.split('*')
			.map((morceau) => morceau.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
			.join('[^/]*');
		expression = new RegExp(`^${source}$`);
		motifs.set(motif, expression);
	}
	return expression;
}

export function estModifiable(chemin: string, editable: string[]): boolean {
	return editable.some((entree) => entree === chemin || (entree.includes('*') && enExpression(entree).test(chemin)));
}

/** Les fichiers modifiables nommés explicitement (sans motif). */
export function cheminsExplicites(editable: string[]): string[] {
	return editable.filter((entree) => !entree.includes('*'));
}

/**
 * Le contenu d'un fichier créé par l'apprenant : de quoi démarrer sans se tromper de namespace.
 * src/Entity/Soiree.php donne « namespace App\Entity; class Soiree », un template Twig hérite de la base.
 */
export function contenuDeDepart(chemin: string, framework: FrameworkProfile): string {
	const nom = chemin.split('/').pop()!;
	if (chemin.endsWith('.php')) {
		const dossiers = chemin.split('/').slice(0, -1);
		const classe = nom.slice(0, -'.php'.length);
		// Les racines de namespace sont déclarées par le moteur, une fois, par framework.
		const racine = framework.namespaceRoots[dossiers[0] ?? ''];
		if (!racine || !/^[A-Za-z_]\w*$/.test(classe)) return '<?php\n\n';
		const namespace = [racine, ...dossiers.slice(1)].join('\\');
		return `<?php\n\nnamespace ${namespace};\n\nclass ${classe}\n{\n}\n`;
	}
	if (nom.endsWith('.html.twig')) return "{% extends 'base.html.twig' %}\n\n{% block title %}{% endblock %}\n\n{% block body %}\n{% endblock %}\n";
	return '';
}
