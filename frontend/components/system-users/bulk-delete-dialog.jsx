import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteSystemUsers } from "@/lib/api/system-users";
import { bulkDeleteOutcome } from "@/lib/system-users/bulk-delete";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

const NAMES_SHOWN = 6;

// One request for the whole selection (no per-user loop from the browser); the API
// runs each through the single delete and reports what happened to every one.
export function BulkDeleteDialog({ users = [], open, onOpenChange, onDone }) {
  const t = useTranslations("systemUsers.bulk");
  const { refreshThen } = useRefresh();
  const [pending, setPending] = useState(false);
  const [endSessions, setEndSessions] = useState(false);

  function handleOpenChange(next) {
    if (!next) setEndSessions(false);
    onOpenChange?.(next);
  }

  async function onConfirm() {
    setPending(true);
    try {
      const { data } = await deleteSystemUsers(users.map((user) => user.id), { endSessions });
      const outcome = bulkDeleteOutcome(data);
      const kept = Object.keys(outcome.failures).length;
      // Reported once the list no longer shows the deleted rows.
      refreshThen(() => {
        if (kept === 0) toast.success(t("allDeleted", { count: outcome.succeeded.length }));
        onDone?.(outcome);
        handleOpenChange(false);
        setPending(false);
      });
    } catch (error) {
      // A 422 here means nothing was deleted (an unknown id, a duplicate): say so and re-read.
      toast.error(apiMessage(error, t("failed")));
      refreshThen(() => setPending(false));
    }
  }

  const names = users.slice(0, NAMES_SHOWN).map((user) => user.username);
  const more = users.length - names.length;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("title", { count: users.length })}
      description={t("description")}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("deleting") : t("confirm", { count: users.length })}
      pending={pending}
      onConfirm={onConfirm}
    >
      <ul className="flex flex-wrap gap-1.5">
        {names.map((name) => (
          <li key={name} className="rounded-md border bg-muted/40 px-2 py-0.5 font-mono text-xs">
            {name}
          </li>
        ))}
        {more > 0 ? <li className="px-1 py-0.5 text-xs text-muted-foreground">{t("more", { count: more })}</li> : null}
      </ul>
      {/* The same choice as a single delete; an open session otherwise refuses that account. */}
      <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
        <Checkbox
          id="bulk-delete-su-end-sessions"
          checked={endSessions}
          onCheckedChange={(value) => setEndSessions(value === true)}
          className="mt-0.5"
        />
        <div className="space-y-1">
          <Label htmlFor="bulk-delete-su-end-sessions" className="text-sm font-medium">
            {t("endSessions")}
          </Label>
          <p className="text-xs leading-5 text-muted-foreground">{t("endSessionsHint")}</p>
        </div>
      </div>
    </ConfirmDialog>
  );
}
