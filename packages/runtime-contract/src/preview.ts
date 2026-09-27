/**
 * Ce que la page de l'aperçu (dans l'iframe du bac à sable) envoie au relais, sur le modèle de PING :
 * ses avertissements et erreurs, que le relais transmet à la console du playground. L'apprenant n'a pas
 * les outils de développement de l'iframe sous la main.
 */
export const PREVIEW_CONSOLE = 'forelse:preview-console';

export interface PreviewConsoleMessage {
	type: typeof PREVIEW_CONSOLE;
	level: 'warn' | 'error';
	message: string;
}
