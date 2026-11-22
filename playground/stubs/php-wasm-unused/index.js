export async function getPHPLoaderModule() {
	throw new Error('Cette version de PHP n\'est pas embarquée : seul PHP 8.6 est disponible.');
}
export const getIntlExtensionPath = getPHPLoaderModule;
export const jspi = async () => false;
