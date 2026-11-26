import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft } from "lucide-react";
import { convertApplicationSupervisor } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Move a site from the old panel's PM2 to a systemd unit. This restarts the application;
 * if it doesn't answer afterwards the API puts it back under PM2.
 */
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
      // Before the toast and the close: a conversion changes the supervisor
      // badge and the worker count on the card behind this dialog, both
      // server-rendered. Toasting first uncovers the old supervisor.
      await refreshAndWait();
      toast.success(t("done", { name: application.name }));
      onOpenChange(false);
    } catch (requestError) {
      // Stays open with the reason. The API's own sentence is better than
      // anything guessed here — it distinguishes "no entrypoint recorded" from
      // "the unit came up but would not serve", and those need different fixes.
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
