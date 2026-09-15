import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRightLeft } from "lucide-react";
import { convertApplicationSupervisor } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Moving a site off the old panel's PM2 and onto a systemd unit.
 *
 * Adoption leaves migrated sites exactly where it found them — running under
 * the PM2 daemon that was already running them — because taking a server over
 * must not restart anybody's site. This is the separate, deliberate choice to
 * move one, and the reason it needs a dialog at all is that **it restarts the
 * application**. There is no zero-downtime path: one supervisor has to release
 * the port before the other can bind it.
 *
 * So the dialog leads with the restart rather than burying it, and then says
 * the thing that makes it safe — the API asks the application for a page
 * afterwards and puts it back under PM2 if it does not answer. A failure here
 * means the site is still up, on the supervisor it started on.
 *
 * The gains are listed second, and honestly: they are real but none of them is
 * urgent, which is exactly why nobody should feel pushed into this.
 */
export function ConvertSupervisorDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.process.convert");
  const router = useRouter();
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
      toast.success(t("done", { name: application.name }));
      onOpenChange(false);
      router.refresh();
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
