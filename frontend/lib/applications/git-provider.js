import { providerFromRepositoryUrl } from "../git/provider-from-url.js";

// Account-linked sites store "owner/repo" with no host, so look up by `git_account_id`.
// Null for self-hosted hosts, deleted accounts, and readers who cannot see the accounts list.
export function gitProviderFor(application, providerByAccountId) {
  if (!application || application.site_type !== "git") return null;
  if (application.git_account_missing) return null;

  const accountId = application.git_account_id;
  if (accountId !== null && accountId !== undefined) {
    return providerByAccountId?.get?.(String(accountId)) ?? null;
  }

  return providerFromRepositoryUrl(application.repository_url);
}

// Keyed by string so number/string id mismatches cannot miss.
export function providersByAccountId(accounts = []) {
  const byId = new Map();
  for (const account of accounts) {
    if (account?.id === undefined || account?.id === null) continue;
    if (!account.provider) continue;
    byId.set(String(account.id), account.provider);
  }
  return byId;
}
