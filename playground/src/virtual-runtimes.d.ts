declare module 'virtual:forelse-runtimes' {
	import type { RuntimeManifest } from '@forelse/runtime-contract';
	/** Les manifestes des paquets de runtime installés (voir discover-runtimes.ts). */
	const manifests: RuntimeManifest[];
	export default manifests;
}
