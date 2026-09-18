/** `node:perf_hooks` : l'horloge du navigateur. */
export const performance = globalThis.performance
export default { performance }
export const PerformanceObserver = globalThis.PerformanceObserver ?? class {}, PerformanceEntry = globalThis.PerformanceEntry ?? class {}
