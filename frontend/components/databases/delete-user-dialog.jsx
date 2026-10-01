import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert, User } from "lucide-react";
import { deleteDatabaseUser } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// No type-to-confirm: the user can be recreated.
export function DeleteUserDialog({ database, user, open, onOpenChange }) {
  const t = useTranslations("databases.users");
  const tAccess = useTranslations("databases.access");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const access = user?.connection_preference ?? "localhost";

  async function onConfirm() {
    setPending(true);
    try {
      await deleteDatabaseUser(database.id, user.id);
      await refreshAndWait();
      toast.success(t("deleted", { username: user.username }));
      onOpenChange?.(false);
    } catch (error) {
      toast.error(apiMessage(error, t("deleteFailed")));
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
      title={t("deleteTitle", { username: user?.username ?? "" })}
      description={t("deleteDescription", {
        username: user?.username ?? "",
        name: database?.name ?? "",
      })}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("deleting") : t("deleteSubmit")}
      pending={pending}
      onConfirm={onConfirm}
    >
      {/* A user is a name AND a host, and two rows can share the name; shown
          as the same block the user row uses. */}
      <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
        <User className="size-4 shrink-0 text-muted-foreground" />
        <code className="min-w-0 font-mono text-sm font-medium break-all">
          {user?.username}
        </code>
        <span className="text-xs text-muted-foreground">
          {tAccess(`${access}.label`)}
          {access === "remote" && user?.host ? ` · ${user.host}` : ""}
        </span>
      </div>
    </ConfirmDialog>
  );
}
