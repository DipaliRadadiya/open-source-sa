"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft, CircleAlert, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { completeSyncHandover } from "@/lib/api/sync";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// Shown only while the server says the move off the old agent is unfinished (FS-C26).
export function HandoverCard({ status, canManage }) {
  const t = useTranslations("sync.handover");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  // Steps that failed in the last attempt, from the 500's own results.
  const [failed, setFailed] = useState([]);

  const left = [
    ...(status.agent?.running ? [t("agentRunning", { unit: status.agent.unit ?? "pm2" })] : []),
    ...status.users.flatMap((user) => [
      ...(user.boot_unit_healthy === false ? [t("bootMissing", { username: user.username })] : []),
      ...(user.log_rotation === false ? [t("rotationMissing", { username: user.username })] : []),
    ]),
  ];

  async function complete() {
    setPending(true);
    setFailed([]);
    try {
      await completeSyncHandover();
      await refreshAndWait();
      toast.success(t("done"));
    } catch (error) {
      const result = error.response?.data;
      const steps = [
        ...(result?.agent?.reference ? [t("agentFailed", { unit: result.agent.unit ?? "pm2" })] : []),
        ...(result?.users ?? []).flatMap((user) => [
          ...(user.boot_unit === "failed" ? [t("bootFailed", { username: user.username })] : []),
          ...(user.log_rotation === "failed" ? [t("rotationFailed", { username: user.username })] : []),
        ]),
      ];
      if (steps.length) setFailed(steps);
      else toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <CardContent className="space-y-3 px-5 py-4">
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-warning/10 text-warning">
            <ArrowRightLeft className="size-4.5" aria-hidden />
          </span>
          <div className="min-w-48 flex-1 space-y-1">
            <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
            <p className="text-sm text-muted-foreground">{t("body")}</p>
          </div>
        </div>
        {left.length ? (
          <ul className="ms-12 list-disc space-y-1 ps-4 text-sm">
            {left.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        ) : null}
        {failed.length ? (
          <div role="alert" className="ms-12 space-y-1 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
            <p className="flex items-center gap-2 font-medium text-destructive">
              <CircleAlert className="size-4" aria-hidden />
              {t("partial")}
            </p>
            <ul className="list-disc ps-6">
              {failed.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          </div>
        ) : null}
      </CardContent>
      <CardFooter className="justify-end border-t bg-muted/30 py-3">
        <ReasonTooltip reason={canManage ? null : t("noPermission")}>
          <Button onClick={complete} disabled={!canManage || pending}>
            {pending ? <Loader2 className="size-4 animate-spin" /> : null}
            {pending ? t("working") : t("action")}
          </Button>
        </ReasonTooltip>
      </CardFooter>
    </Card>
  );
}
