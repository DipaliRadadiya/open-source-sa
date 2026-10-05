import { useRef } from "react";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteUser } from "@/lib/api/users";
import { useAction } from "@/hooks/use-action";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function DeleteUserDialog({ user, open, onOpenChange }) {
  const t = useTranslations("users");
  const { run, pending } = useAction();
  // The row and the ⋯ that opened this are gone once it is deleted.
  const removed = useRef(false);

  async function onConfirm() {
    await run(() => deleteUser(user.id), {
      success: t("toast.deleted"),
      // The backend's 422 message (e.g. self-deletion) replaces this fallback.
      error: t("toast.deleteFailed"),
      onSuccess: () => {
        removed.current = true;
        onOpenChange?.(false);
      },
      refresh: true,
    });
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("delete.title")}
      description={t("delete.description", { name: user?.name ?? "" })}
      cancelLabel={t("delete.cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
      onCloseAutoFocus={(event) => {
        if (!removed.current) return;
        removed.current = false;
        event.preventDefault();
        document.querySelector("[data-users-add]")?.focus();
      }}
    />
  );
}
