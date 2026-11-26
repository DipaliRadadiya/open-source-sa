import { useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CheckCircle2, Loader2, XCircle } from "lucide-react";
import { runErrorText, stagingRunActive } from "@/lib/schemas/application-staging";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Caution } from "@/components/ui/caution";
import { Note } from "@/components/ui/note";

const Spinner = ({ className }) => <Loader2 className={`${className ?? ""} animate-spin`} />;

export function StagingRunStatus({ run, production, staging }) {
  const t = useTranslations("applications.staging.run");
  const previous = useRef(run);

  useEffect(() => {
    const before = previous.current;
    previous.current = run;
    if (!before || !run || before.id !== run.id || !stagingRunActive(before) || stagingRunActive(run)) return;
    const target = run.kind === "pull" ? staging?.domain : production?.domain;
    if (run.status === "failed") toast.error(t(`${run.kind === "pull" ? "pull" : "push"}Failed`, { domain: target ?? "" }));
    else toast.success(t(`${run.kind === "pull" ? "pull" : "push"}Done`, { domain: target ?? "" }));
  }, [run, production, staging, t]);

  if (!run) return null;
  const kind = run.kind === "pull" ? "pull" : "push";
  const target = kind === "pull" ? staging?.domain : production?.domain;

  if (stagingRunActive(run)) {
    return (
      <>
        <AutoRefresh intervalMs={3000} stopAfterMs={30 * 60 * 1000} />
        <Note icon={Spinner} title={t(`${kind}Running`, { domain: target ?? "" })}>
          {run.status === "queued" ? t("queued") : kind === "push" ? t("pushOffline", { domain: target ?? "" }) : t("running")}
        </Note>
      </>
    );
  }

  if (run.status === "failed") {
    return (
      <Caution tone="destructive" size="md">
        <div className="space-y-1">
          <p className="flex items-center gap-2 font-medium">
            <XCircle className="size-4 shrink-0" />
            {t(`${kind}FailedAt`, { domain: target ?? "", time: run.finished_at_human ?? "" })}
          </p>
          {runErrorText(run.error) ? <p>{runErrorText(run.error)}</p> : null}
          {run.error?.reference ? (
            <p className="font-mono text-xs">{t("reference", { reference: run.error.reference })}</p>
          ) : null}
        </div>
      </Caution>
    );
  }

  return (
    <p className="flex items-center gap-2 text-sm text-muted-foreground">
      <CheckCircle2 className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
      {t(`${kind}DoneAt`, { domain: target ?? "", time: run.finished_at_human ?? "" })}
    </p>
  );
}
