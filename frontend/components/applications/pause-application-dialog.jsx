import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { PauseCircle } from "lucide-react";
import { disableApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function PauseApplicationDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.pause");
  const { refreshThen } = useRefresh();
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
      await disableApplication(application.id);
      refreshThen(() => {
        toast.success(t("paused", { name: application.name }));
        onOpenChange(false);
        setPending(false);
      });
    } catch (requestError) {
      setError(apiMessage(requestError, t("failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={PauseCircle}
      tone="warning"
      title={t("title", { name: application?.name ?? "" })}
      description={t("description")}
      confirmLabel={pending ? t("pausing") : t("confirm")}
      cancelLabel={t("cancel")}
      pending={pending}
      onConfirm={confirm}
      error={error}
    >
      <ul className="list-disc space-y-1.5 pl-4 text-sm text-muted-foreground">
        <li>{t("keeps")}</li>
        <li>{t("reversible")}</li>
        <li>{t("seo")}</li>
      </ul>
    </ConfirmDialog>
  );
}
