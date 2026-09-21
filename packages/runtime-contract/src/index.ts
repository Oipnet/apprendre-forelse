export type { FrameworkId, FrameworkProfile } from './framework';
export type { RuntimeBuildContribution, RuntimeManifest } from './manifest';
export type { WorkerCall, WorkerMessage, WorkerMethod } from './protocol';
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
	TestRunResult,
} from './runtime';

// La plomberie que les deux côtés partagent : un runtime dans un worker, piloté par postMessage.
export { createModuleWorker, WorkerRuntime } from './worker';
