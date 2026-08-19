"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Archive, ArrowRight, Loader2, ShieldCheck, ShieldOff, PauseCircle } from "lucide-react";
import { runBackupNow } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Whether this site could be recovered, and the one action worth having here.
 *
 * The three states are the Backups screen's own vocabulary, deliberately —
 * "protected", "paused" and "not protected" have to mean the same thing in both
 * places or the panel contradicts itself. A target that exists but is switched
 * off, or set to manual, backs nothing up: that is `paused`, and it is the state
 * worth naming because it looks configured and is not.
 *
 * "Back up now" is here because it is the only backup action that needs no
 * decisions — one call, no form. Setting a schedule, choosing a destination or
 * restoring all involve choices, and those live on the Backups screen.
 */
export function BackupCard({ applicationId, target, failed = false, canManage, href }) {
  const t = useTranslations("applications.backups");
  const router = useRouter();
  const [running, setRunning] = useState(false);

  const state = !target
    ? "unprotected"
    : !target.enabled || target.frequency === "manual"
      ? "paused"
      : "protected";

  const meta = {
    protected: { icon: ShieldCheck, variant: "success" },
    paused: { icon: PauseCircle, variant: "warning" },
    unprotected: { icon: ShieldOff, variant: "secondary" },
  }[state];
  const Icon = meta.icon;

  async function backUpNow() {
    setRunning(true);
    try {
      await runBackupNow(applicationId);
      toast.success(t("started"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setRunning(false);
    }
  }

  return (
    <Card>
      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Archive className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>
        {failed ? null : (
          <Badge variant={meta.variant} className="w-fit gap-1.5 font-normal">
            <Icon className="size-3" />
            {t(`state.${state}`)}
          </Badge>
        )}
      </CardHeader>

      {/* gap rather than space-y: space-y sets margin-top on children via a
          compound selector that would outrank any margin set here. flex-1 lets
          the card fill its stretched row, and the leftover collects as padding
          under the last line — not as a gap above a button floated to the card
          foot, which read as a rendering fault on any site with little to
          report. */}
      <CardContent className="flex flex-1 flex-col gap-3">
        {/* A failed read is not "no backups configured" — that would tell
            somebody their site is unprotected on the evidence of one request. */}
        {failed ? (
          <p className="text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : (
          <dl className="space-y-1.5 text-sm">
            {target?.frequency_title ? (
              <div className="flex justify-between gap-3">
                <dt className="text-muted-foreground">{t("schedule")}</dt>
                <dd className="text-right">{target.frequency_title}</dd>
              </div>
            ) : null}
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">{t("lastRun")}</dt>
              {/* Never blank: an empty cell reads as a rendering fault, and
                  "never" is a real and important answer here. */}
              <dd className="text-right">{target?.last_run_at_human ?? t("never")}</dd>
            </div>
            {target?.next_run_at_human && state === "protected" ? (
              <div className="flex justify-between gap-3">
                <dt className="text-muted-foreground">{t("nextRun")}</dt>
                <dd className="text-right">{target.next_run_at_human}</dd>
              </div>
            ) : null}
          </dl>
        )}

        {/* The consequence, not the label. "Not protected" is a status; what it
            means is that if this site is lost there is nothing to put back, and
            that is the fact somebody needs before deciding it can wait. Paused
            gets its own line because it is the deceptive one — it looks set up,
            and the last copy is ageing. */}
        {!failed && state !== "protected" ? (
          <p className="text-sm text-muted-foreground">
            {state === "paused" ? t("pausedRisk") : t("unprotectedRisk")}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {canManage && target ? (
            <Button variant="outline" size="sm" disabled={running} onClick={backUpNow}>
              {running ? <Loader2 className="size-4 animate-spin" /> : null}
              {running ? t("starting") : t("backUpNow")}
            </Button>
          ) : null}
          {/* Setting one up is the point of the card when there is no target;
              a ghost link for the only thing worth doing here buries it. */}
          <Button asChild variant={!failed && !target ? "default" : "ghost"} size="sm">
            <Link href={href} prefetch={false}>
              {target ? t("manage") : t("setUp")}
              <ArrowRight className="size-4" />
            </Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
