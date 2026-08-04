"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { GitBranch, Loader2, Rocket, Webhook } from "lucide-react";
import { deployApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Git sites only — a one-click install has no repository to pull from.
 *
 * "Deploy" is the one action people come to this page for, so it sits here
 * rather than behind the ⋯ menu. A failed redeploy leaves the old code serving,
 * so the card reports the last successful deploy, not "broken".
 */
export function SourceCard({ application, canDeploy = false, preview = false }) {
  const t = useTranslations("applications.source");
  const previewNotice = useTranslations("applications.preview")("actionsDisabled");
  const router = useRouter();
  const [deploying, setDeploying] = useState(false);

  const commit =
    typeof application.last_commit === "string"
      ? application.last_commit
      : (application.last_commit?.sha ?? application.last_commit?.hash ?? null);
  const repository = application.repository ?? application.repository_url;
  const pushToDeploy = application.webhook?.enabled;

  async function deploy() {
    if (preview) {
      toast.info(previewNotice);
      return;
    }
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
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
        <CardTitle>{t("title")}</CardTitle>
        {pushToDeploy ? (
          <Badge variant="secondary" className="gap-1.5 font-normal">
            <Webhook className="size-3" />
            {t("pushToDeploy")}
          </Badge>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 text-sm sm:grid-cols-2">
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t("repository")}</p>
            <p className="break-all font-mono text-xs">{repository ?? "—"}</p>
          </div>
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{t("branch")}</p>
            <p className="inline-flex items-center gap-1.5 font-mono text-xs">
              <GitBranch className="size-3.5 text-muted-foreground" />
              {application.branch ?? "—"}
            </p>
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

        {canDeploy ? (
          <Button onClick={deploy} disabled={deploying}>
            {deploying ? <Loader2 className="size-4 animate-spin" /> : <Rocket className="size-4" />}
            {deploying ? t("deploying") : t("deploy")}
          </Button>
        ) : null}
      </CardContent>
    </Card>
  );
}
