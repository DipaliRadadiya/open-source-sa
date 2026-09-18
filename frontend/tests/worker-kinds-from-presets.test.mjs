import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { workerKinds } from "../lib/applications/worker-kind.js";

/*
 * The Type dropdown used to render a fixed ["queue","horizon","custom"] — what
 * SaveWorkerRequest ACCEPTS, rather than what the site can run. The API already
 * decides the second thing: WorkerPresets::for() picks by framework, and every
 * preset carries its kind. One source, not two.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const preset = (kind) => ({ key: kind, kind });

const LARAVEL = [preset("queue"), preset("horizon"), preset("custom")];
const CRAFT = [preset("queue"), preset("custom")];
const NODE = [preset("custom")];

test("each site type offers exactly what its presets do", () => {
  assert.deepEqual(workerKinds(LARAVEL), ["queue", "horizon", "custom"]);
  assert.deepEqual(workerKinds(CRAFT), ["queue", "custom"]);
  // Krishna's `github` site: the API returns presets ["custom"], so this is
  // the whole list. It used to offer Queue worker and Horizon here.
  assert.deepEqual(workerKinds(NODE), ["custom"]);
});

test("the order the API sent is the order shown", () => {
  // Not sorted or re-grouped: the backend puts the framework's own preset
  // first and `custom` last, which is the order worth reading.
  assert.deepEqual(workerKinds([preset("custom"), preset("queue")]), ["custom", "queue"]);
});

test("a kind the site no longer suggests is still offered while a worker has it", () => {
  /*
   * Server Sync can adopt a queue worker on a site the detector does not read
   * as Laravel. Without this, opening that worker's Edit dialog would render a
   * Select whose value is absent from its own options — which draws as an empty
   * control, and makes "leave this alone" the one thing you cannot do.
   *
   * Same reasoning as never disabling the value already selected.
   */
  assert.deepEqual(workerKinds(NODE, "queue"), ["custom", "queue"]);
  // Already present: included once, not twice.
  assert.deepEqual(workerKinds(LARAVEL, "queue"), ["queue", "horizon", "custom"]);
});

test("a failed presets read does not empty the control it is editing", () => {
  // `get-workers.js` returns `presets: []` on failure. Creating is impossible
  // then anyway, but an open Edit dialog must still show the worker's own kind.
  assert.deepEqual(workerKinds([], "horizon"), ["horizon"]);
  assert.deepEqual(workerKinds([]), []);
});

test("malformed presets are skipped rather than rendered", () => {
  assert.deepEqual(workerKinds([{ key: "x" }, null, preset("custom")]), ["custom"]);
});

test("no fixed kind list survives in the frontend", () => {
  /*
   * The whole point. If this reappears, the dropdown starts disagreeing with
   * the empty state and the command field again — both of which read the API.
   */
  // Comments stripped: the docblock explaining this change quotes the old
  // literal, and a bare grep reads its own explanation as the thing it forbids.
  const code = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
  const lib = code(read("lib/applications/worker-kind.js"));
  const field = code(read("components/applications/workers/worker-kind-field.jsx"));
  assert.doesNotMatch(lib, /WORKER_KINDS/);
  assert.doesNotMatch(lib, /\[\s*"queue"\s*,\s*"horizon"\s*,\s*"custom"\s*\]/);
  assert.doesNotMatch(field, /\[\s*"queue"\s*,\s*"horizon"\s*,\s*"custom"\s*\]/);
  assert.match(field, /workerKinds\(presets, field\.value\)/);
});

test("both dialogs hand the presets to the Type field", () => {
  // They each already received `presets` for the command field's picker and
  // simply did not pass them on, which is how the two controls in one modal
  // ended up disagreeing.
  for (const name of ["create-worker-dialog", "edit-worker-dialog"]) {
    const source = read(`components/applications/workers/${name}.jsx`);
    assert.match(source, /<WorkerKindField[^>]*presets=\{presets\}/, name);
  }
});
