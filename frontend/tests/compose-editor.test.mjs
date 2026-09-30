import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

const LOCALES = readdirSync("messages")
  .filter((f) => f.endsWith(".json"))
  .map((f) => f.replace(".json", ""));
const messages = Object.fromEntries(
  LOCALES.map((l) => [
    l,
    JSON.parse(readFileSync(`messages/${l}.json`, "utf8")),
  ]),
);
const editor = readFileSync(
  "components/applications/compose-editor.jsx",
  "utf8",
);

/*
 * The compose editor answers "how do I change an environment variable on a
 * container site", which previously had no answer: a container gets no Environment
 * screen, and the compose file was writable exactly once on the create form.
 *
 * Two of its sentences are load-bearing, so they are pinned here.
 */

test("every string the editor renders exists in every locale", () => {
  // A missing next-intl key renders as the key itself, which on a dangerous dialog
  // is worse than no text at all.
  const keys = [...editor.matchAll(/\bt\("([a-zA-Z]+)"\)/g)].map((m) => m[1]);

  assert.ok(keys.length > 8, "expected to find the editor's translation keys");

  for (const locale of LOCALES) {
    const copy = messages[locale].applications.compose;

    for (const key of new Set(keys)) {
      assert.ok(
        typeof copy[key] === "string" && copy[key].trim().length > 0,
        `${locale}.applications.compose.${key} is missing`,
      );
    }
  }
});

test("the dialog promises the rollback, because that is what makes it survivable", () => {
  // Someone about to edit the file that runs their site wants to know this BEFORE
  // they start, not from an error afterwards.
  assert.match(
    messages.en.applications.compose.safety,
    /puts the previous one back/i,
  );
  assert.match(messages.en.applications.compose.safety, /briefly down/i);
});

test("taking over a generated file is described as one-way", () => {
  // The only irreversible thing this screen does. "Saving takes the file over" is
  // not enough on its own — it has to say the fields stop working.
  const takeover = messages.en.applications.compose.takeover;

  assert.match(takeover, /stop driving it|stops driving it/i);
  assert.match(takeover, /no way back/i);
});

test("the acknowledgement is required only for the one-way case", () => {
  // A confirmation on every save would be friction with nothing behind it, since
  // an ordinary bad save rolls back on its own. Asserted on the component because
  // this is a judgement that is easy to "tidy" into always-on later.
  assert.match(editor, /generated && !acknowledged/);
});

test("a viewer gets a read-only textarea and no save button", () => {
  // The GET is allowed for `view` because reading the file is how somebody
  // diagnoses their own site; the PUT is not.
  assert.match(editor, /readOnly=\{!canManage\}/);
  assert.match(editor, /canManage \? \(/);
});

test("it is a page, not a dialog — the save control cannot be pushed off screen", () => {
  // Reported as the Save button hiding on a desktop: a sixty-line YAML box inside
  // a modal put the footer below the fold, so the control you opened it for was
  // the one you could not reach. A page has no fold to fall off.
  assert.doesNotMatch(editor, /<Dialog/);
  assert.doesNotMatch(editor, /DialogFooter/);

  // And the file arrives with the page rather than after a spinner.
  assert.match(editor, /initialCompose/);
});

test("long YAML lines get horizontal room rather than wrapping", () => {
  // An image reference, a bind mount and a published port are all long, and
  // wrapping them is what makes YAML unreadable. Width, not height.
  assert.match(editor, /wrap="off"/);
  assert.match(editor, /overflow-x-auto/);
});

test("save is offered only when something changed", () => {
  // A live Save button on an untouched file invites a pointless container
  // recreation, which is real downtime for the site.
  assert.match(editor, /const dirty = contents !== initialCompose/);
});

test("validator refusals are shown on the page, not as a toast", () => {
  // They are read line by line and acted on, and the text stays on screen so the
  // file can be fixed without being retyped. A toast clears itself in four seconds.
  assert.match(editor, /error\.response\?\.data\?\.errors\?\.compose/);
  // The rewrite made this a ternary inside setErrors rather than a branch, so
  // assert the choice is still made rather than pinning the shape it is made in.
  assert.match(editor, /fieldErrors\s*\n?\s*:\s*\[apiMessage/);
});

test("a successful save refreshes the server-rendered facts", () => {
  // A compose save can move the published port and the image, both of which are
  // rendered on the server — without this the card shows page-load state.
  assert.match(editor, /router\.refresh\(\)/);
});
