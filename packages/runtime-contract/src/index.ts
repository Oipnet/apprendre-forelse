export type { FrameworkId, FrameworkProfile } from './framework';
export type { RuntimeBuildContribution, RuntimeManifest } from './manifest';
export type { WorkerArgs, WorkerCall, WorkerMessage, WorkerMethod, WorkerResult } from './protocol';
export type { ServeOptions, WorkerApi, WorkerEndpoint } from './serve';
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
} from './runtime';

// La plomberie que les deux côtés partagent : un runtime dans un worker, piloté par postMessage.
export { PING } from './protocol';
export { serveRuntime } from './serve';
export { createModuleWorker, WorkerRuntime } from './worker';
