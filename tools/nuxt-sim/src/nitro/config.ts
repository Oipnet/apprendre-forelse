/**
 * `runtimeConfig` tel que le voit `useRuntimeConfig()` côté serveur : les valeurs de nuxt.config,
 * complétées par Nuxt (app, nitro) et remplacées par les variables NUXT_… (applyEnv de Nitro).
 */
import { destr } from '../unjs/destr.ts';
import { snakeCase } from '../unjs/scule.ts';

export type RuntimeConfig = Record<string, any> & { public: Record<string, any>; app: Record<string, any> };

export function buildRuntimeConfig(userConfig: Record<string, any> | undefined, env: Record<string, string | undefined>): RuntimeConfig {
	const config: RuntimeConfig = {
		app: { baseURL: '/', buildAssetsDir: '/_nuxt/', cdnURL: '' },
		nitro: { envPrefix: 'NUXT_' },
		...structuredClone(userConfig ?? {}),
		public: structuredClone(userConfig?.public ?? {}),
	};
	return applyEnv(config, { prefix: 'NITRO_', altPrefix: 'NUXT_', env }) as RuntimeConfig;
}

interface EnvOptions {
	prefix: string;
	altPrefix: string;
	env: Record<string, string | undefined>;
}

function getEnv(key: string, opts: EnvOptions): unknown {
	const envKey = snakeCase(key).toUpperCase();
	return destr(opts.env[opts.prefix + envKey] ?? opts.env[opts.altPrefix + envKey]);
}

function isObject(input: unknown): input is Record<string, any> {
	return typeof input === 'object' && !Array.isArray(input);
}

function applyEnv(obj: Record<string, any>, opts: EnvOptions, parentKey = ''): Record<string, any> {
	for (const key in obj) {
		const subKey = parentKey ? `${parentKey}_${key}` : key;
		const envValue = getEnv(subKey, opts);
		if (isObject(obj[key])) {
			if (isObject(envValue)) {
				obj[key] = { ...obj[key], ...envValue };
				applyEnv(obj[key], opts, subKey);
			} else if (envValue === undefined) {
				applyEnv(obj[key], opts, subKey);
			} else {
				obj[key] = envValue ?? obj[key];
			}
		} else {
			obj[key] = envValue ?? obj[key];
		}
	}
	return obj;
}
