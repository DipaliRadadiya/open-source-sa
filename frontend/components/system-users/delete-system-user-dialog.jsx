"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { deleteSystemUser } from "@/lib/api/system-users";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CopyButton } from "@/components/ui/copy-button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { apiMessage } from "@/lib/api/error-message";

export function DeleteSystemUserDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");

  const username = user?.username ?? "";
  const matches = confirm.trim() === username;

  function handleOpenChange(next) {
    if (!next) setConfirm("");
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      await deleteSystemUser(user.id);
      toast.success(t("toast.deleted"));
      handleOpenChange(false);
      router.push("/system-users");
      router.refresh();
    } catch (error) {
      // 422 = still owns applications; show the backend's translated message.
      toast.error(apiMessage(error, t("toast.failed")));
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
      title={t("delete.title")}
      description={t("delete.description", { username })}
      cancelLabel={t("delete.cancel")}
      confirmLabel={pending ? t("delete.deleting") : t("delete.confirm")}
      confirmDisabled={!matches}
      pending={pending}
      onConfirm={onConfirm}
    >
      <div className="space-y-2">
        {/* Copy, because the name has to be typed exactly and a Linux username
            is the kind of string that gets mistyped — and it is sitting right
            there on screen. Matches the delete-application dialog, which is
            the same guard over the same shape of value. */}
        <div className="flex items-start justify-between gap-2">
          <Label htmlFor="delete-su-confirm" className="text-sm">
            {t("delete.confirmLabel", { username })}
          </Label>
          <CopyButton value={username} label={t("delete.copyUsername")} className="size-6 shrink-0" />
        </div>
        <Input
          placeholder={username}
          id="delete-su-confirm"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          autoComplete="off"
          className="font-mono"
        />
      </div>
    </ConfirmDialog>
  );
}
