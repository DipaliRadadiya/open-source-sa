"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteCronjob } from "@/lib/api/cronjobs";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";

// No type-the-name gate here (unlike system users): deleting a cron job removes
// a schedule, not an account and its data, and it's re-creatable from the row's
// own values. The confirm step alone is proportionate.
export function DeleteCronjobDialog({ job, open, onOpenChange }) {
  const t = useTranslations("cronJobs");
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteCronjob(job.id);
      toast.success(t("toast.deleted"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("toast.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("delete.title")}
      description={t("delete.description", { name: job.name })}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
