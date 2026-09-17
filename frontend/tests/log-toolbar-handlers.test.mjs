import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported: clicking "Newest first" on an application's Logs page threw
 * `Uncaught TypeError: W is not a function` at the onClick.
 *
 * ApplicationLogsPanel rendered <LogToolbar> without `newestFirst` or
 * `onNewestFirstChange`. The toolbar calls the handler directly, so it was
 * invoking `undefined(true)`.
 *
 * Nothing could have caught it. A missing prop is not a build error in plain
 * JS, no test rendered the toolbar, and the SERVER Logs page — which does wire
 * both — works, so the control looked proven on a page that never had it.
 */

const TOOLBAR = "components/logs/log-toolbar.jsx";
const CALLERS = [
  "components/logs/logs-panel.jsx",
  "components/applications/logs/application-logs-panel.jsx",
];

/** Handlers the toolbar destructures with no default and calls unguarded. */
function requiredHandlers() {
  const src = fs.readFileSync(TOOLBAR, "utf8");
  const start = src.indexOf("export function LogToolbar({");
  const sig = src.slice(start, src.indexOf("}) {", start));

  return [...sig.matchAll(/^\s*(on[A-Z]\w*)\s*,\s*$/gm)]
    .map((m) => m[1])
    .filter((h) => {
      const body = src.slice(start);
      return new RegExp(`\\b${h}\\(`).test(body) && !new RegExp(`\\b${h}\\?\\.\\(`).test(body);
    });
}

test("every page that renders the toolbar passes the handlers it calls", () => {
  const handlers = requiredHandlers();
  assert.ok(handlers.includes("onNewestFirstChange"), "the reported one is no longer required");

  for (const caller of CALLERS) {
    const src = fs.readFileSync(caller, "utf8");
    const at = src.indexOf("<LogToolbar");
    assert.ok(at > -1, `${caller} no longer renders the toolbar`);
    const block = src.slice(at, src.indexOf("/>", at));

    for (const handler of handlers) {
      assert.match(
        block,
        new RegExp(`${handler}=`),
        `${caller} renders LogToolbar without ${handler}, which it calls on click`,
      );
    }
  }
});

test("both pages can actually reorder, not just avoid the crash", () => {
  /*
   * Passing a handler that flips nothing would silence the error and leave the
   * button dead. The state has to exist and reach the viewer as well, or
   * "Newest first" toggles an icon and the lines stay put.
   */
  for (const caller of CALLERS) {
    const src = fs.readFileSync(caller, "utf8");
    assert.match(src, /const \[newestFirst, setNewestFirst\] = useState\(false\)/, caller);
    assert.match(src, /onNewestFirstChange=\{setNewestFirst\}/, caller);

    const viewer = src.slice(src.indexOf("<LogViewer"));
    assert.match(
      viewer.slice(0, viewer.indexOf("/>")),
      /newestFirst=\{newestFirst\}/,
      `${caller} never tells the viewer which way round to render`,
    );
  }
});

test("oldest-first is the default on both", () => {
  // That is how a console reads and how a live tail appends. The two pages
  // disagreeing about it would be worse than either choice.
  for (const caller of CALLERS) {
    assert.match(
      fs.readFileSync(caller, "utf8"),
      /newestFirst, setNewestFirst\] = useState\(false\)/,
      `${caller} starts newest-first`,
    );
  }
});
