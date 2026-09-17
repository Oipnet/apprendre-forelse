import * as monaco from 'monaco-editor/editor/editor.api';
import 'monaco-editor/features/register.all';
import 'monaco-editor/languages/definitions/php/register';
import 'monaco-editor/languages/definitions/twig/register';
import 'monaco-editor/languages/definitions/yaml/register';
import 'monaco-editor/languages/definitions/css/register';
import 'monaco-editor/languages/definitions/markdown/register';
import 'monaco-editor/languages/definitions/dockerfile/register';
import 'monaco-editor/languages/definitions/shell/register';
import 'monaco-editor/languages/definitions/ini/register';
import 'monaco-editor/languages/definitions/html/register';
import 'monaco-editor/languages/definitions/javascript/register';
import 'monaco-editor/languages/definitions/typescript/register';
import editorWorkerUrl from 'monaco-editor/editor/editor.worker?worker&url';
import { createModuleWorker } from '../util/worker';

self.MonacoEnvironment = { getWorker: () => createModuleWorker(editorWorkerUrl, 'monaco') };

export { monaco };

const LANGUAGES: Record<string, string> = { php: 'php', twig: 'twig', yaml: 'yaml', yml: 'yaml', css: 'css', md: 'markdown', sh: 'shell', ini: 'ini', html: 'html', vue: 'html', js: 'javascript', mjs: 'javascript', ts: 'typescript', json: 'json', conf: 'shell', dockerfile: 'dockerfile' };

export function languageOf(path: string): string {
	if (path.endsWith('.html.twig')) return 'twig';
	const name = path.split('/').pop() ?? '';
	// Dockerfile, Dockerfile.prod, prod.Dockerfile ; .env, .env.local ; .dockerignore.
	if (/^Dockerfile(\.|$)|\.Dockerfile$/i.test(name)) return 'dockerfile';
	if (/^\.env(\.|$)/.test(name)) return 'ini';
	if (name === '.dockerignore' || name === '.gitignore') return 'shell';
	return LANGUAGES[name.includes('.') ? (name.split('.').pop() ?? '') : ''] ?? 'plaintext';
}

export const uriOf = (path: string) => monaco.Uri.parse(`file:///app/${path}`);
export const pathOf = (uri: monaco.Uri) => uri.path.replace(/^\/app\//, '');

interface OpenFile {
	path: string;
	readonly: boolean;
	model: monaco.editor.ITextModel;
	tab: HTMLButtonElement;
}

/** Éditeur multi-fichiers : barre d'onglets + une instance Monaco. */
export class EditorPanel {
	private readonly editor: monaco.editor.IStandaloneCodeEditor;
	private readonly files = new Map<string, OpenFile>();
	private readonly viewStates = new Map<string, monaco.editor.ICodeEditorViewState | null>();
	private current?: OpenFile;

	constructor(
		private readonly tabs: HTMLElement,
		container: HTMLElement,
		private readonly onChange: (path: string, content: string) => void,
	) {
		this.editor = monaco.editor.create(container, {
			theme: 'vs-dark',
			automaticLayout: true,
			fontSize: 14,
			minimap: { enabled: false },
			scrollBeyondLastLine: false,
			tabSize: 4,
			fixedOverflowWidgets: true,
			suggest: { showWords: false },
		});
	}

	/** `label` remplace le nom de fichier sur l'onglet (ex. la solution, à côté du fichier de l'apprenant). */
	addFile(path: string, content: string, readonly = false, label?: string) {
		const model = monaco.editor.createModel(content, languageOf(path), uriOf(path));
		model.onDidChangeContent(() => this.onChange(path, model.getValue()));
		const tab = document.createElement('button');
		tab.className = 'tab';
		tab.textContent = label ?? path.split('/').pop()!;
		tab.title = path + (readonly ? ' (lecture seule)' : '');
		if (readonly) tab.classList.add('readonly');
		tab.addEventListener('click', () => this.open(path));
		this.tabs.append(tab);
		this.files.set(path, { path, readonly, model, tab });
	}

	/** Le fichier affiché, s'il y en a un. */
	currentPath(): string | undefined {
		return this.current?.path;
	}

	/** Ferme un fichier et oublie son modèle (l'atelier peut supprimer un fichier). */
	removeFile(path: string) {
		const file = this.files.get(path);
		if (!file) return;
		file.tab.remove();
		file.model.dispose();
		this.files.delete(path);
		this.viewStates.delete(path);
		if (this.current === file) this.current = undefined;
	}

	has(path: string): boolean {
		return this.files.has(path);
	}

	open(path: string) {
		const file = this.files.get(path);
		if (!file) return;
		if (this.current) this.viewStates.set(this.current.path, this.editor.saveViewState());
		this.current?.tab.classList.remove('active');
		this.current = file;
		file.tab.classList.add('active');
		this.editor.setModel(file.model);
		this.editor.updateOptions({ readOnly: file.readonly, readOnlyMessage: { value: 'Ce fichier est fourni par l\'exercice et ne peut pas être modifié.' } });
		const state = this.viewStates.get(path);
		if (state) this.editor.restoreViewState(state);
		this.editor.focus();
	}

	get instance() {
		return this.editor;
	}

	/** Place le curseur sur une ligne du fichier affiché. */
	goToLine(line: number) {
		this.editor.revealLineInCenter(line);
		this.editor.setPosition({ lineNumber: line, column: 1 });
		this.editor.focus();
	}

	/** Remplace le contenu (réinitialisation, solution) : déclenche onChange. */
	setContent(path: string, content: string) {
		this.files.get(path)?.model.setValue(content);
	}
}
