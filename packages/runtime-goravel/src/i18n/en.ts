import type { Messages } from './index.ts';

/** The Goravel runtime's texts in English. */
export const en: Messages = {
	boot: {
		downloading: 'Downloading Goravel',
		downloadingSize: (mb: string) => `Downloading Goravel (${mb} MB)`,
		unpacking: 'Unpacking the Goravel project',
		starting: 'Starting Goravel',
		notStarted: 'The Goravel runtime has not started.',
		binaryMissing: (status: number) => `Goravel binary not found (HTTP ${status}).`,
		binaryNotBuilt: 'The Goravel binary has not been built (npm run build:wasm, in packages/runtime-goravel).',
		archiveMissing: (status: number) => `Project archive not found (HTTP ${status}).`,
		stoppedWithoutAnswer: 'the Go program stopped without answering',
	},
	tests: {
		noOwnTest: 'None of your tests ran.',
		skipped: '(skipped)',
		notFinished: 'The test did not finish.',
		summary: (passed: number, failed: number) => `(${passed} passed, ${failed} failed)`,
	},
};
