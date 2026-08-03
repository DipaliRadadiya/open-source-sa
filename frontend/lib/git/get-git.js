import { cache } from "react";
import { serverFetch } from "@/lib/api/server-fetch";
import {
  gitAccountsResponseSchema,
  providersResponseSchema,
} from "@/lib/schemas/git";

/**
 * Shapes are imported, never restated here — an inline copy silently rejects
 * the whole response the first time the API grows a field.
 */
async function read(path, schema) {
  try {
    const res = await serverFetch(path);
    if (!res.ok) return { data: null, failed: true };

    const parsed = schema.safeParse(await res.json());
    return parsed.success
      ? { data: parsed.data, failed: false }
      : { data: null, failed: true };
  } catch {
    return { data: null, failed: true };
  }
}

/** Connected accounts. A cheap DB read — no outbound calls to any provider. */
export const getGitAccounts = cache(async function getGitAccounts() {
  const { data, failed } = await read(
    "/integrations/git/accounts",
    gitAccountsResponseSchema,
  );
  return { accounts: data?.git_accounts ?? [], failed };
});

/**
 * The connect form's field schema, per provider.
 *
 * Fetched rather than hardcoded so adding a provider is a backend change only.
 * A failure here is not fatal to the page: the list still renders, and the
 * connect button explains why it cannot open.
 */
export const getGitProviders = cache(async function getGitProviders() {
  const { data, failed } = await read(
    "/integrations/git/providers",
    providersResponseSchema,
  );
  return { providers: data?.providers ?? [], failed };
});
