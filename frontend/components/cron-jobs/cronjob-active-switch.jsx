import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { setCronjobActive } from "@/lib/api/cronjobs";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

// Inline pause/resume. Deactivating removes the cron.d file server-side but
// keeps the row, so it's reversible — no confirmation needed either way.
export function CronjobActiveSwitch({ job, canManage = true, prevPage = null }) {
  const t = useTranslations("cronJobs");
  const { refresh, refreshThen, navigateThen } = useRefresh();
  const statusFilter = useSearchParams().get("active");
  const [busy, setBusy] = useState(false);
  // The requested value, until the server agrees with it.
  const [asked, setAsked] = useState(null);

  // Show the requested value during the round trip. Derived rather than
  // cleared in an effect, so once the server agrees, changes made elsewhere show through.
  const shown = asked !== null && asked !== job.active ? asked : job.active;

  async function onToggle(next) {
    setBusy(true);
    setAsked(next);
    try {
      await setCronjobActive(job.id, next);
      // Toast once the row has refreshed to show the change.
      const after = () => {
        toast.success(next ? t("toast.resumed") : t("toast.paused"));
        setBusy(false);
      };
      // Switched out of the status filter as the page's only row: the page it
      // leaves is empty, so go to the one before instead of redirecting there.
      const leavesPage = prevPage && statusFilter !== null && statusFilter !== String(next);
      if (leavesPage) navigateThen({ page: prevPage > 1 ? prevPage : undefined }, after);
      else refreshThen(after);
    } catch (error) {
      // Put the knob back where it was: the change did not happen.
      setAsked(null);
      setBusy(false);
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { name: job.name }));
        refresh();
        return;
      }
      toast.error(apiMessage(error, next ? t("toast.resumeFailed") : t("toast.pauseFailed")));
    }
  }

  return (
    <PendingSwitch
      checked={shown}
      pending={busy}
      disabled={!canManage}
      // Only for missing permission; while busy the switch is mid-request.
      disabledReason={canManage ? undefined : t("noPermission")}
      onCheckedChange={canManage ? onToggle : undefined}
      // Names the job so screen readers can tell the switches apart.
      aria-label={t("activeFor", { name: job.name })}
    />
  );
}
