import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { setCronjobActive } from "@/lib/api/cronjobs";
import { PendingSwitch } from "@/components/ui/pending-switch";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";

export function CronjobActiveSwitch({ job, canManage = true, prevPage = null }) {
  const t = useTranslations("cronJobs");
  const { refresh, refreshThen, navigateThen } = useRefresh();
  const statusFilter = useSearchParams().get("active");
  const [busy, setBusy] = useState(false);
  const [asked, setAsked] = useState(null);

  const shown = asked !== null && asked !== job.active ? asked : job.active;

  async function onToggle(next) {
    setBusy(true);
    setAsked(next);
    try {
      await setCronjobActive(job.id, next);
      const after = () => {
        toast.success(next ? t("toast.resumed") : t("toast.paused"));
        setBusy(false);
      };
      const leavesPage = prevPage && statusFilter !== null && statusFilter !== String(next);
      if (leavesPage) navigateThen({ page: prevPage > 1 ? prevPage : undefined }, after);
      else refreshThen(after);
    } catch (error) {
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
    <DisabledReasonProvider denied={!canManage}>
      <PendingSwitch
        readOnly={!canManage}
        checked={shown}
        pending={busy}
        disabled={!canManage}
        onCheckedChange={canManage ? onToggle : undefined}
        aria-label={t("activeFor", { name: job.name })}
      />
    </DisabledReasonProvider>
  );
}
