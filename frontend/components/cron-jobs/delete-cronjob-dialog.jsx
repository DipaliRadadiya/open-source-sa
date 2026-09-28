import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteCronjob } from "@/lib/api/cronjobs";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// No type-the-name gate here (unlike system users): deleting a cron job removes
// a schedule, not an account and its data, and it's re-creatable from the row's
// own values. The confirm step alone is proportionate.
export function DeleteCronjobDialog({ job, open, onOpenChange }) {
  const t = useTranslations("cronJobs");
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    const done = (say) =>
      refreshThen(() => {
        say();
        onOpenChange?.(false);
        setPending(false);
      });
    try {
      await deleteCronjob(job.id);
      done(() => toast.success(t("toast.deleted")));
    } catch (error) {
      // Removed from another tab: the outcome asked for is already true.
      if (error?.response?.status === 404) {
        done(() => toast.info(t("toast.alreadyGone", { name: job.name })));
        return;
      }
      toast.error(apiMessage(error, t("toast.failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("delete.title")}
      description={t("delete.description", { name: job.name })}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
