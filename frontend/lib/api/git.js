import { api } from "@/lib/api/client";

const BASE = "/integrations/git";

/**
 * Live token health for every connected account. Never cached (tokens can be
 * revoked at any time); fetched client-side so a slow provider does not block
 * the page.
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
 * Point an application at a different git account. Omitted fields keep their
 * current values, so `{git_account_id}` alone repairs a lost credential.
 * Verified before storing; a `422` on `repository` means that account cannot
 * reach it, and the application is left unchanged.
 */
export function relinkGitAccount(applicationId, payload) {
  return api.put(`/applications/${applicationId}/git-account`, payload);
}

/** Repositories this account can see; `?per_page=1` is enough for the total count. */
export function getRepositories(accountId, params = {}) {
  return api.get(`${BASE}/accounts/${accountId}/repositories`, { params });
}
