import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: { include: ['spike/php86/*.spike.ts'], testTimeout: 300_000, hookTimeout: 300_000, fileParallelism: false },
});
