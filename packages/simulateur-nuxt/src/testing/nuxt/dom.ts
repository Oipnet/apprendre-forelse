/**
 * Globales du DOM pour l'environnement de test `nuxt` : portage de `populateGlobal` de Vitest 5
 * (vitest/runtime), qui recopie sur l'objet global les clés d'une fenêtre happy-dom. Ici, l'objet global
 * est aussi celui du simulateur (Web Worker du playground, ou Node pour content:check) : tout ce qui est
 * posé est retiré par `restore()` à la fin du fichier de test.
 */

/** LIVING_KEYS et OTHER_KEYS de Vitest. */
const KEYS = [
	'DOMException', 'EventTarget', 'NamedNodeMap', 'Node', 'Attr', 'Element', 'DocumentFragment',
	'DOMImplementation', 'Document', 'XMLDocument', 'CharacterData', 'Text', 'CDATASection',
	'ProcessingInstruction', 'Comment', 'DocumentType', 'NodeList', 'RadioNodeList', 'HTMLCollection',
	'HTMLOptionsCollection', 'DOMStringMap', 'DOMTokenList', 'StyleSheetList', 'HTMLElement', 'HTMLHeadElement',
	'HTMLTitleElement', 'HTMLBaseElement', 'HTMLLinkElement', 'HTMLMetaElement', 'HTMLStyleElement',
	'HTMLBodyElement', 'HTMLHeadingElement', 'HTMLParagraphElement', 'HTMLHRElement', 'HTMLPreElement',
	'HTMLUListElement', 'HTMLOListElement', 'HTMLLIElement', 'HTMLMenuElement', 'HTMLDListElement',
	'HTMLDivElement', 'HTMLAnchorElement', 'HTMLAreaElement', 'HTMLBRElement', 'HTMLButtonElement',
	'HTMLCanvasElement', 'HTMLDataElement', 'HTMLDataListElement', 'HTMLDetailsElement', 'HTMLDialogElement',
	'HTMLDirectoryElement', 'HTMLFieldSetElement', 'HTMLFontElement', 'HTMLFormElement', 'HTMLHtmlElement',
	'HTMLImageElement', 'HTMLInputElement', 'HTMLLabelElement', 'HTMLLegendElement', 'HTMLMapElement',
	'HTMLMarqueeElement', 'HTMLMediaElement', 'HTMLMeterElement', 'HTMLModElement', 'HTMLOptGroupElement',
	'HTMLOptionElement', 'HTMLOutputElement', 'HTMLPictureElement', 'HTMLProgressElement', 'HTMLQuoteElement',
	'HTMLScriptElement', 'HTMLSelectElement', 'HTMLSlotElement', 'HTMLSourceElement', 'HTMLSpanElement',
	'HTMLTableCaptionElement', 'HTMLTableCellElement', 'HTMLTableColElement', 'HTMLTableElement',
	'HTMLTimeElement', 'HTMLTableRowElement', 'HTMLTableSectionElement', 'HTMLTemplateElement',
	'HTMLTextAreaElement', 'HTMLUnknownElement', 'HTMLFrameElement', 'HTMLFrameSetElement', 'HTMLIFrameElement',
	'HTMLEmbedElement', 'HTMLObjectElement', 'HTMLParamElement', 'HTMLVideoElement', 'HTMLAudioElement',
	'HTMLTrackElement', 'HTMLFormControlsCollection', 'SVGElement', 'SVGGraphicsElement', 'SVGSVGElement',
	'SVGTitleElement', 'SVGAnimatedString', 'SVGNumber', 'SVGStringList', 'Event', 'CloseEvent', 'CustomEvent',
	'MessageEvent', 'ErrorEvent', 'HashChangeEvent', 'PopStateEvent', 'StorageEvent', 'ProgressEvent',
	'PageTransitionEvent', 'SubmitEvent', 'UIEvent', 'FocusEvent', 'InputEvent', 'MouseEvent', 'KeyboardEvent',
	'TouchEvent', 'CompositionEvent', 'WheelEvent', 'BarProp', 'External', 'Location', 'History', 'Screen',
	'Crypto', 'Performance', 'Navigator', 'PluginArray', 'MimeTypeArray', 'Plugin', 'MimeType', 'FileReader',
	'FormData', 'Blob', 'File', 'FileList', 'ValidityState', 'DOMParser', 'XMLSerializer',
	'XMLHttpRequestEventTarget', 'XMLHttpRequestUpload', 'XMLHttpRequest', 'WebSocket', 'NodeFilter',
	'NodeIterator', 'TreeWalker', 'AbstractRange', 'Range', 'StaticRange', 'Selection', 'Storage',
	'CustomElementRegistry', 'ShadowRoot', 'MutationObserver', 'MutationRecord', 'Uint8Array', 'Uint16Array',
	'Uint32Array', 'Uint8ClampedArray', 'Int8Array', 'Int16Array', 'Int32Array', 'Float32Array', 'Float64Array',
	'ArrayBuffer', 'DOMRectReadOnly', 'DOMRect', 'Image', 'Audio', 'Option', 'CSS', 'addEventListener', 'alert',
	'blur', 'cancelAnimationFrame', 'close', 'confirm', 'createPopup', 'dispatchEvent', 'document', 'focus',
	'frames', 'getComputedStyle', 'history', 'innerHeight', 'innerWidth', 'length', 'localStorage', 'location',
	'matchMedia', 'moveBy', 'moveTo', 'name', 'navigator', 'open', 'outerHeight', 'outerWidth', 'pageXOffset',
	'pageYOffset', 'parent', 'postMessage', 'print', 'prompt', 'removeEventListener', 'requestAnimationFrame',
	'resizeBy', 'resizeTo', 'screen', 'screenLeft', 'screenTop', 'screenX', 'screenY', 'scroll', 'scrollBy',
	'scrollLeft', 'scrollTo', 'scrollTop', 'scrollX', 'scrollY', 'self', 'sessionStorage', 'stop', 'top',
	'Window', 'window',
];

