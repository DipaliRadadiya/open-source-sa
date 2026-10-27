import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Play } from "lucide-react";
import { runCronjob } from "@/lib/api/cronjobs";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// A confirm, not a one-click item: it runs a real command off schedule, as the job's account.
export function RunNowDialog({ job, open, onOpenChange }) {
  const t = useTranslations("cronJobs");
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await runCronjob(job.id);
      // The list then shows Running now and re-reads itself until the run ends.
      refreshThen(() => {
        toast.success(t("runNow.started", { name: job.name }));
        onOpenChange?.(false);
        setPending(false);
      });
    } catch (error) {
      // Paused, account gone, already running: the API's own sentence says which.
      toast.error(apiMessage(error, t("runNow.failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => !pending && onOpenChange?.(next)}
      icon={Play}
      title={t("runNow.title", { name: job.name })}
      description={t("runNow.description", { user: job.username })}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("runNow.starting") : t("runNow.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    >
      <p className="rounded-lg border bg-muted/40 px-3 py-2.5 font-mono text-xs break-all">{job.command}</p>
    </ConfirmDialog>
  );
}
