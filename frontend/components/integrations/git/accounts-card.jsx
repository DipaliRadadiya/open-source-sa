"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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

/**
 * Connected accounts, and whether their tokens still work.
 *
 * The list itself is server-rendered from the database and arrives instantly.
 * Health is fetched here, from the browser, because it makes live calls out to
 * GitHub, GitLab and Bitbucket — putting it in the server render would let one
 * slow provider hold up the whole page. Badges fill in when it answers.
 */
export function AccountsCard({ accounts = [], providers = [], canManage, providersFailed }) {
  const t = useTranslations("git");
  const { name: brand } = useBranding();
  const router = useRouter();
  const [statuses, setStatuses] = useState(null);
  // Only the manual re-check spins the button. The first load is silent — the
  // rows carry their own "checking" state, and two spinners for one request
  // read as two requests.
  const [rechecking, setRechecking] = useState(false);
  const [connecting, setConnecting] = useState(false);
  // Only appears in the immediate first-connect flow; a refresh or navigation
  // clears it so it never becomes permanent dashboard chrome.
  const [showCreateNudge, setShowCreateNudge] = useState(false);
  const [editing, setEditing] = useState(null);
  const [replacing, setReplacing] = useState(null);
  const [disconnecting, setDisconnecting] = useState(null);
  const [testingId, setTestingId] = useState(null);

  // No setState before the first await: called straight from an effect, a
  // synchronous one cascades an extra render on every mount.
  const load = useCallback(async (signal) => {
    try {
      const { data } = await getAccountStatuses({ signal });
      const parsed = gitStatusesResponseSchema.safeParse(data);
      // A malformed or failed check leaves every badge saying it has not been
      // checked, which is the one honest answer when we do not know.
      if (parsed.success) setStatuses(parsed.data.statuses);
    } catch {
      // Deliberately quiet: this is a background check, and a toast for a
      // provider being slow would be noise on a page that otherwise works.
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
    // Inline and awaited before anything is set: calling a state-setting
    // helper straight from an effect body cascades a render on mount.
    (async () => {
      await load(controller.signal);
    })();
    return () => controller.abort();
  }, [accounts.length, load]);

  async function check(account) {
    setTestingId(account.id);
    try {
      await testAccount(account.id);
      toast.success(t("actions.checked", { label: account.label }));
      // Refreshes identifier, scopes and last-verified on the row, then the
      // badge from the same live source as the rest.
      router.refresh();
      await load();
    } catch (error) {
      toast.error(apiMessage(error, t("actions.checkFailed")));
    } finally {
      setTestingId(null);
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
      <Button disabled={Boolean(connectReason)} onClick={() => setConnecting(true)}>
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
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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
                {/* Said before the credential is asked for, not after. */}
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
              {showCreateNudge ? (
                <div className="border-b py-4">
                  <div className="flex flex-col gap-3 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div className="space-y-0.5">
                      <p className="text-sm font-medium">{t("onboarding.title")}</p>
                      <p className="text-xs leading-5 text-muted-foreground">
                        {t("onboarding.description")}
                      </p>
                    </div>
                    <Button size="sm" asChild className="shrink-0">
                      <Link href="/applications/create">
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
                    testing={testingId === account.id}
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
            showNextStep={accounts.length === 0}
            onFirstAccountConnected={() => setShowCreateNudge(true)}
            onOpenChange={setConnecting}
          />
          {/* Keyed and mounted only while open: a dialog that keeps its state
              after closing shows the previous account's values for a moment
              when the next one opens. */}
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
