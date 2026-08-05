"use client";

import { useTranslations } from "next-intl";
import {
  CheckCircle2,
  GitBranch,
  GitCommitHorizontal,
  Info,
  Loader2,
  Rocket,
  SquareArrowOutUpRight,
  TriangleAlert,
} from "lucide-react";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// Backend writes raw step keys ("set_ownership"); no frontend catalog exists for
// them, so humanise rather than risk a missing-message throw.
function humanizeStep(step) {
  const words = String(step).replace(/[._]+/g, " ").trim();
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : step;
}

// Best-effort link to the commit on the provider, built from a public repo URL.
// Only the hosts whose commit paths we actually know; anything else falls back
// to the copyable SHA. Account-based repos have no URL here, so no link — never
// a guessed one.
function commitUrl(repositoryUrl, sha) {
  if (!repositoryUrl || !sha) return null;
  try {
    const u = new URL(repositoryUrl);
    const path = u.pathname.replace(/\.git$/, "").replace(/^\/+|\/+$/g, "");
    if (!path) return null;
    if (u.hostname.includes("github.")) return `${u.origin}/${path}/commit/${sha}`;
    if (u.hostname.includes("gitlab.")) return `${u.origin}/${path}/-/commit/${sha}`;
    if (u.hostname.includes("bitbucket."))
      return `${u.origin}/${path}/commits/${sha}`;
    return null;
  } catch {
    return null;
  }
}

function Fact({ label, children }) {
  return (
    <div className="min-w-0 space-y-1">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="min-w-0">{children}</dd>
    </div>
  );
}

export function DeployCard({ application, deploying, canManage, onDeploy }) {
  const t = useTranslations("applications.deployment");
  const commit =
    typeof application.last_commit === "string" ? application.last_commit : null;
  const repository = application.repository ?? application.repository_url;
  const branch = application.branch ?? "main";
  // The site is serving the OLD code, so a set failed_step is a deploy warning,
  // not an outage — saying that plainly is the point.
  const deployFailed =
    application.status === "active" && Boolean(application.failed_step);
  // A git app is created "active" serving a placeholder; until the first deploy
  // there is no code. Say what to do rather than showing a blank "—".
  const neverDeployed = !application.last_deployed_at && !commit;
  const commitHref = commitUrl(application.repository_url, commit);
  const steps = application.steps ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("deploy.title")}</CardTitle>
        <CardDescription>{t("deploy.subtitle")}</CardDescription>
        {canManage ? (
          <CardAction>
            <Button onClick={onDeploy} disabled={deploying}>
              {deploying ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <Rocket className="size-4" />
              )}
              {deploying
                ? t("deploy.deploying")
                : deployFailed
                  ? t("deploy.redeploy")
                  : t("deploy.action")}
            </Button>
          </CardAction>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-4">
        {deployFailed ? (
          <div
            role="alert"
            className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-sm text-warning"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-0.5">
              <p>{t("deploy.failedAt", { step: application.failed_step })}</p>
              {application.reference ? (
                <p className="font-mono text-xs opacity-90">
                  {t("deploy.reference", { reference: application.reference })}
                </p>
              ) : null}
            </div>
          </div>
        ) : neverDeployed && !deploying ? (
          <div className="flex items-start gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5 text-sm text-foreground">
            <Info className="mt-0.5 size-4 shrink-0 text-primary" />
            <p>{t("deploy.notDeployedYet", { branch })}</p>
          </div>
        ) : null}

        {/* Four facts across the full width instead of two hugging the left. */}
        <dl className="grid grid-cols-1 gap-4 rounded-lg border bg-muted/30 p-4 sm:grid-cols-2 md:grid-cols-4">
          <Fact label={t("deploy.repository")}>
            {repository ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span
                    tabIndex={0}
                    className="block truncate font-mono text-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                  >
                    {repository}
                  </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-sm font-mono text-xs break-all">
                  {repository}
                </TooltipContent>
              </Tooltip>
            ) : (
              <span className="block truncate font-mono text-xs">—</span>
            )}
          </Fact>
          <Fact label={t("deploy.branch")}>
            <span className="inline-flex items-center gap-1.5 font-mono text-xs">
              <GitBranch className="size-3.5 text-muted-foreground" />
              {branch}
            </span>
          </Fact>
          <Fact label={t("deploy.commit")}>
            {commit ? (
              <span className="flex flex-wrap items-center gap-1.5">
                <GitCommitHorizontal className="size-3.5 text-muted-foreground" />
                {commitHref ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <a
                        href={commitHref}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 font-mono text-xs text-primary underline-offset-2 hover:underline"
                      >
                        {commit.slice(0, 10)}
                        <SquareArrowOutUpRight className="size-3" />
                      </a>
                    </TooltipTrigger>
                    <TooltipContent>{t("deploy.viewCommit")}</TooltipContent>
                  </Tooltip>
                ) : (
                  <span className="font-mono text-xs">{commit.slice(0, 10)}</span>
                )}
                <CopyButton value={commit} className="size-6" />
              </span>
            ) : (
              <span className="text-sm text-muted-foreground">—</span>
            )}
          </Fact>
          <Fact label={t("deploy.lastDeploy")}>
            {application.last_deployed_at ? (
              <Tooltip>
                <TooltipTrigger asChild>
                  <span
                    tabIndex={0}
                    className="text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                  >
                    {application.last_deployed_at_human ?? application.last_deployed_at}
                  </span>
                </TooltipTrigger>
                <TooltipContent>{application.last_deployed_at}</TooltipContent>
              </Tooltip>
            ) : (
              <span className="text-sm font-medium">{t("deploy.never")}</span>
            )}
          </Fact>
        </dl>

        {deploying ? (
          <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
            <p className="flex items-center gap-2 text-sm font-medium">
              <Loader2 className="size-4 animate-spin" />
              {t("deploy.inProgress")}
            </p>
            {steps.length ? (
              <ol className="grid gap-1.5 sm:grid-cols-2">
                {steps.map((step) => (
                  <li
                    key={step}
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                  >
                    <CheckCircle2 className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span>{humanizeStep(step)}</span>
                  </li>
                ))}
              </ol>
            ) : null}
          </div>
        ) : null}

        {/* A deploy is fetch + reset --hard, so edits made on the server are
            discarded. Say so before the surprise, not after. */}
        {canManage && !deploying ? (
          <p className="text-xs text-muted-foreground">
            {t("deploy.resetNote", { branch })}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}
