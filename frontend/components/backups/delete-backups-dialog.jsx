"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Trash2 } from "lucide-react";
import { deleteBackups } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Confirms deleting one or more backups, and reports what actually happened.
 *
 * A backup is the thing somebody reaches for when everything else has already
 * gone wrong, so this says plainly that the archive goes too — the record
 * disappearing is the visible half, the object leaving the customer's bucket
 * is the half that cannot be undone.
 *
 * The result is reported per outcome rather than as success or failure,
 * because a batch genuinely half-works: one backup mid-run refuses while the
 * other nineteen delete. Calling that a failure invites the user to repeat
 * nineteen deletions that already happened; calling it a success hides the
 * archive still sitting in the bucket being paid for.
 */
export function DeleteBackupsDialog({ open, onOpenChange, backups = [], onDeleted }) {
  const t = useTranslations("backups.history.delete");
  const [pending, setPending] = useState(false);

  const count = backups.length;
  // The automatic copy taken before a restore — the parachute for a restore
  // that goes wrong. Deleting one is allowed and sometimes right, but it
  // deserves saying out loud rather than being counted in silently.
  const safetyCount = backups.filter((backup) => backup.is_safety).length;

  async function confirm() {
    setPending(true);
    try {
      const { data } = await deleteBackups(backups.map((backup) => backup.id));
      const failed = Array.isArray(data?.failed) ? data.failed : [];
      const succeeded = Array.isArray(data?.succeeded) ? data.succeeded : [];

      if (failed.length === 0) toast.success(t("done", { count: succeeded.length }));
      else if (succeeded.length === 0) toast.error(t("noneDeleted", { count: failed.length }));
      else toast.warning(t("partial", { done: succeeded.length, failed: failed.length }));

      onOpenChange(false);
      onDeleted?.(succeeded);
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Trash2}
      tone="destructive"
      title={t("title", { count })}
      description={t("description", { count })}
      confirmLabel={pending ? t("deleting") : t("confirm")}
      confirmVariant="destructive"
      pending={pending}
      onConfirm={confirm}
    >
      {safetyCount > 0 ? (
        <p className="text-sm font-medium text-destructive">
          {t("safetyWarning", { count: safetyCount })}
        </p>
      ) : null}
    </ConfirmDialog>
  );
}
