"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  CheckCircle2,
  CircleHelp,
  FolderGit2,
  KeyRound,
  MoreHorizontal,
  Pencil,
  RefreshCw,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import { getRepositories } from "@/lib/api/git";
import { repositoriesResponseSchema } from "@/lib/schemas/git";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { InfoHint } from "@/components/ui/info-hint";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { AccountHealth } from "@/components/integrations/git/account-health";

/**
 * One connected account.
 *
 * The label leads, not the provider: it is the name the user chose, and it is
 * how this account will appear in the app-create dropdown later. The provider
 * is a badge and the identifier is the proof it is the right account — that one
 * comes back from the provider during verification, so it is the only field
 * here nobody typed.
 */
export function AccountRow({
  account,
  status,
  loading,
  canManage,
  onTest,
  onEdit,
  onReplace,
  onDisconnect,
  testing,
}) {
  const t = useTranslations("git");
  const [testingRepos, setTestingRepos] = useState(false);
  const [repositoryAccess, setRepositoryAccess] = useState(null);
  const broken = status?.status === "invalid";

  async function testRepositories() {
    setTestingRepos(true);
    setRepositoryAccess(null);
    try {
      const { data } = await getRepositories(account.id, { page: 1 });
      const result = repositoriesResponseSchema.safeParse(data);
      if (!result.success) throw new Error("Invalid repository response");

      // The endpoint intentionally returns a single lightweight page rather
      // than a provider-wide total. Say only what this check proves.
      setRepositoryAccess(
        result.data.repositories.length > 0 ? "available" : "empty",
      );
    } catch {
      setRepositoryAccess("failed");
    } finally {
      setTestingRepos(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="flex items-start justify-between gap-3 py-3.5">
        <div className="flex min-w-0 gap-3">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
            <ProviderLogo provider={account.provider} />
          </span>
  
          <div className="min-w-0 space-y-1.5">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="min-w-0 text-sm font-medium break-all">{account.label}</span>
              <Badge variant="secondary" className="font-normal">
                {account.provider_title}
              </Badge>
              {account.identifier ? (
                <span className="truncate font-mono text-xs text-muted-foreground">
                  {account.identifier}
                </span>
              ) : null}
              {/* Self-hosted only. On gitlab.com the host is noise. */}
              {account.host ? (
                <span className="truncate font-mono text-xs text-muted-foreground">
                  {account.host}
                </span>
              ) : null}
              <AccountDetails account={account} />
            </div>
  
            <AccountHealth status={status} loading={loading} />
  
            <div className="flex flex-wrap items-center gap-2 pt-0.5">
              <Button
                variant="outline"
                size="sm"
                disabled={!canManage || testing}
                aria-busy={testing}
                onClick={onTest}
              >
                <RefreshCw className={`size-3.5 ${testing ? "animate-spin" : ""}`} />
                {testing ? t("actions.checking") : t("actions.check")}
              </Button>
  
              <Button
                variant="outline"
                size="sm"
                disabled={!canManage || testingRepos}
                aria-busy={testingRepos}
                onClick={testRepositories}
              >
                {testingRepos ? <RefreshCw className="size-3.5 animate-spin" /> : <FolderGit2 className="size-3.5" />}
                {testingRepos
                  ? t("repositoryAccess.checking")
                  : t("repositoryAccess.action")}
              </Button>
  
              {repositoryAccess === "available" ? (
                <p
                  role="status"
                  aria-live="polite"
                  className="inline-flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400"
                >
                  <CheckCircle2 className="size-3.5" />
                  {t("repositoryAccess.available")}
                </p>
              ) : null}
  
              {repositoryAccess === "empty" ? (
                <div
                  role="status"
                  aria-live="polite"
                  className="flex basis-full items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs"
                >
                  <CircleHelp className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                  <div className="space-y-0.5">
                    <p className="font-medium text-foreground">{t("repositoryAccess.empty")}</p>
                    <p className="leading-5 text-muted-foreground">
                      {t("repositoryAccess.emptyHint")}
                    </p>
                  </div>
                </div>
              ) : null}
  
              {repositoryAccess === "failed" ? (
                <p
                  role="status"
                  aria-live="polite"
                  className="inline-flex items-center gap-1.5 text-xs text-destructive"
                >
                  <TriangleAlert className="size-3.5" />
                  {t("repositoryAccess.failed")}
                </p>
              ) : null}
  
              {/* The fix, next to the problem. A broken token is the one state on
                  this page where there is something to do, so it does not hide in
                  the menu. */}
              {broken && canManage ? (
                <Button variant="outline" size="sm" onClick={onReplace}>
                  <KeyRound className="size-3.5" />
                  {t("actions.replace")}
                </Button>
              ) : null}
            </div>
          </div>
        </div>
  
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 shrink-0"
              disabled={!canManage}
            >
              <MoreHorizontal className="size-4" />
              <span className="sr-only">{t("actions.menu")}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="min-w-44">
            <DropdownMenuItem onClick={onEdit}>
              <Pencil className="size-4" />
              {t("actions.edit")}
            </DropdownMenuItem>
            {/* Separate from Edit on purpose: renaming is instant and safe,
                swapping a credential is a verified round-trip that can fail. */}
            <DropdownMenuItem onClick={onReplace}>
              <KeyRound className="size-4" />
              {t("actions.replace")}
            </DropdownMenuItem>
            <DropdownMenuItem variant="destructive" onClick={onDisconnect}>
              <Trash2 className="size-4" />
              {t("actions.disconnect")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </DisabledReasonProvider>
  );
}

/**
 * Scopes, and when this was last checked.
 *
 * Off the row deliberately: scopes matter exactly once — when someone asks why
 * their organisation's repositories are missing — so they belong one tap away
 * rather than in every row forever.
 */
function AccountDetails({ account }) {
  const t = useTranslations("git.details");
  const scopes = account.scopes ?? [];

  return (
    <InfoHint label={t("label")}>
      <div className="space-y-2 text-xs">
        <Fact label={t("scopes")}>
          {account.provider === "github" && scopes.length === 0 ? (
            <span className="text-muted-foreground">
              {t("noScopesFineGrained")}
            </span>
          ) : scopes.length ? (
            <span className="flex flex-wrap gap-1">
              {scopes.map((scope) => (
                <code key={scope} className="rounded bg-muted px-1 py-0.5 font-mono">
                  {scope}
                </code>
              ))}
            </span>
          ) : (
            <span className="text-muted-foreground">{t("noScopes")}</span>
          )}
        </Fact>
        {account.workspace ? (
          <Fact label={t("workspace")}>
            <span className="font-mono">{account.workspace}</span>
          </Fact>
        ) : null}
        <Fact label={t("lastVerified")}>
          {account.last_verified_at_human ?? t("never")}
        </Fact>
        <Fact label={t("connected")}>{account.created_at_human ?? "—"}</Fact>
      </div>
    </InfoHint>
  );
}

function Fact({ label, children }) {
  return (
    <div className="space-y-0.5">
      <p className="text-muted-foreground">{label}</p>
      <div>{children}</div>
    </div>
  );
}
