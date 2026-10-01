import { useTranslations } from "next-intl";
import { CircleCheck, TriangleAlert, CircleMinus, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";

// `degraded` (some processes down) is deliberately a warning, not success.
const STATE_META = {
  running: { icon: CircleCheck, variant: "success" },
  degraded: { icon: TriangleAlert, variant: "warning" },
  stopped: { icon: CircleMinus, variant: "outline" },
};

export function WorkerStatusBadge({ worker, busyAction }) {
  const t = useTranslations("applications.workers");

  if (busyAction) {
    return (
      <Badge variant="outline" className="gap-1.5 font-normal text-muted-foreground">
        <Loader2 className="size-3 animate-spin" />
        {t(`busy.${busyAction}`)}
      </Badge>
    );
  }

  const meta = STATE_META[worker.state] ?? STATE_META.stopped;
  const Icon = meta.icon;
  // Process counts for running/degraded, skipped for single-process workers.
  const showCount = worker.processes > 1 && (worker.state === "running" || worker.state === "degraded");

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Badge variant={meta.variant} className="gap-1.5 font-normal">
        <Icon className="size-3" />
        {worker.state_title ?? t(`state.${worker.state}`)}
        {showCount ? (
          <span className="tabular-nums opacity-90">
            {t("processCount", { running: worker.running, total: worker.processes })}
          </span>
        ) : null}
      </Badge>
      {!worker.enabled ? (
        <Badge variant="outline" className="font-normal text-muted-foreground">
          {t("disabledTag")}
        </Badge>
      ) : null}
      {/* Flagged only when OFF, the risky non-default. */}
      {!worker.restart_on_deploy ? (
        <Badge variant="warning" className="font-normal">
          {t("noRestartOnDeployTag")}
        </Badge>
      ) : null}
      {!worker.auto_restart ? (
        <Badge variant="warning" className="font-normal">
          {t("noAutoRestartTag")}
        </Badge>
      ) : null}
    </div>
  );
}
