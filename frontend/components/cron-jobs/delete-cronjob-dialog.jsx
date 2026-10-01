import { useRef, useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteCronjob } from "@/lib/api/cronjobs";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// No type-the-name gate (unlike system users): a cron job is easily
// re-created, so a plain confirm is proportionate.
export function DeleteCronjobDialog({ job, open, onOpenChange, prevPage = null }) {
  const t = useTranslations("cronJobs");
  const { refreshThen, navigateThen } = useRefresh();
  const [pending, setPending] = useState(false);
  // The row, and the ⋯ that opened this, are gone once it is deleted.
  const removed = useRef(false);

  async function onConfirm() {
    setPending(true);
    const done = (say) => {
      const after = () => {
        removed.current = true;
        say();
        onOpenChange?.(false);
        setPending(false);
      };
      if (prevPage) navigateThen({ page: prevPage > 1 ? prevPage : undefined }, after);
      else refreshThen(after);
    };
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
      onCloseAutoFocus={(event) => {
        if (!removed.current) return;
        removed.current = false;
        event.preventDefault();
        document.querySelector("[data-cron-add]")?.focus();
      }}
    />
  );
}
