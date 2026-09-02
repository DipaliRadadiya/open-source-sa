import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteUser } from "@/lib/api/users";
import { useAction } from "@/hooks/use-action";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function DeleteUserDialog({ user, open, onOpenChange }) {
  const t = useTranslations("users");
  const { run, pending } = useAction();

  async function onConfirm() {
    await run(() => deleteUser(user.id), {
      success: t("toast.deleted"),
      // Backend blocks self-deletion (and similar) with a 422 message, which
      // apiMessage surfaces in place of this fallback.
      error: t("toast.deleteFailed"),
      onSuccess: () => onOpenChange?.(false),
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
    />
  );
}
