import { api } from "@/lib/api/client";

const BASE = "/integrations/git";

/**
 * Live token health for every connected account, one row each.
 *
 * Nothing about this is cached: a token can be revoked at the provider at any
 * moment, so a stored verdict would lie. It is fetched from the browser
 * alongside the server-rendered list rather than blocking it — a slow provider
 * must not hold up the page.
 */
export function getAccountStatuses({ signal } = {}) {
  return api.get(`${BASE}/accounts/status`, { signal });
}

/** `{provider, label, token, host?, workspace?}` — verified before it is stored. */
export function connectAccount(payload) {
  return api.post(`${BASE}/accounts`, payload);
}

/**
 * Rename and/or rotate. A changed credential is re-verified first, so a
 * rejected rotation leaves the working token in place.
 */
export function updateAccount(id, payload) {
  return api.put(`${BASE}/accounts/${id}`, payload);
}

/** Re-verify now; refreshes identifier, scopes and last-verified. */
export function testAccount(id) {
  return api.post(`${BASE}/accounts/${id}/test`);
}

/**
 * Removes the panel's copy of the credential. It does NOT revoke anything at
 * the provider — the token keeps working until it is deleted there.
 */
export function disconnectAccount(id) {
  return api.delete(`${BASE}/accounts/${id}`);
}

/**
 * What repositories this account can see.
 *
 * Fetched by "Test repositories" on the account row. `?per_page=1` is enough
 * to get the total count; fetching the full list would be slow for someone
 * with hundreds of repos.
 */
/**
 * Point an application at a different git account (`PUT .../git-account`).
 *
 * Everything omitted is taken from the application as it stands, so repairing a
 * site that merely lost its credential is `{git_account_id}` and nothing else —
 * the user never restates an owner/repo they did not change.
 *
 * Verified before it is stored: the candidate account is asked to list the
 * repository's branches, and a rejected re-link leaves the application exactly
 * as it was. A `422` on `repository` means that account cannot reach it.
 */
export function relinkGitAccount(applicationId, payload) {
  return api.put(`/applications/${applicationId}/git-account`, payload);
}

export function getRepositories(accountId, params = {}) {
  return api.get(`${BASE}/accounts/${accountId}/repositories`, { params });
}
