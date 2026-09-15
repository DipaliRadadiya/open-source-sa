import test from "node:test";
import assert from "node:assert/strict";
import { providerFromRepositoryUrl } from "../lib/git/provider-from-url.js";
import { gitProviderFor, providersByAccountId } from "../lib/applications/git-provider.js";

test("the three hosted services are recognised however the URL is written", () => {
  /*
   * All four of these come off a clone button on one of those sites, and the
   * field accepts what is in the address bar too. The SCP form is the one a
   * URL parser refuses outright — `git@github.com:owner/repo.git` has no
   * scheme and its host ends at a colon.
   */
  assert.equal(providerFromRepositoryUrl("https://github.com/owner/repo.git"), "github");
  assert.equal(providerFromRepositoryUrl("git@github.com:owner/repo.git"), "github");
  assert.equal(providerFromRepositoryUrl("github.com/owner/repo"), "github");
  assert.equal(providerFromRepositoryUrl("https://www.gitlab.com/group/repo"), "gitlab");
  assert.equal(providerFromRepositoryUrl("ssh://git@bitbucket.org/team/repo.git"), "bitbucket");
  assert.equal(providerFromRepositoryUrl("HTTPS://GitHub.com/Owner/Repo"), "github");
});

test("a host we cannot place stays unplaced", () => {
  /*
   * The temptation is to read "git" or "gitlab" out of a hostname. A
   * self-hosted GitLab, a Gitea and a bare SSH remote are indistinguishable by
   * address, and a wrong badge is worse than the generic mark the caller falls
   * back to — it claims a connection to a company the repository has nothing
   * to do with.
   */
  assert.equal(providerFromRepositoryUrl("https://git.example.com/owner/repo.git"), null);
  assert.equal(providerFromRepositoryUrl("https://gitlab.example.com/group/repo"), null);
  assert.equal(providerFromRepositoryUrl("https://github.example.com/owner/repo"), null);
  assert.equal(providerFromRepositoryUrl(""), null);
  assert.equal(providerFromRepositoryUrl(null), null);
  assert.equal(providerFromRepositoryUrl("not a url at all"), null);
});

test("an account-built site is named by its account, not by its repository", () => {
  // `repository` is "owner/repo" for these — there is no host in the payload
  // to read, which is the whole reason the accounts list is fetched.
  const byId = providersByAccountId([
    { id: 7, provider: "gitlab" },
    { id: 9, provider: "bitbucket" },
  ]);

  assert.equal(
    gitProviderFor({ site_type: "git", git_account_id: 7, repository: "owner/repo" }, byId),
    "gitlab",
  );
  // The id arrives as a number and the map is keyed by string on purpose.
  assert.equal(gitProviderFor({ site_type: "git", git_account_id: "9" }, byId), "bitbucket");
  // An id with no matching account — a reader who cannot see the integrations,
  // or a failed fetch — is unknown, not a guess from somewhere else.
  assert.equal(gitProviderFor({ site_type: "git", git_account_id: 99 }, byId), null);
});

test("a public-URL site is named by its address", () => {
  const empty = new Map();
  assert.equal(
    gitProviderFor(
      { site_type: "git", git_account_id: null, repository_url: "https://github.com/o/r" },
      empty,
    ),
    "github",
  );
});

test("a site whose account was deleted claims no provider", () => {
  /*
   * `git_account_missing` means the credential is gone and a banner on the
   * site's own page says so. A badge built from the leftover id would say the
   * connection is fine two lines above the notice that it is not.
   */
  const byId = providersByAccountId([{ id: 7, provider: "github" }]);
  assert.equal(
    gitProviderFor({ site_type: "git", git_account_id: 7, git_account_missing: true }, byId),
    null,
  );
});

test("only git sites get a provider at all", () => {
  // A WordPress site has its own logo, and `repository_url` is not its field.
  assert.equal(
    gitProviderFor({ site_type: "wordpress", repository_url: "https://github.com/o/r" }, new Map()),
    null,
  );
  assert.equal(gitProviderFor(null, new Map()), null);
});

test("accounts without a provider are not in the map", () => {
  // Rather than mapping an id to undefined and making every caller re-check.
  const byId = providersByAccountId([{ id: 1 }, { id: null, provider: "github" }, { provider: "gitlab" }]);
  assert.equal(byId.size, 0);
});
