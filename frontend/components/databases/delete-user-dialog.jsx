"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
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
  const router = useRouter();
  const [pending, setPending] = useState(false);

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
    />
  );
}
