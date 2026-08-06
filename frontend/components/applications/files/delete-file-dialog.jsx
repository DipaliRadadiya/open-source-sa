"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Trash2 } from "lucide-react";
import { deleteFile } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function DeleteFileDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      await deleteFile(appId, file.path);
      toast.success(t("delete.done", { name: file.name }));
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
      onOpenChange={onOpenChange}
      icon={Trash2}
      tone="destructive"
      title={t("delete.title", { name: file.name })}
      description={file.type === "dir" ? t("delete.descriptionDir") : t("delete.description")}
      cancelLabel={t("cancel")}
      confirmLabel={t("delete.confirm")}
      pending={pending}
      onConfirm={onConfirm}
    />
  );
}