const SKIP_KEYS = ['window', 'self', 'top', 'parent'];

function isClassLikeName(name: string): boolean {
	return name[0] === name[0].toUpperCase();
}

/** Installe les clés de `win` sur `global` ; la fonction rendue remet chaque clé comme avant. */
export function populateGlobal(global: any, win: any, additionalKeys: string[] = []): () => void {
	const keysArray = [...additionalKeys, ...KEYS];
	const keys = new Set([...keysArray, ...Object.getOwnPropertyNames(win)].filter((key) => {
		if (SKIP_KEYS.includes(key)) return false;
		if (key in global) return keysArray.includes(key);
		return true;
	}));
	const overrideObject = new Map<string, unknown>();
	/** Descripteur propre d'avant (ou `undefined` : la clé venait du prototype, ou n'existait pas). */
	const originals = new Map<string, PropertyDescriptor | undefined>();
	const define = (key: string, descriptor: PropertyDescriptor) => {
		if (!originals.has(key)) originals.set(key, Object.getOwnPropertyDescriptor(global, key));
		Object.defineProperty(global, key, { configurable: true, ...descriptor });
	};
	for (const key of keys) {
		// Une clé que cette fenêtre ne fournit pas (happy-dom en donne moins dans un navigateur que sous
		// Node : `Uint8Array`, `ArrayBuffer`…) : celle de l'environnement reste en place.
		if (win[key] === undefined && key in global) continue;
		const boundFunction = typeof win[key] === 'function' && !isClassLikeName(key) && win[key].bind(win);
		define(key, {
			get() {
				if (overrideObject.has(key)) return overrideObject.get(key);
				if (boundFunction) return boundFunction;
				return win[key];
			},
			set(value) {
				overrideObject.set(key, value);
				win[key] = value;
			},
		});
	}
	// Dans un Web Worker, `self` n'a qu'un accesseur : defineProperty plutôt qu'une affectation.
	for (const key of SKIP_KEYS) define(key, { value: global, writable: true });
	if (global.document?.defaultView) {
		Object.defineProperty(global.document, 'defaultView', { get: () => global, enumerable: true, configurable: true });
	}
	return () => {
		for (const [key, descriptor] of [...originals].reverse()) {
			delete global[key];
			if (descriptor) Object.defineProperty(global, key, descriptor);
		}
	};
}
