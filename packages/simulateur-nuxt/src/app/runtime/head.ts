/**
 * `<head>` de la page : les composables de nuxt/dist/head/runtime/composables.js, sur le vrai unhead
 * (@unhead/vue 3.4, options de Nuxt 4 : `disableDefaults` et `legacyPlugins`). Côté serveur, la tête
 * vient du contexte de rendu ; dans le navigateur, de l'application Vue.
 */
import { headSymbol, useHead as useHeadBase, useHeadSafe as useHeadSafeBase, useSeoMeta as useSeoMetaBase, useServerHead as useServerHeadBase, useServerSeoMeta as useServerSeoMetaBase } from '@unhead/vue';
import { hasInjectionContext, inject } from 'vue';
import { runWithContext, useNuxtApp, type NuxtApp } from './nuxt.ts';

type HeadOptions = { head?: any; nuxt?: NuxtApp } & Record<string, unknown>;

export function injectHead(nuxtApp?: NuxtApp): any {
	const nuxt = nuxtApp || useNuxtApp();
	return nuxt.ssrContext?.head || runWithContext(nuxt, () => {
		if (hasInjectionContext()) {
			const head = inject(headSymbol);
			if (!head) throw new Error('[NUXT_E6001] Nuxt head instance not found in the current context.');
			return head;
		}
	});
}

export function useHead(input: any, options: HeadOptions = {}) {
	return useHeadBase(input, { head: options.head || injectHead(options.nuxt), ...options } as never);
}

export function useHeadSafe(input: any, options: HeadOptions = {}) {
	return useHeadSafeBase(input, { head: options.head || injectHead(options.nuxt), ...options } as never);
}

export function useSeoMeta(input: any, options: HeadOptions = {}) {
	return useSeoMetaBase(input, { head: options.head || injectHead(options.nuxt), ...options } as never);
}

export function useServerHead(input: any, options: HeadOptions = {}) {
	return useServerHeadBase(input, { head: options.head || injectHead(options.nuxt), ...options } as never);
}

export function useServerSeoMeta(input: any, options: HeadOptions = {}) {
	return useServerSeoMetaBase(input, { head: options.head || injectHead(options.nuxt), ...options } as never);
}

/** `app.head` de nuxt.config, complété comme le fait le schéma de Nuxt (charset et viewport en tête). */
export function resolveAppHead(input: Record<string, any> | undefined): Record<string, any> {
	const resolved: Record<string, any> = { ...(input && typeof input === 'object' ? input : {}) };
	for (const key of ['meta', 'link', 'style', 'script', 'noscript']) resolved[key] = [...(resolved[key] ?? [])];
	if (!resolved.meta.find((m: any) => m?.charset)?.charset) resolved.meta.unshift({ charset: resolved.charset || 'utf-8' });
	if (!resolved.meta.find((m: any) => m?.name === 'viewport')?.content) resolved.meta.unshift({ name: 'viewport', content: resolved.viewport || 'width=device-width, initial-scale=1' });
	for (const key of ['meta', 'link', 'style', 'script', 'noscript']) resolved[key] = resolved[key].filter(Boolean);
	return resolved;
}
