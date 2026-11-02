/// <reference lib="webworker" />
/**
 * Le worker PHP qui a le réseau, vers les API d'IA seulement : les environnements qui déclarent `network:` (parcours IA).
 * Sa CSP (docker/Caddyfile) n'autorise que ces API ; celle de php-worker.ts n'autorise rien.
 */
import { createPhpNetwork } from './php-network';
import { servePhpWorker } from './php-worker-core';

servePhpWorker(createPhpNetwork());
