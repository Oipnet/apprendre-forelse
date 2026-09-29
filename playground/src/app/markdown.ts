import DOMPurify from 'dompurify';
import { marked } from 'marked';

/** Le contenu vient d'un pack : on le nettoie avant de l'injecter dans la plateforme. */
export const markdown = (source: string, inline = false) => DOMPurify.sanitize(inline ? (marked.parseInline(source) as string) : (marked.parse(source) as string));

/** Du HTML déjà rendu par la plateforme (consigne) : nettoyé quand même, par prudence, avant d'être injecté. */
export const sanitize = (html: string) => DOMPurify.sanitize(html);
