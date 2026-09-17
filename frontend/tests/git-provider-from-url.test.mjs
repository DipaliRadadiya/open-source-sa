import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { gitProviderFromUrl } from "../lib/applications/git-provider-from-url.js";

/*
 * Deploy-on-push asked which provider a public repository belongs to — a
 * question whose answer was in the repository URL on the same screen. A site
 * created from a connected account never had to answer it; only a pasted URL
 * did, which is exactly the case where the host is a public one we can name.
 */

test("the three public hosts are read, not asked about", () => {
  assert.equal(gitProviderFromUrl("https://github.com/owner/repo.git"), "github");
  assert.equal(gitProviderFromUrl("https://gitlab.com/group/sub/project.git"), "gitlab");
  assert.equal(gitProviderFromUrl("https://bitbucket.org/team/repo.git"), "bitbucket");

  // The keys must be exactly what the backend's webhook catalogue uses, or the
  // page filters the provider list down to nothing and the card offers none.
  for (const name of ["github", "gitlab", "bitbucket"]) {
    assert.match(
      fs.readFileSync("lib/applications/git-provider-from-url.js", "utf8"),
      new RegExp(`"${name}"`),
    );
  }
});

test("the forms a provider's own Clone button hands out", () => {
  // www., and the SCP-style SSH URL offered right beside the HTTPS one —
  // `new URL()` cannot parse the latter at all, but its host is unambiguous.
  assert.equal(gitProviderFromUrl("https://www.github.com/owner/repo"), "github");
  assert.equal(gitProviderFromUrl("git@github.com:owner/repo.git"), "github");
  assert.equal(gitProviderFromUrl("git@bitbucket.org:team/repo.git"), "bitbucket");
  assert.equal(gitProviderFromUrl("ssh://git@gitlab.com/group/project.git"), "gitlab");
  // A username in the URL, which is how Bitbucket hands out its HTTPS clone.
  assert.equal(gitProviderFromUrl("https://kris@bitbucket.org/team/repo.git"), "bitbucket");
});

test("anything it cannot know returns null, so the picker comes back", () => {
  /*
   * This is the half that keeps the helper honest. I have built host-matching
   * as a stand-in for a missing provider field before — on storage
   * destinations — and it became a source of confidently wrong answers the
   * moment a host did not fit the shape it assumed. A closed list plus a null
   * is what stops that: a self-hosted GitLab is unknowable from its URL, and
   * guessing would be worse than asking.
   */
  assert.equal(gitProviderFromUrl("https://git.company.com/group/project.git"), null);
  assert.equal(gitProviderFromUrl("https://gitlab.example.com/group/project.git"), null);
  assert.equal(gitProviderFromUrl("https://codeberg.org/owner/repo.git"), null);
  assert.equal(gitProviderFromUrl("not a url"), null);
  assert.equal(gitProviderFromUrl(""), null);
  assert.equal(gitProviderFromUrl(null), null);
  assert.equal(gitProviderFromUrl(undefined), null);

  // A lookalike host must NOT match — the list is exact, not a suffix test.
  assert.equal(gitProviderFromUrl("https://github.com.evil.example/owner/repo"), null);
  assert.equal(gitProviderFromUrl("https://notgithub.com/owner/repo"), null);
});

test("the account still wins over the URL", () => {
  /*
   * A linked account states its provider outright; the URL is the fallback.
   * Reversing them would let a mirror URL override the account the site
   * actually deploys from.
   */
  const page = fs.readFileSync(
    "app/(app)/applications/[application]/deployment/page.jsx",
    "utf8",
  );
  assert.match(
    page,
    /gitAccounts\.find\(\(a\) => a\.id === application\.git_account_id\)\?\.provider \?\?\s*gitProviderFromUrl\(application\.repository_url\)/,
  );
});

test("a single provider is stated, not offered as a one-item dropdown", () => {
  const card = fs.readFileSync("components/applications/deployment/webhook-card.jsx", "utf8");
  assert.match(card, /providers\.length === 1 \?/);
  // And the picker survives for the case that is genuinely open.
  assert.match(card, /<Select value=\{providerName\}/);
});

test("inference decides a default, never verification", () => {
  /*
   * The webhook verifier reads the stored `webhook_provider`. A hook that
   * re-sniffed the repository URL would start rejecting real pushes the day
   * someone repoints the repository — so the helper must not be reachable from
   * anything that checks a signature.
   */
  const callers = fs
    .readdirSync("lib/applications")
    .concat(fs.readdirSync("components/applications/deployment"))
    .filter((f) => f.endsWith(".js") || f.endsWith(".jsx"));

  assert.ok(callers.length > 0, "nothing was scanned");

  for (const dir of ["lib/api", "lib/schemas"]) {
    for (const file of fs.readdirSync(dir)) {
      const source = fs.readFileSync(`${dir}/${file}`, "utf8");
      assert.doesNotMatch(
        source,
        /gitProviderFromUrl/,
        `${dir}/${file} derives the provider instead of reading the stored one`,
      );
    }
  }
});
