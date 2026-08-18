"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { setCronjobActive } from "@/lib/api/cronjobs";
import { Switch } from "@/components/ui/switch";
import { apiMessage } from "@/lib/api/error-message";

// Inline pause/resume. Deactivating removes the cron.d file server-side but
// keeps the row, so it's reversible — no confirmation needed either way.
export function CronjobActiveSwitch({ job, canManage = true }) {
  const t = useTranslations("cronJobs");
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  async function onToggle(next) {
    setBusy(true);
    try {
      await setCronjobActive(job.id, next);
      toast.success(next ? t("toast.resumed") : t("toast.paused"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("toast.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Switch
      checked={job.active}
      disabled={busy || !canManage}
      // Only when the permission is what stops them. While `busy` the switch is
      // mid-request, and "your role does not include…" would be a lie.
      disabledReason={canManage ? undefined : t("noPermission")}
      onCheckedChange={canManage ? onToggle : undefined}
      aria-label={t("columns.active")}
    />
  );
}
