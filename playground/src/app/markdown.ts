import DOMPurify from 'dompurify';
import { marked } from 'marked';

/** Le contenu vient d'un pack : on le nettoie avant de l'injecter dans la plateforme. */
export const markdown = (source: string, inline = false) => DOMPurify.sanitize(inline ? (marked.parseInline(source) as string) : (marked.parse(source) as string));
