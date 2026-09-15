import { providerFromRepositoryUrl } from "../git/provider-from-url.js";

/**
 * Which git service a site came from — GitHub, GitLab, Bitbucket, or unknown.
 *
 * Two sources, because a site is built one of two ways and each knows only its
 * own half:
 *
 * - from a CONNECTED ACCOUNT, where `repository` is "owner/repo" with no host
 *   in it at all. The account is the only thing that knows, so the provider is
 *   looked up by `git_account_id`.
 * - from a PUBLIC URL, where there is no account and the address names the
 *   host itself.
 *
 * Null for everything else, and that covers real cases rather than oversights:
 * a self-hosted GitLab whose address says nothing, a site whose account was
 * deleted, a reader without permission to see the accounts list. The caller
 * draws the generic git mark for all of them — the same one every git site
 * showed before this existed.
 *
 * `git_account_missing` is deliberately NOT treated as a lookup failure: that
 * site's account is gone, so there is nothing to name, and inventing a badge
 * from a stale id would say the connection is fine when a banner two lines
 * below says it is not.
 */
export function gitProviderFor(application, providerByAccountId) {
  if (!application || application.site_type !== "git") return null;
  if (application.git_account_missing) return null;

  const accountId = application.git_account_id;
  if (accountId !== null && accountId !== undefined) {
    return providerByAccountId?.get?.(String(accountId)) ?? null;
  }

  return providerFromRepositoryUrl(application.repository_url);
}

/**
 * `git_account_id` → provider, from the accounts list.
 *
 * A Map keyed by STRING: the application sends a number and the account sends
 * a number, but one `String()` on each side is cheaper than trusting that to
 * stay true through a JSON round trip and a schema that marks both nullish.
 */
export function providersByAccountId(accounts = []) {
  const byId = new Map();
  for (const account of accounts) {
    if (account?.id === undefined || account?.id === null) continue;
    if (!account.provider) continue;
    byId.set(String(account.id), account.provider);
  }
  return byId;
}
