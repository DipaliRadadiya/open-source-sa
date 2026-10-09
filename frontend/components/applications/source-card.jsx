"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { isDeployIncomplete, liveCommit } from "@/lib/applications/code-on-disk";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import { Loader2, Rocket, Settings2, Unlink, Webhook } from "lucide-react";
import { deployApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { RelinkGitAccountDialog } from "@/components/applications/relink-git-account-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { cn } from "@/lib/utils";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Caution } from "@/components/ui/caution";
import { CopyButton } from "@/components/ui/copy-button";

// A failed redeploy leaves the old code serving, so the card reports the last successful deploy.
export function SourceCard({ application, gitAccounts = [], canDeploy = false, canSeeDeployment = true, deployInFlight = false, className }) {
  // Hidden when the account is gone: `git_account_missing` has its own banner.
  const account = gitAccounts.find((a) => a.id === application.git_account_id) ?? null;
  const providerTitle = application.git_account_missing ? null : account?.provider_title;
  const t = useTranslations("applications.source");
  const td = useTranslations("applications.details");
  const ta = useTranslations("applications");
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

  // "owner/repo" for a known host; the full URL stays in the title and the link.
  const repoUrl = /^https?:\/\//.test(repository ?? "") ? repository : null;
  const repoName = repository ? repository.replace(/^https?:\/\/[^/]+\//, "").replace(/\.git$/, "") : null;

  return (
    <Card className={cn("@container/source", className)}>
      {/* Same shape as the other cards here: title with its badges, the buttons on the
          right, a line, then the content. Stacks until the CARD is wide enough. */}
      <CardHeader className="flex flex-col gap-3 border-b @2xl/source:flex-row @2xl/source:items-center @2xl/source:justify-between">
        <CardTitle as="h2" className="flex min-w-48 flex-1 flex-wrap items-center gap-2">
          {t("title")}
          {/* The provider, where known. */}
          {providerTitle ? (
            <Badge variant="outline" className="font-normal">
              {providerTitle}
            </Badge>
          ) : null}
          {pushToDeploy ? (
            <Badge variant="muted" className="gap-1.5 font-normal">
              <Webhook className="size-3" />
              {t("pushToDeploy")}
            </Badge>
          ) : null}
        </CardTitle>
        <div className="flex flex-wrap items-center gap-2 @2xl/source:shrink-0">
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
      <CardContent className="@container flex flex-1 flex-col gap-3">
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
          <Caution
            tone="destructive"
            size="md"
            icon={Unlink}
            action={
              canDeploy && gitAccounts.length ? (
                <Button type="button" variant="outline" size="sm" onClick={() => setRelinking(true)}>
                  {t("relink.action")}
                </Button>
              ) : null
            }
          >
            <p role="alert">{t("accountMissing")}</p>
          </Caution>
        ) : null}

        {/* One note: a short bold verdict with where it stopped, then one sentence on what
            that means. The old note said "The last deploy failed…" twice (7 Oct). */}
        {deployFailed ? (
          <Caution size="md">
            <div role="alert" className="space-y-1">
              <p className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-medium">{ta("tiles.deployFailed")}</span>
                {incomplete && application.failed_step ? (
                  <span className="text-xs text-muted-foreground">
                    {td("failedAt", { step: provisionStepLabel(application.failed_step, td) })}
                  </span>
                ) : null}
              </p>
              {incomplete ? (
                // The server's sentence names the commit that is live; ours is the fallback.
                <p className="text-muted-foreground">{application.code_on_disk?.message || t("incomplete")}</p>
              ) : (
                <p className="text-muted-foreground">{t("failedAt", { step: provisionStepLabel(application.failed_step, td) })}</p>
              )}
              {application.reference ? (
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                  <span className="min-w-0 break-all">{t("reference", { reference: application.reference })}</span>
                  <CopyButton value={application.reference} />
                </p>
              ) : null}
            </div>
          </Caution>
        ) : null}

        {/* Label over value, like the details row in the page header. By the card's width. */}
        <dl className="grid gap-x-6 gap-y-3 @xs:grid-cols-2 @2xl:grid-cols-4">
          <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{t("repository")}</dt>
            <dd className="truncate font-mono text-[13px] font-medium" title={repository ?? undefined}>
              {repoUrl ? (
                <a href={repoUrl} target="_blank" rel="noreferrer" className="text-primary underline-offset-4 hover:underline">
                  {repoName}
                </a>
              ) : (
                (repoName ?? "—")
              )}
            </dd>
          </div>
          <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{t("branch")}</dt>
            <dd className="truncate font-mono text-[13px] font-medium">{application.branch ?? "—"}</dd>
          </div>
          {commit ? (
            <div className="min-w-0">
              <dt className="text-xs text-muted-foreground">{t("commit")}</dt>
              <dd className="flex flex-wrap items-center gap-1.5">
                <span className="font-mono text-[13px] font-medium" title={commit}>{commit.slice(0, 7)}</span>
                {incomplete ? (
                  <Badge variant="warning" className="font-normal">
                    {t("notFullyDeployed")}
                  </Badge>
                ) : null}
              </dd>
            </div>
          ) : null}
          <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{t("lastDeploy")}</dt>
            <dd className="text-sm font-medium">
              {application.last_deployed_at_human ?? application.last_deployed_at ?? t("never")}
            </dd>
          </div>
        </dl>

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
