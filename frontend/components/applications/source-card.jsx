"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { GitBranch, Loader2, Rocket, Settings2, TriangleAlert, Webhook } from "lucide-react";
import { deployApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Git sites only — a one-click install has no repository to pull from.
 *
 * "Deploy" is the one action people come to this page for, so it sits here
 * rather than behind the ⋯ menu. A failed redeploy leaves the old code serving,
 * so the card reports the last successful deploy, not "broken".
 */
export function SourceCard({ application, canDeploy = false, className }) {
  const t = useTranslations("applications.source");
  const router = useRouter();
  const [deploying, setDeploying] = useState(false);

  const commit =
    typeof application.last_commit === "string"
      ? application.last_commit
      : (application.last_commit?.sha ?? application.last_commit?.hash ?? null);
  const repository = application.repository ?? application.repository_url;
  const pushToDeploy = application.webhook?.enabled;
  // The site is active, so the OLD code is still serving — this is a deploy
  // warning, not an outage. Saying so is the whole point of the card.
  const deployFailed = application.status === "active" && Boolean(application.failed_step);

  async function deploy() {
    setDeploying(true);
    try {
      await deployApplication(application.id);
      toast.info(t("started"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setDeploying(false);
    }
  }

  return (
    <Card className={className}>
      {/* Stacks on a phone. Side by side, the buttons are shrink-0 and take
          ~300px of a 358px line, leaving the title about 40px — which does not
          wrap, it collapses to one word per line, and the buttons overflow the
          card anyway. min-w-48 rather than min-w-0 on the text: min-w-0 lets it
          shrink to nothing, which is what allowed the squeeze in the first
          place. */}
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div className="min-w-48 flex-1 space-y-1.5">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <GitBranch className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
          {/* Under the subtitle, with the other cards' badges. It states a fact
              about the card, not an action you can take — grouping it with the
              buttons implied it was one of them. */}
          {pushToDeploy ? (
            <Badge variant="secondary" className="w-fit gap-1.5 font-normal">
              <Webhook className="size-3" />
              {t("pushToDeploy")}
            </Badge>
          ) : null}
        </div>
        {/* Actions in the header, not under the facts. This card is a
            full-width band, so buttons at the foot sat alone on a 1200px line
            with the whole row empty beside them. In the header they land where
            the eye already is after the title. */}
        <div className="flex flex-wrap items-center gap-2 sm:shrink-0">
          {canDeploy ? (
            <Button size="sm" onClick={deploy} disabled={deploying}>
              {deploying ? <Loader2 className="size-4 animate-spin" /> : <Rocket className="size-4" />}
              {deploying ? t("deploying") : deployFailed ? t("redeploy") : t("deploy")}
            </Button>
          ) : null}
          <Button variant="outline" size="sm" asChild>
            <Link href={`/applications/${application.id}/deployment`}>
              <Settings2 className="size-4" />
              {t("manage")}
            </Link>
          </Button>
        </div>
      </CardHeader>
      {/* gap rather than space-y: space-y sets margin-top on children via a
          compound selector that would outrank any margin set here. flex-1 lets
          the card fill its stretched row, and the leftover collects as padding
          under the last line — not as a gap above a button floated to the card
          foot, which read as a rendering fault on any site with little to
          report. */}
      <CardContent className="flex flex-1 flex-col gap-3">
        {deployFailed ? (
          <div
            role="alert"
            className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2.5 text-sm text-warning"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div className="space-y-0.5">
              <p>{t("failedAt", { step: application.failed_step })}</p>
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
              <p className="font-mono text-xs">{commit.slice(0, 12)}</p>
            </div>
          ) : null}
        </div>

      </CardContent>
    </Card>
  );
}
