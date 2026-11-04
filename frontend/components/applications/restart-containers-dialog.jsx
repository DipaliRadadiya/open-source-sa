import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { RotateCw } from "lucide-react";
import { controlApplicationProcess } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// Docker apps only: the API runs `docker compose restart` on every container of the app.
export function RestartContainersDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.restart");
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
      await controlApplicationProcess(application.id, "restart");
      refreshThen(() => {
        toast.success(t("restarted", { name: application.name }));
        onOpenChange(false);
        setPending(false);
      });
    } catch (requestError) {
      // The 422s (paused, not ready, Docker down) carry a localized reason.
      setError(apiMessage(requestError, t("failed")));
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={RotateCw}
      tone="warning"
      title={t("title", { name: application?.name ?? "" })}
      description={t("description")}
      confirmLabel={pending ? t("restarting") : t("confirm")}
      cancelLabel={t("cancel")}
      pending={pending}
      onConfirm={confirm}
      error={error}
    >
      <ul className="list-disc space-y-1.5 pl-4 text-sm text-muted-foreground">
        <li>{t("keeps")}</li>
        <li>{t("brief")}</li>
      </ul>
    </ConfirmDialog>
  );
}
