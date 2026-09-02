import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Trash2 } from "lucide-react";
import { deleteFile } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { PermanentDeleteField } from "@/components/applications/files/permanent-delete-field";

export function DeleteFileDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  // Cleared whenever the dialog opens, not when it closes: a dialog opened from
  // a row's own menu never sees onOpenChange(false), so state left behind here
  // would arrive already ticked on the next file.
  const [permanent, setPermanent] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteFile(appId, file.path, { permanent });
      toast.success(
        permanent ? t("delete.doneForever", { name: file.name }) : t("delete.done", { name: file.name }),
      );
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("delete.failed")));
    } finally {
      setPending(false);
    }
  }

  if (!file) return null;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (next) setPermanent(false);
        onOpenChange?.(next);
      }}
      icon={Trash2}
      tone="destructive"
      title={t("delete.title", { name: file.name })}
      // What actually happens, and it changes with the checkbox — the dialog
      // used to say "This can't be undone" for a delete that is now recoverable.
      description={
        permanent
          ? t("delete.descriptionPermanent")
          : file.type === "dir"
            ? t("delete.descriptionDir")
            : t("delete.description")
      }
      cancelLabel={t("cancel")}
      confirmLabel={permanent ? t("delete.confirmForever") : t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    >
      <PermanentDeleteField checked={permanent} onChange={setPermanent} disabled={pending} />
    </ConfirmDialog>
  );
}
