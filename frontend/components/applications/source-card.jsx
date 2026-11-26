"use client";

import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { isDeployIncomplete, liveCommit } from "@/lib/applications/code-on-disk";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import { Loader2, Rocket, Settings2, Unlink, Webhook, GitBranch } from "lucide-react";
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
import { FACT_CELL, FACT_FRAME, FACT_GRID, FACT_LABEL } from "@/lib/theme/facts";
import { HoverHint } from "@/components/ui/hover-hint";

export function SourceCard({ application, gitAccounts = [], canDeploy = false, canSeeDeployment = true, deployInFlight = false, className }) {
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
  const deployFailed =
    application.status === "active" && (Boolean(application.failed_step) || incomplete);

  async function deploy() {
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("started"));
      refreshThen(() => setDeploying(false));
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
      setDeploying(false);
    }
  }

  const repoUrl = /^https?:\/\//.test(repository ?? "") ? repository : null;
  const repoName = repository ? repository.replace(/^https?:\/\/[^/]+\//, "").replace(/\.git$/, "") : null;

  return (
    <Card className={cn("@container/source", className)}>
      <CardHeader className="flex flex-col gap-3 border-b @2xl/source:flex-row @2xl/source:items-center @2xl/source:justify-between">
        <CardTitle icon={GitBranch} as="h2" className="flex min-w-48 flex-1 flex-wrap items-center gap-2">
          {t("title")}
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
        {deployInFlight ? <AutoRefresh intervalMs={5000} stopAfterMs={900000} /> : null}
        {deployInFlight ? (
          <p role="status" className="flex items-start gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" aria-hidden />
            {t("inFlightNote")}
          </p>
        ) : null}
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

        <div className={FACT_FRAME}>
        <dl className={cn(FACT_GRID, "@xs:grid-cols-2 @2xl:grid-cols-4")}>
          <div className={FACT_CELL}>
            <dt className={FACT_LABEL}>{t("repository")}</dt>
            <HoverHint content={repository ?? undefined}><dd className="truncate font-mono text-[13px] font-medium">
              {repoUrl ? (
                <a href={repoUrl} target="_blank" rel="noreferrer" className="text-primary underline-offset-4 hover:underline">
                  {repoName}
                </a>
              ) : (
                (repoName ?? "—")
              )}
            </dd></HoverHint>
          </div>
          <div className={FACT_CELL}>
            <dt className={FACT_LABEL}>{t("branch")}</dt>
            <dd className="truncate font-mono text-[13px] font-medium">{application.branch ?? "—"}</dd>
          </div>
          {commit ? (
            <div className={FACT_CELL}>
              <dt className={FACT_LABEL}>{t("commit")}</dt>
              <dd className="flex flex-wrap items-center gap-1.5">
                <HoverHint content={commit}><span className="font-mono text-[13px] font-medium">{commit.slice(0, 7)}</span></HoverHint>
                {incomplete ? (
                  <Badge variant="warning" className="font-normal">
                    {t("notFullyDeployed")}
                  </Badge>
                ) : null}
              </dd>
            </div>
          ) : null}
          <div className={FACT_CELL}>
            <dt className={FACT_LABEL}>{t("lastDeploy")}</dt>
            <dd className="text-sm font-medium">
              {application.last_deployed_at_human ?? application.last_deployed_at ?? t("never")}
            </dd>
          </div>
        </dl>
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
