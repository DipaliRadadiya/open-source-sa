import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const page = fs.readFileSync(path.join(root, "app/(app)/applications/create/page.jsx"), "utf8");

// ?type=docker named the app — and so its new Linux user — "docker", which the
// server's docker group makes useradd refuse; setup failed at once (19 Oct).
test("?type=docker does not prefill the name", () => {
  assert.match(page, /initialName=\{prefillType === "docker" \? "" : prefillType\}/);
});
