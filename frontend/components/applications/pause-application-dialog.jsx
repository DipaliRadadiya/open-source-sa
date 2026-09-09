import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { PauseCircle } from "lucide-react";
import { disableApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Pausing turns real visitors away, so it asks first.
 *
 * What it does NOT do is the half people assume: nothing is deleted, moved or
 * stopped. The web server is pointed at a holding page and reloaded, and
 * resuming points it back. So the dialog leads with what stays, because the
 * word "pause" next to a site is read as "take it down" and the fear is of
 * losing something.
 *
 * The search-engine line is not padding. Plesk splits this into two features
 * for exactly this reason — its *suspended* state answers 503, which search
 * engines read as "come back later" and rankings survive, while its *disabled*
 * state serves an ordinary page and rankings drop. Ours serves the holding page
 * as a normal 200, so it behaves like the second one while being called the
 * first. Until the API answers 503, saying so is the only honest option:
 * somebody pausing a site for an hour is fine, and somebody pausing it for a
 * month should know what they are trading.
 *
 * Resuming has no dialog of its own — it restores normal service, and there is
 * nothing to warn about in putting a site back.
 */
export function PauseApplicationDialog({ application, open, onOpenChange }) {
  const t = useTranslations("applications.pause");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  function handleOpenChange(next) {
    // Cleared at the site that opens it too: a dialog reopened from its own
    // button never runs this, and would show the last failure over a fresh try.
    if (!next) setError(null);
    onOpenChange(next);
  }

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      await disableApplication(application.id);
      toast.success(t("paused", { name: application.name }));
      onOpenChange(false);
      router.refresh();
    } catch (requestError) {
      // Stays open, carrying the reason. A 422 here usually means somebody
      // already paused it in another tab, and the API's own sentence says so
      // better than anything this component could guess.
      setError(apiMessage(requestError, t("failed")));
    } finally {
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
