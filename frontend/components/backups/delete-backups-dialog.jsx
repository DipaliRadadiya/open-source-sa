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
  // What came back refusing, and why. Held in state rather than announced and
  // discarded: a count in a toast that vanishes cannot tell you which archive
  // is still in your bucket.
  const [failures, setFailures] = useState([]);

  // Cleared where the dialog is opened, not in onOpenChange — reopening from
  // the toolbar button never routes through that, so last time's failures
  // would still be on screen above a fresh selection.
  const [wasOpen, setWasOpen] = useState(open);
  if (wasOpen !== open) {
    setWasOpen(open);
    if (open) setFailures([]);
  }

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

      // The successes are reported either way, so the table loses those rows
      // immediately and the two numbers in the toast are about real work.
      onDeleted?.(succeeded, failed.map((entry) => entry.id));

      if (failed.length === 0) {
        toast.success(t("done", { count: succeeded.length }));
        onOpenChange(false);
        return;
      }

      // Stays open on anything less than a clean sweep. The reader has just
      // pressed a destructive button and is owed the list, not a number — and
      // the two reasons want opposite things done about them. The selection now
      // holds exactly the refusals, so pressing Delete again retries those.
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
              // Literal keys, not `failures.reason.${entry.reason}`: a key built
              // at runtime cannot be checked, and check-i18n exists to catch
              // exactly the missing one a fallback would hide.
              const reason =
                entry.reason === "running"
                  ? t("failures.reason.running")
                  : entry.reason === "artifact"
                    ? t("failures.reason.artifact")
                    : t("failures.reason.failed");

              return (
                <li key={entry.id} className="space-y-0.5 text-xs">
                  <p className="font-medium">
                    {/* The exact timestamp, not "1 day ago": this list exists to
                        say WHICH backup, and two of the same type taken on the
                        same day render as the same sentence in human time. */}
                    {[backup?.type_title, backup?.created_at ?? backup?.created_at_human]
                      .filter(Boolean)
                      .join(" · ") || t("failures.unknownBackup")}
                  </p>
                  {/* Two refusals, two different things to do: one resolves
                      itself, the other leaves an object you are still paying
                      for. An unrecognised reason falls back rather than
                      rendering a key. */}
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
