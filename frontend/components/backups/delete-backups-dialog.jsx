import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { Trash2 } from "lucide-react";
import { deleteBackups } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// Reports per outcome: a batch can partly succeed.
export function DeleteBackupsDialog({ open, onOpenChange, backups = [], onDeleted }) {
  const t = useTranslations("backups.history.delete");
  const [pending, setPending] = useState(false);
  // Refusals and their reasons, kept on screen rather than only in a toast.
  const [failures, setFailures] = useState([]);

  // Cleared here, not in onOpenChange: opening from the toolbar skips it.
  const [wasOpen, setWasOpen] = useState(open);
  if (wasOpen !== open) {
    setWasOpen(open);
    if (open) setFailures([]);
  }

  const count = backups.length;
  // Pre-restore safety copies: deleting them is allowed, but called out.
  const safetyCount = backups.filter((backup) => backup.is_safety).length;

  const { refreshAndWait } = useRefresh();

  async function confirm() {
    setPending(true);
    try {
      const { data } = await deleteBackups(backups.map((backup) => backup.id));
      const failed = Array.isArray(data?.failed) ? data.failed : [];
      const succeeded = Array.isArray(data?.succeeded) ? data.succeeded : [];

  // Successes are reported either way, so those rows leave the table now.
      onDeleted?.(succeeded, failed.map((entry) => entry.id));
      // Re-read the list before the toast, or deleted rows stay on screen.
      await refreshAndWait();

      if (failed.length === 0) {
        toast.success(t("done", { count: succeeded.length }));
        onOpenChange(false);
        return;
      }

      // Stays open listing the refusals; the selection now holds exactly those, so Delete retries them.
      setFailures(failed);
      if (succeeded.length === 0) toast.error(t("noneDeleted", { count: failed.length }));
      else toast.warning(t("partial", { done: succeeded.length, failed: failed.length }));
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

      {failures.length > 0 ? (
        <div className="space-y-2 rounded-lg border border-warning/30 bg-warning/5 p-3">
          <p className="text-sm font-medium">{t("failures.title")}</p>
          <ul className="space-y-2">
            {failures.map((entry) => {
              const backup = backups.find((item) => item.id === entry.id);
              // Literal keys so check-i18n can verify them.
              const reason =
                entry.reason === "running"
                  ? t("failures.reason.running")
                  : entry.reason === "artifact"
                    ? t("failures.reason.artifact")
                    : t("failures.reason.failed");

              return (
                <li key={entry.id} className="space-y-0.5 text-xs">
                  <p className="font-medium">
                    {/* Exact timestamp, not relative time, so two same-day backups are distinguishable. */}
                    {[backup?.type_title, backup?.created_at ?? backup?.created_at_human]
                      .filter(Boolean)
                      .join(" · ") || t("failures.unknownBackup")}
                  </p>
                  {/* An unrecognised reason falls back rather than rendering a key. */}
                  <p className="text-muted-foreground">{reason}</p>
                </li>
              );
            })}
          </ul>
        </div>
      ) : null}
    </ConfirmDialog>
  );
}
