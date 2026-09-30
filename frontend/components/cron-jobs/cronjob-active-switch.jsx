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
  // The value we asked for, until the server agrees with it.
  const [asked, setAsked] = useState(null);

  // This used to sit on `job.active` alone, and that only changes when
  // `router.refresh()` lands — so the knob stayed where it was for the whole
  // round trip while the switch sat disabled. Faded and unmoved is what a
  // click that did nothing looks like.
  //
  // Derived, not cleared in an effect: once the server catches up `asked`
  // equals `job.active` and stops mattering by itself, so a change made
  // elsewhere shows through instead of being masked by a stale override.
  const shown = asked !== null && asked !== job.active ? asked : job.active;

  async function onToggle(next) {
    setBusy(true);
    setAsked(next);
    try {
      await setCronjobActive(job.id, next);
      // Said once the row shows it: the Paused badge and the next run change
      // with the refresh, and a toast ahead of them read as a claim not yet true.
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
      toast.error(apiMessage(error, t("toast.failed")));
    }
  }

  return (
    <PendingSwitch
      checked={shown}
      pending={busy}
      disabled={!canManage}
      // Only when the permission is what stops them. While busy the switch is
      // mid-request, and "your role does not include…" would be a lie.
      disabledReason={canManage ? undefined : t("noPermission")}
      onCheckedChange={canManage ? onToggle : undefined}
      // Every row's switch was just "Active": a screen reader could not say whose.
      aria-label={t("activeFor", { name: job.name })}
    />
  );
}
