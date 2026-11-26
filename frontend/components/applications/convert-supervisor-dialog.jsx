import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft } from "lucide-react";
import { convertApplicationSupervisor } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function ConvertSupervisorDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.process.convert");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  function handleOpenChange(next) {
    if (!next) setError(null);
    onOpenChange(next);
  }

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      await convertApplicationSupervisor(application.id);
      await refreshAndWait();
      toast.success(t("done", { name: application.name }));
      onOpenChange(false);
    } catch (requestError) {
      setError(apiMessage(requestError, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={ArrowRightLeft}
      tone="warning"
      title={t("title", { name: application?.name ?? "" })}
      description={t("description")}
      confirmLabel={pending ? t("converting") : t("confirm")}
      cancelLabel={t("cancel")}
      pending={pending}
      onConfirm={confirm}
      error={error}
    >
      <ul className="list-disc space-y-1.5 pl-4 text-sm text-muted-foreground">
        <li>{t("restarts")}</li>
        <li>{t("rollback")}</li>
        <li>{t("gains")}</li>
      </ul>
    </ConfirmDialog>
  );
}
