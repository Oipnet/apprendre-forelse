import '../playground.css';
import { mountPlayground } from '../app/Playground';
import type { PlaygroundConfig } from '../app/types';

const root = document.querySelector<HTMLElement>('[data-playground]');
if (root) void mountPlayground(root, JSON.parse(root.dataset.config ?? '{}') as PlaygroundConfig);
