import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert, User } from "lucide-react";
import { deleteDatabaseUser } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Removing a user is not destructive to data, but it IS destructive to whatever
 * is connecting with it — so the dialog names that rather than asking "are you
 * sure?". No type-to-confirm: unlike dropping a database, this is recoverable
 * by making the user again.
 */
export function DeleteUserDialog({ database, user, open, onOpenChange }) {
  const t = useTranslations("databases.users");
  const tAccess = useTranslations("databases.access");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const access = user?.connection_preference ?? "localhost";

  async function onConfirm() {
    setPending(true);
    try {
      await deleteDatabaseUser(database.id, user.id);
      toast.success(t("deleted", { username: user.username }));
      onOpenChange?.(false);
      router.refresh();
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
      {/* Who is being removed, as a value rather than a word inside a sentence.
          A database user is a name AND where it may connect from, and two rows
          can share the name with different hosts — reading that distinction out
          of prose is exactly the mistake worth not making here. Same block the
          user row shows, so the dialog and the row are recognisably about the
          same line. */}
      <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
        <User className="size-4 shrink-0 text-muted-foreground" />
        <code className="min-w-0 font-mono text-sm font-medium break-all">
          {user?.username}
        </code>
        {/* The row's own words for the same fact, not a second wording of it. */}
        <span className="text-xs text-muted-foreground">
          {tAccess(`${access}.label`)}
          {access === "remote" && user?.host ? ` · ${user.host}` : ""}
        </span>
      </div>
    </ConfirmDialog>
  );
}
