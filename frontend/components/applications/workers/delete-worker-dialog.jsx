import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteWorker } from "@/lib/api/workers";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// The API stops the supervisord program before deleting the row — the other order
// would leave a process running that nothing in the panel knows about — so a
// plain confirm is proportionate; there's no orphaned-process risk to spell out.
export function DeleteWorkerDialog({ worker, appId, open, onOpenChange }) {
  const t = useTranslations("applications.workers");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteWorker(appId, worker.id);
      // Closed once the list has re-read, not on the API's answer: closing
      // first left the deleted row on screen for a second and a half under a
      // toast saying it was gone.
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
