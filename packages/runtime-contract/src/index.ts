export type { FrameworkId, FrameworkProfile } from './framework.ts';
export type { GradingOutcome, GradingWording, Mutant, MutantRun } from './grading.ts';
export type { PreviewConsoleMessage } from './preview.ts';
export type { RuntimeBuildContribution, RuntimeManifest } from './manifest.ts';
export type { WorkerArgs, WorkerCall, WorkerMessage, WorkerMethod, WorkerResult } from './protocol.ts';
export type { ServeOptions, WorkerApi, WorkerEndpoint } from './serve.ts';
export type {
	BootProgress,
	TestCaseResult,
	TestStatus,
	CommandResult,
	EnvironmentSpec,
	Grading,
	HttpRequest,
	HttpResponse,
	Runtime,
	RuntimeRestart,
	TestRunResult,
} from './runtime.ts';

// La plomberie que les deux côtés partagent : un runtime dans un worker, piloté par postMessage.
export { gradeOwnTests } from './grading.ts';
export { PING } from './protocol.ts';
export { PREVIEW_CONSOLE } from './preview.ts';
export { serveRuntime } from './serve.ts';
export { createModuleWorker, WorkerRuntime } from './worker.ts';
