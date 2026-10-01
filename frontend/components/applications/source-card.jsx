"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { isDeployIncomplete, liveCommit } from "@/lib/applications/code-on-disk";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import { GitBranch, Loader2, Rocket, Settings2, TriangleAlert, Unlink, Webhook } from "lucide-react";
import { deployApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { RelinkGitAccountDialog } from "@/components/applications/relink-git-account-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Git sites only. "Deploy" sits here rather than in the ⋯ menu. A failed
 * redeploy leaves the old code serving, so the card reports the last
 * successful deploy, not "broken".
 */
export function SourceCard({ application, gitAccounts = [], canDeploy = false, canSeeDeployment = true, deployInFlight = false, className }) {
  /*
   * The provider, from the git account (the application only carries
   * `git_account_id`). Hidden when the account is gone: `git_account_missing`
   * has its own banner below.
   */
  const account = gitAccounts.find((a) => a.id === application.git_account_id) ?? null;
  const providerTitle = application.git_account_missing ? null : account?.provider_title;
  const t = useTranslations("applications.source");
  const td = useTranslations("applications.details");
  const { refreshThen } = useRefresh();
  const [deploying, setDeploying] = useState(false);
  const [relinking, setRelinking] = useState(false);

  const commit = liveCommit(application);
  const incomplete = isDeployIncomplete(application);
  const repository = application.repository ?? application.repository_url;
  const pushToDeploy = application.webhook?.enabled;
  // The old code is still serving, so this is a deploy warning, not an outage.
  const deployFailed =
    application.status === "active" && (Boolean(application.failed_step) || incomplete);

  async function deploy() {
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("started"));
      // Stay busy until the refreshed page reports the deploy in flight, to prevent
      // a second deploy.
      refreshThen(() => setDeploying(false));
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      setDeploying(false);
    }
  }

  return (
    <Card className={className}>
      {/* Stacks on a phone. min-w-48, not min-w-0, on the text: beside shrink-0
          buttons min-w-0 lets the title collapse to one word per line. */}
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div className="min-w-48 flex-1 space-y-1.5">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <GitBranch className="size-4 text-primary" />
            {t("title")}
            {/* The provider, where known. */}
            {providerTitle ? (
              <Badge variant="outline" className="font-normal">
                {providerTitle}
              </Badge>
            ) : null}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
          {/* A fact, not an action, so it sits with the badges, not the buttons. */}
          {pushToDeploy ? (
            <Badge variant="muted" className="w-fit gap-1.5 font-normal">
              <Webhook className="size-3" />
              {t("pushToDeploy")}
            </Badge>
          ) : null}
        </div>
        {/* Actions in the header: the card is a full-width band. */}
        <div className="flex flex-wrap items-center gap-2 sm:shrink-0">
          {canDeploy ? (
            <Button
              size="sm"
              onClick={deploy}
              disabled={deploying || deployInFlight}
              disabledReason={!deploying && deployInFlight ? t("inFlightReason") : null}
            >
              {deploying || deployInFlight ? <Loader2 className="size-4 animate-spin" /> : <Rocket className="size-4" />}
              {deploying || deployInFlight ? t("deploying") : deployFailed ? t("redeploy") : t("deploy")}
            </Button>
          ) : null}
          {/* Only for users who can open it. */}
          {canSeeDeployment ? (
            <Button variant="outline" size="sm" asChild>
              <Link href={`/applications/${application.id}/deployment`}>
                <Settings2 className="size-4" />
                {t("manage")}
              </Link>
            </Button>
          ) : null}
        </div>
      </CardHeader>
      {/* gap, not space-y: space-y's compound selector would outrank margins set
          here. flex-1 fills the stretched row, with leftover space at the bottom. */}
      <CardContent className="flex flex-1 flex-col gap-3">
        {/* Re-reads the page while a deploy runs, so the button comes back and
            "Last deployed" moves on without a reload. */}
        {deployInFlight ? <AutoRefresh intervalMs={5000} stopAfterMs={900000} /> : null}
        {deployInFlight ? (
          <p role="status" className="flex items-start gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" aria-hidden />
            {t("inFlightNote")}
          </p>
        ) : null}
        {/* The deploy account was deleted: the site keeps its repo and branch but
            has no credential, so the next deploy will fail. Offer the repair here. */}
        {application.git_account_missing ? (
          <div
            role="alert"
            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2.5 text-sm text-destructive"
          >
            <span className="flex items-start gap-2">
              <Unlink className="mt-0.5 size-4 shrink-0" />
              {t("accountMissing")}
            </span>
            {canDeploy && gitAccounts.length ? (
              <Button type="button" variant="outline" size="sm" onClick={() => setRelinking(true)}>
                {t("relink.action")}
              </Button>
            ) : null}
          </div>
        ) : null}

        {deployFailed ? (
          <div
            role="alert"
            className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-sm text-warning"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-0.5">
              {/* Only a deploy that failed before its checkout leaves the old version
                  serving; after it, the new commit is live. */}
              {incomplete ? (
                <>
                  {application.failed_step ? (
                    <p>{t("failedAtStep", { step: provisionStepLabel(application.failed_step, td) })}</p>
                  ) : null}
                  <p>{application.code_on_disk?.message || t("incomplete")}</p>
                </>
              ) : (
                <p>{t("failedAt", { step: provisionStepLabel(application.failed_step, td) })}</p>
              )}
              {application.reference ? (
                <p className="font-mono text-xs opacity-90">
                  {t("reference", { reference: application.reference })}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}

        <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t("repository")}</p>
            <p className="break-all font-mono text-xs">{repository ?? "—"}</p>
          </div>
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t("branch")}</p>
            <p className="font-mono text-xs">{application.branch ?? "—"}</p>
          </div>
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t("lastDeploy")}</p>
            <p className="font-medium">
              {application.last_deployed_at_human ?? application.last_deployed_at ?? t("never")}
            </p>
          </div>
          {commit ? (
            <div className="space-y-1">
              <p className="text-xs text-muted-foreground">{t("commit")}</p>
              <p className="flex flex-wrap items-center gap-1.5 font-mono text-xs">
                {commit.slice(0, 12)}
                {incomplete ? (
                  <Badge variant="outline" className="border-warning/40 bg-warning/10 font-sans font-normal text-warning">
                    {t("notFullyDeployed")}
                  </Badge>
                ) : null}
              </p>
            </div>
          ) : null}
        </div>

        <RelinkGitAccountDialog
          application={application}
          accounts={gitAccounts}
          open={relinking}
          onOpenChange={setRelinking}
        />
      </CardContent>
    </Card>
  );
}
