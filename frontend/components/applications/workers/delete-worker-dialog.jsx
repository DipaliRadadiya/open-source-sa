import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteWorker } from "@/lib/api/workers";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// The API stops the supervisord program before deleting the row, so a plain
// confirm is enough (no orphaned process).
export function DeleteWorkerDialog({ worker, appId, open, onOpenChange }) {
  const t = useTranslations("applications.workers");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteWorker(appId, worker.id);
      // Closed once the list has re-read, not on the API's answer.
      refreshThen(() => {
        toast.success(t("toast.deleted"));
        onOpenChange?.(false);
      });
    } catch (error) {
      toast.error(apiMessage(error, t("toast.deleteFailed")));
    } finally {
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
      description={t("delete.description", { name: worker.name })}
      cancelLabel={t("cancel")}
      confirmLabel={pending || refreshing ? t("delete.deleting") : t("delete.confirm")}
      pending={pending || refreshing}
      onConfirm={onConfirm}
    />
  );
}
