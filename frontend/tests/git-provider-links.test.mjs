import { test } from "node:test";
import assert from "node:assert/strict";
import { createTokenUrl, revokeTokenUrl } from "../lib/git/provider-links.js";

// Atlassian removed app passwords on 2026-07-28 and deleted the page with
// them, so both Bitbucket links 404'd for everyone. A dead link in a form that
// exists to help someone find a credential is worse than no link.
test("neither bitbucket link points at the removed app-passwords page", () => {
  for (const url of [createTokenUrl("bitbucket"), revokeTokenUrl("bitbucket")]) {
    assert.doesNotMatch(url, /app-passwords/);
    assert.equal(url, "https://id.atlassian.com/manage-profile/security/api-tokens");
  }
});

test("github keeps its prefilled scopes and note", () => {
  const url = createTokenUrl("github", null, "Acme Panel");
  assert.match(url, /github\.com\/settings\/tokens\/new/);
  assert.match(url, /scopes=repo/);
  assert.match(url, /description=Acme%20Panel/);
});

test("gitlab follows the host when one is given", () => {
  // Self-hosted GitLab keeps its own token page; gitlab.com is only the default.
  assert.match(createTokenUrl("gitlab", "git.acme.test"), /^https:\/\/git\.acme\.test\//);
  assert.match(createTokenUrl("gitlab", "https://git.acme.test/"), /^https:\/\/git\.acme\.test\//);
  assert.match(createTokenUrl("gitlab"), /^https:\/\/gitlab\.com\//);
  assert.match(revokeTokenUrl("gitlab", "git.acme.test"), /^https:\/\/git\.acme\.test\//);
});

test("an unknown provider gets no link rather than a guess", () => {
  assert.equal(createTokenUrl("nope"), null);
  assert.equal(revokeTokenUrl("nope"), null);
});
