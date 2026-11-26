import { cache } from "react";
import { read } from "@/lib/api/read";
import {
  gitAccountsResponseSchema,
  providersResponseSchema,
} from "@/lib/schemas/git";

export const getGitAccounts = cache(async function getGitAccounts() {
  const { data, failed } = await read(
    "/integrations/git/accounts",
    gitAccountsResponseSchema,
  );
  return { accounts: data?.git_accounts ?? [], failed };
});

export const getGitProviders = cache(async function getGitProviders() {
  const { data, failed } = await read(
    "/integrations/git/providers",
    providersResponseSchema,
  );
  return { providers: data?.providers ?? [], failed };
});
