import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ArrowRightLeft } from "lucide-react";
import { switchApplicationCronjobsPhp } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function SwitchPhpDialog({ job, version, onOpenChange }) {
  const t = useTranslations("cronJobs");
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  async function onConfirm() {
    setPending(true);
    setError(null);
    try {
      await switchApplicationCronjobsPhp(job.application_id, { version, cronjobIds: [job.id] });
      refreshThen(() => {
        toast.success(t("phpMismatch.switched", { name: job.name, version }));
        onOpenChange?.(false);
      });
    } catch (err) {
      setError(apiMessage(err, t("phpMismatch.failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open
      onOpenChange={(next) => !next && !pending && onOpenChange?.(false)}
      icon={ArrowRightLeft}
      title={t("phpMismatch.switchTitle", { name: job.name, version })}
      description={t("phpMismatch.switchDescription")}
      cancelLabel={t("cancel")}
      confirmLabel={t("phpMismatch.switchAction", { version })}
      pending={pending}
      error={error}
      onConfirm={onConfirm}
    >
      <p className="rounded-lg border bg-muted/40 px-3 py-2.5 font-mono text-xs break-all">{job.command}</p>
    </ConfirmDialog>
  );
}
