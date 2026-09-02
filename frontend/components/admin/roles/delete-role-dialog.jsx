import { useAction } from "@/hooks/use-action";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteRole } from "@/lib/api/roles";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function DeleteRoleDialog({ role, open, onOpenChange }) {
  const t = useTranslations("roles");
  const { run, pending } = useAction();

  async function onConfirm() {
    await run(() => deleteRole(role.id), {
      success: t("toast.deleted"),
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
      description={t("delete.description", { name: role?.name ?? "" })}
      cancelLabel={t("delete.cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
