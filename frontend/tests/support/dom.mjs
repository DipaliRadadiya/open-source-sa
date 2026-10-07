/**
 * Renders real components under node:test: jsdom for the DOM, esbuild to turn
 * JSX and `@/` imports into one module node can load.
 */
import { mkdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { build } from "esbuild";
import { JSDOM } from "jsdom";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

export function installDom() {
  if (globalThis.document) return;
  const dom = new JSDOM("<!doctype html><html><body></body></html>", {
    url: "http://localhost/",
    pretendToBeVisual: true,
  });
  const { window } = dom;
  for (const key of Object.getOwnPropertyNames(window)) {
    if (key in globalThis) continue;
    Object.defineProperty(globalThis, key, {
      configurable: true,
      get: () => window[key],
    });
  }
  Object.defineProperty(globalThis, "navigator", { configurable: true, value: window.navigator });
  // Radix measures and captures pointers; jsdom implements neither.
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
  window.ResizeObserver = globalThis.ResizeObserver;
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    addEventListener() {},
    removeEventListener() {},
    addListener() {},
    removeListener() {},
  });
  window.HTMLElement.prototype.scrollIntoView = () => {};
  window.HTMLElement.prototype.hasPointerCapture = () => false;
  window.HTMLElement.prototype.releasePointerCapture = () => {};
  globalThis.IS_REACT_ACT_ENVIRONMENT = true;
}

/** Bundles `entry` (relative to the frontend root); `stubs` maps an import to a file. */
export async function loadComponent(entry, stubs = {}) {
  const outfile = join(ROOT, "node_modules/.cache/component-tests", `${entry.replace(/[^\w]+/g, "_")}.mjs`);
  mkdirSync(dirname(outfile), { recursive: true });
  await build({
    entryPoints: [join(ROOT, entry)],
    outfile,
    bundle: true,
    format: "esm",
    platform: "node",
    jsx: "automatic",
    packages: "external",
    logLevel: "error",
    plugins: [
      {
        name: "app-paths",
        setup(builder) {
          builder.onResolve({ filter: /^@\// }, (args) => {
            if (stubs[args.path]) return { path: join(ROOT, stubs[args.path]) };
            return builder.resolve(`./${args.path.slice(2)}`, { resolveDir: ROOT, kind: args.kind });
          });
          // next-intl's client hooks are use-intl's; this keeps Next itself out of the bundle.
          builder.onResolve({ filter: /^next-intl$/ }, () => ({ path: "use-intl", external: true }));
        },
      },
    ],
  });
  return import(`${pathToFileURL(outfile).href}?${Date.now()}`);
}
