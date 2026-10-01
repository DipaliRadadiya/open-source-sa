import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  gitAccountsResponseSchema,
  providersResponseSchema,
} from "@/lib/schemas/git";

// Shapes are imported, never restated: an inline copy rejects the whole response
// once the API grows a field.

/** Connected accounts. A cheap DB read — no outbound calls to any provider. */
export const getGitAccounts = cache(async function getGitAccounts() {
  const { data, failed } = await read(
    "/integrations/git/accounts",
    gitAccountsResponseSchema,
  );
  return { accounts: data?.git_accounts ?? [], failed };
});

// Fetched so adding a provider is a backend change only. Not fatal: the connect
// button explains why it cannot open.
export const getGitProviders = cache(async function getGitProviders() {
  const { data, failed } = await read(
    "/integrations/git/providers",
    providersResponseSchema,
  );
  return { providers: data?.providers ?? [], failed };
});
