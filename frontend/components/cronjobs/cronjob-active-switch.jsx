import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { setCronjobActive } from "@/lib/api/cronjobs";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { apiMessage } from "@/lib/api/error-message";

// Inline pause/resume. Deactivating removes the cron.d file server-side but
// keeps the row, so it's reversible — no confirmation needed either way.
export function CronjobActiveSwitch({ job, canManage = true }) {
  const t = useTranslations("cronJobs");
  const router = useRouter();
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
      toast.success(next ? t("toast.resumed") : t("toast.paused"));
      router.refresh();
    } catch (error) {
      // Put the knob back where it was: the change did not happen.
      setAsked(null);
      toast.error(apiMessage(error, t("toast.failed")));
    } finally {
      setBusy(false);
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
      aria-label={t("columns.active")}
    />
  );
}
