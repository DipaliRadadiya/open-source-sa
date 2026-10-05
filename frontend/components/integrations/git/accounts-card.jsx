"use client";

import { useCallback, useEffect, useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ArrowRight, GitBranch, Plus, RefreshCw } from "lucide-react";
import { getAccountStatuses, testAccount } from "@/lib/api/git";
import { gitStatusesResponseSchema } from "@/lib/schemas/git";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { useBranding } from "@/components/branding-provider";
import { Card, CardContent } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { AccountRow } from "@/components/integrations/git/account-row";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { ConnectDialog } from "@/components/integrations/git/connect-dialog";
import { EditDialog } from "@/components/integrations/git/edit-dialog";
import { ReplaceTokenDialog } from "@/components/integrations/git/replace-token-dialog";
import { DisconnectDialog } from "@/components/integrations/git/disconnect-dialog";

// Health is fetched in the browser: it calls providers live, and one slow provider must not block the page.
export function AccountsCard({ accounts = [], providers = [], canManage, providersFailed }) {
  const t = useTranslations("git");
  const { name: brand } = useBranding();
  const { refreshAndWait } = useRefresh();
  const [statuses, setStatuses] = useState(null);
  // Only the manual re-check spins the button; rows show their own "checking".
  const [rechecking, setRechecking] = useState(false);
  const [connecting, setConnecting] = useState(false);
  // The account just connected in this tab (cleared on refresh/navigation);
  // the next-step prompt names it and links with its id.
  const [justConnected, setJustConnected] = useState(null);
  const [editing, setEditing] = useState(null);
  const [replacing, setReplacing] = useState(null);
  const [disconnecting, setDisconnecting] = useState(null);
  const testing = usePendingKeys();

  // No setState before the first await: it is called from an effect.
  const load = useCallback(async (signal) => {
    try {
      const { data } = await getAccountStatuses({ signal });
      const parsed = gitStatusesResponseSchema.safeParse(data);
      // On a malformed response every badge stays "not checked".
      if (parsed.success) setStatuses(parsed.data.statuses);
    } catch {
      // Deliberately quiet: a background check.
    }
  }, []);

  async function recheck() {
    setRechecking(true);
    await load();
    setRechecking(false);
  }

  useEffect(() => {
    if (accounts.length === 0) return undefined;

    const controller = new AbortController();
    (async () => {
      await load(controller.signal);
    })();
    return () => controller.abort();
  }, [accounts.length, load]);

  async function check(account) {
    if (testing.isPending(account.id)) return;
    testing.start(account.id);
    try {
      await testAccount(account.id);
      await refreshAndWait();
      toast.success(t("actions.checked", { label: account.label }));
      await load();
    } catch (error) {
      toast.error(apiMessage(error, t("actions.checkFailed")));
    } finally {
      testing.finish(account.id);
    }
  }

  const statusFor = (id) => statuses?.find((row) => row.id === id);

  const connectReason = !canManage
    ? t("noPermission")
    : providersFailed || providers.length === 0
      ? t("connect.unavailable")
      : null;

  const connectButton = (
    <ReasonTooltip reason={connectReason}>
      <Button
        disabled={Boolean(connectReason)}
        onClick={() => setConnecting(true)}
        data-git-connect
      >
        <Plus className="size-4" />
        {t("connect.action")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
          <div className="flex items-center gap-2.5">
            <span className="flex shrink-0 items-center justify-center text-muted-foreground">
              <GitBranch className="size-3.5" />
            </span>
            <div>
              <h2 className="text-base font-semibold tracking-tight">
                {t("accounts.title")}
              </h2>
              <p className="text-sm text-muted-foreground">
                {t("accounts.description")}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {accounts.length > 0 ? (
              <IconTooltip label={t("accounts.recheck")}>
                <Button
                  variant="outline"
                  size="icon"
                  className="size-9"
                  disabled={rechecking}
                  aria-label={t("accounts.recheck")}
                  onClick={recheck}
                >
                  <RefreshCw className={`size-4 ${rechecking ? "animate-spin" : ""}`} />
                </Button>
              </IconTooltip>
            ) : null}
            {accounts.length > 0 ? connectButton : null}
          </div>
        </div>

        <CardContent className="px-5 py-0">
          {accounts.length === 0 ? (
            <div className="mx-auto flex max-w-lg flex-col items-center gap-5 py-10 text-center sm:py-12">
              <span className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15">
                <GitBranch className="size-6" aria-hidden />
              </span>

              <div className="space-y-2">
                <p className="text-base font-semibold tracking-tight">{t("empty.title")}</p>
                <p className="max-w-md text-sm leading-6 text-muted-foreground">
                  {t("empty.description")}
                </p>
                <p className="max-w-md text-xs leading-5 text-muted-foreground">
                  {t("connect.readOnly", { brand })}
                </p>
              </div>

              {providers.length > 0 ? (
                <div className="flex flex-wrap justify-center gap-2">
                  {providers.map((provider) => (
                    <span
                      key={provider.name}
                      className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2.5 py-1.5 text-xs font-medium text-muted-foreground"
                    >
                      <ProviderLogo provider={provider.name} className="size-3.5" />
                      {provider.title}
                    </span>
                  ))}
                </div>
              ) : null}

              <div className="w-full rounded-xl border bg-muted/30 p-4 text-left sm:p-5">
                <p className="text-sm font-medium">{t("empty.stepsTitle")}</p>
                <ol className="mt-3 space-y-3 text-sm text-muted-foreground">
                  {[
                    t("empty.step1", { provider: t("empty.providers") }),
                    t("empty.step2"),
                    t("empty.step3"),
                  ].map((step, index) => (
                    <li key={step} className="flex items-start gap-3">
                      <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-background text-xs font-medium text-foreground ring-1 ring-border">
                        {index + 1}
                      </span>
                      <span className="leading-5">{step}</span>
                    </li>
                  ))}
                </ol>
              </div>

              {connectButton}
            </div>
          ) : (
            <>
              {justConnected ? (
                <div className="border-b py-4">
                  <div className="flex flex-col gap-3 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-card ring-1 ring-foreground/10">
                        <ProviderLogo provider={justConnected.provider} className="size-4" />
                      </span>
                      <div className="min-w-0 space-y-0.5">
                        <p className="text-sm font-medium">
                          {t("onboarding.title", { label: justConnected.label })}
                        </p>
                        <p className="text-xs leading-5 text-muted-foreground">
                          {t("onboarding.description")}
                        </p>
                      </div>
                    </div>
                    {/* Opens the create form on the Git type with this account
                        preselected; the create page validates both. */}
                    <Button size="sm" asChild className="shrink-0">
                      <Link
                        href={
                          justConnected.id
                            ? `/applications/create?type=git&git_account=${justConnected.id}`
                            : "/applications/create?type=git"
                        }
                      >
                        {t("onboarding.action")}
                        <ArrowRight className="size-3.5" />
                      </Link>
                    </Button>
                  </div>
                </div>
              ) : null}
              <div className="divide-y">
                {accounts.map((account) => (
                  <AccountRow
                    key={account.id}
                    account={account}
                    status={statusFor(account.id)}
                    loading={statuses === null}
                    canManage={canManage}
                    testing={testing.isPending(account.id)}
                    onTest={() => check(account)}
                    onEdit={() => setEditing(account)}
                    onReplace={() => setReplacing(account)}
                    onDisconnect={() => setDisconnecting(account)}
                  />
                ))}
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {canManage ? (
        <>
          <ConnectDialog
            providers={providers}
            open={connecting}
            onAccountConnected={setJustConnected}
            onOpenChange={setConnecting}
          />
          {/* Keyed and mounted only while open, so no previous account's values
              flash on open. */}
          {editing ? (
            <EditDialog
              key={`edit-${editing.id}`}
              account={editing}
              open
              onOpenChange={(next) => !next && setEditing(null)}
            />
          ) : null}
          {replacing ? (
            <ReplaceTokenDialog
              key={`replace-${replacing.id}`}
              account={replacing}
              open
              onOpenChange={(next) => !next && setReplacing(null)}
            />
          ) : null}
          {disconnecting ? (
            <DisconnectDialog
              account={disconnecting}
              open
              onOpenChange={(next) => !next && setDisconnecting(null)}
            />
          ) : null}
        </>
      ) : null}
    </>
  );
}
