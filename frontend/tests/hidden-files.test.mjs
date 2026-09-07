import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { hiddenToggleHref } from "../lib/files/hidden-href.js";

const root = path.join(import.meta.dirname, "..");

test("the toggle turns hiding on from a showing listing", () => {
  assert.equal(
    hiddenToggleHref({ appId: 7, path: "", showHidden: true }),
    "/applications/7/files?hidden=0",
  );
});

test("and back off again", () => {
  // Showing is the default, so the way back is a URL with no flag at all
  // rather than ?hidden=1, which would say nothing.
  assert.equal(
    hiddenToggleHref({ appId: 7, path: "", showHidden: false }),
    "/applications/7/files",
  );
});

test("the current folder is carried, not dropped", () => {
  // Otherwise the toggle sends someone back to the site root from three
  // folders deep — the kind of thing that makes a control feel broken.
  assert.equal(
    hiddenToggleHref({ appId: 7, path: "wp-content/uploads", showHidden: true }),
    "/applications/7/files?path=wp-content%2Fuploads&hidden=0",
  );

  assert.equal(
    hiddenToggleHref({ appId: 7, path: "wp-content/uploads", showHidden: false }),
    "/applications/7/files?path=wp-content%2Fuploads",
  );
});

test("the listing is filtered by the server, not by the browser", () => {
  // The count of what was held back has to come from the same read that
  // produced the rows. A browser-side filter over a listing the server had
  // already moved on from could report a number the rows contradict.
  const fetcher = fs.readFileSync(
    path.join(root, "lib/applications/get-files.js"),
    "utf8",
  );

  assert.match(fetcher, /hidden: showHidden \? "1" : "0"/, "the flag must be sent to the API");

  const panel = fs.readFileSync(
    path.join(root, "components/applications/files/files-panel.jsx"),
    "utf8",
  );

  assert.ok(
    !/startsWith\("\."\)/.test(panel),
    "the panel must not decide what is hidden for itself",
  );
});

test("the count is required in the schema, so it cannot quietly go missing", () => {
  // Zod strips what it is not told about. An optional hidden_count that
  // stopped arriving would read as "none hidden", and the toolbar would claim
  // a filtered folder was showing everything.
  const schema = fs.readFileSync(path.join(root, "lib/schemas/file.js"), "utf8");

  const line = schema.split("\n").find((l) => l.includes("hidden_count:"));

  assert.ok(line, "filesResponseSchema must declare hidden_count");
  assert.ok(!line.includes(".optional()"), "hidden_count must not be optional");
  assert.ok(!line.includes(".default("), "hidden_count must not be defaulted away");
});

test("every hidden-files message exists in all three locales", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );

    for (const key of ["hide", "show", "count"]) {
      assert.equal(
        typeof messages.applications.files.hidden[key],
        "string",
        `${locale}.json is missing applications.files.hidden.${key}`,
      );
    }
  }
});
