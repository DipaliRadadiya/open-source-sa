import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteWorker } from "@/lib/api/workers";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";

// The API stops the systemd unit before deleting the row — the other order
// would leave a process running that nothing in the panel knows about — so a
// plain confirm is proportionate; there's no orphaned-process risk to spell out.
export function DeleteWorkerDialog({ worker, appId, open, onOpenChange }) {
  const t = useTranslations("applications.workers");
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteWorker(appId, worker.id);
      toast.success(t("toast.deleted"));
      onOpenChange?.(false);
      router.refresh();
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
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
