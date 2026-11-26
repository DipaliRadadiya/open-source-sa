import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteDatabase } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { TypeToConfirm } from "@/components/ui/type-to-confirm";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";

export function DeleteDatabaseDialog({ database, application = null, open, onOpenChange, redirectTo }) {
  const t = useTranslations("databases");
  const { refreshAndWait, pushAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");

  const name = database?.name ?? "";
  const matches = confirm.trim() === name;
  const users = database?.users?.length ?? database?.users_count ?? 0;
  const attached = database?.application_id !== null && database?.application_id !== undefined;

  function handleOpenChange(next) {
    // A dialog reopened by its own trigger never fires onOpenChange, so clear here too.
    if (!next) setConfirm("");
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      await deleteDatabase(database.id);
      if (redirectTo) {
        await pushAndWait(redirectTo);
        toast.success(t("delete.deleted", { name }));
        return;
      }
      await refreshAndWait();
      toast.success(t("delete.deleted", { name }));
      handleOpenChange(false);
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
      {attached ? (
        <Caution tone="destructive" size="md">
          {application
            ? t("delete.usedBy", { application: application.name })
            : t("delete.usedByUnknown")}
        </Caution>
      ) : (
        <p className="text-xs leading-5 text-muted-foreground">{t("delete.backupsKept")}</p>
      )}
      <TypeToConfirm
        id="delete-db-confirm"
        value={name}
        copyLabel={t("delete.copyName")}
        confirm={confirm}
        onChange={setConfirm}
      />
    </ConfirmDialog>
  );
}
