/** Échappe les cinq caractères significatifs en HTML, texte comme valeur d'attribut. */
export const escapeHtml = (s: string): string => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
