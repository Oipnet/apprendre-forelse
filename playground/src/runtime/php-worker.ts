/// <reference lib="webworker" />
/** Le worker PHP sans réseau : tous les environnements, le bac à sable compris (voir php-worker-core.ts). */
import { servePhpWorker } from './php-worker-core';

servePhpWorker();
