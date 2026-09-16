import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { normalizeRepositoryUrl, repositoryUrlProblem } from "../lib/applications/repository-url.js";

/*
 * Reported: a valid public Bitbucket URL was refused on the create-application
 * form with "Enter a valid https:// URL for the self-hosted instance."
 *
 * Bitbucket's Clone button gives `https://you@bitbucket.org/team/repo.git`, and
 * the API rejects any URL with a user component — correctly, since the string
 * goes into `git clone`. The username means nothing for a public clone, so it
 * is removed here rather than bounced back in the GitLab host field's wording.
 */

test("a Bitbucket clone URL loses its username and nothing else", () => {
  const { url, strippedCredentials } = normalizeRepositoryUrl(
    "https://kris@bitbucket.org/team/repo.git",
  );
  assert.equal(url, "https://bitbucket.org/team/repo.git");
  assert.equal(strippedCredentials, true);
});

test("a URL with no username is returned untouched", () => {
  for (const input of [
    "https://github.com/owner/repo.git",
    "https://gitlab.example.com/group/sub/project.git",
    "https://bitbucket.org/team/repo.git",
  ]) {
    const { url, strippedCredentials } = normalizeRepositoryUrl(input);
    assert.equal(url, input, `${input} was altered`);
    assert.equal(strippedCredentials, false);
  }
});

test("a password is dropped along with the username", () => {
  // Not a supported way to reach a private repo — the connected-account source
  // is — but it must never be forwarded to `git clone` either.
  const { url, strippedCredentials } = normalizeRepositoryUrl(
    "https://user:hunter2@example.com/team/repo.git",
  );
  assert.equal(url, "https://example.com/team/repo.git");
  assert.equal(strippedCredentials, true);
});

test("surrounding whitespace is trimmed, and blank stays blank", () => {
  assert.equal(normalizeRepositoryUrl("  https://github.com/a/b.git  ").url, "https://github.com/a/b.git");
  assert.deepEqual(normalizeRepositoryUrl("   "), { url: "", strippedCredentials: false });
  assert.deepEqual(normalizeRepositoryUrl(null), { url: "", strippedCredentials: false });
});

test("an @ inside the path is not mistaken for a username", () => {
  // A scoped package name or a branch path can carry one, and eating from the
  // scheme to the LAST @ would mangle it.
  const input = "https://example.com/team/repo@v2.git";
  assert.equal(normalizeRepositoryUrl(input).url, input);
  assert.equal(normalizeRepositoryUrl(input).strippedCredentials, false);
});

test("the problems worth catching before the round trip", () => {
  assert.equal(repositoryUrlProblem("https://bitbucket.org/team/repo.git"), null);
  // Still fine — the username is removed before the check, so it is not a problem.
  assert.equal(repositoryUrlProblem("https://kris@bitbucket.org/team/repo.git"), null);
  assert.equal(repositoryUrlProblem("http://bitbucket.org/team/repo.git"), "notHttps");
  // The SSH clone URL, which the provider offers right beside the HTTPS one.
  assert.equal(repositoryUrlProblem("git@github.com:owner/repo.git"), "notHttps");
  assert.equal(repositoryUrlProblem("ssh://git@github.com/owner/repo.git"), "notHttps");
  assert.equal(repositoryUrlProblem("not a url"), "malformed");
  // Empty is the field's initial state, not an error to shout about.
  assert.equal(repositoryUrlProblem(""), null);
});

test("the form submits the normalized URL, not the raw field", () => {
  const form = fsReadCreateForm();
  assert.match(
    form,
    /payload\.repository_url = normalizeRepositoryUrl\(values\.repository_url\)\.url/,
    "the raw value would still carry the username the API refuses",
  );
});

function fsReadCreateForm() {
  return fs.readFileSync("components/applications/create-application-form.jsx", "utf8");
}
