"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteDatabase } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CopyButton } from "@/components/ui/copy-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Dropping a database is the least reversible thing on this page.
 *
 * So it asks for the name to be typed, the way the system-user delete does —
 * and it says what else goes with it. The engine cascades the database's users,
 * which is the part people don't expect: the credential their app uses stops
 * existing at the same moment the data does.
 */
export function DeleteDatabaseDialog({ database, open, onOpenChange, redirectTo }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");

  const name = database?.name ?? "";
  const matches = confirm.trim() === name;
  // The list sends users_count; the detail page sends the users themselves.
  // Reading only one of them made the warning say "and its 0 users" on the
  // page that actually knows how many there are.
  const users = database?.users?.length ?? database?.users_count ?? 0;

  function handleOpenChange(next) {
    // Cleared at the open site as well as on close: a dialog re-opened by its
    // own trigger never fires onOpenChange, so stale text would survive.
    if (!next) setConfirm("");
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      await deleteDatabase(database.id);
      toast.success(t("delete.deleted", { name }));
      handleOpenChange(false);
      // Deleted from its own detail page: that page no longer exists.
      if (redirectTo) router.push(redirectTo);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("delete.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("delete.title", { name })}
      description={
        users > 0
          ? t("delete.descriptionWithUsers", { name, count: users })
          : t("delete.description", { name })
      }
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.submit")}
      confirmDisabled={!matches}
      pending={pending}
      onConfirm={onConfirm}
    >
      <div className="space-y-2">
        {/* Same guard, same help: the database name must be typed exactly. */}
        <div className="flex items-start justify-between gap-2">
          <Label htmlFor="delete-db-confirm" className="text-sm">
            {t("delete.confirmLabel", { name })}
          </Label>
          <CopyButton value={name} label={t("delete.copyName")} className="size-6 shrink-0" />
        </div>
        <Input
            placeholder={name}
          id="delete-db-confirm"
          value={confirm}
          onChange={(event) => setConfirm(event.target.value)}
          autoComplete="off"
          spellCheck={false}
          className="font-mono"
        />
      </div>
    </ConfirmDialog>
  );
}
