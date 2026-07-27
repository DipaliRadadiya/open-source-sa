"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ShieldAlert } from "lucide-react";
import { setSystemUserSudo, setSystemUserSsh } from "@/lib/api/system-users";
import { Switch } from "@/components/ui/switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// Inline access toggle used in the table. `field` is "sudo" | "ssh". Applies
// immediately with a toast; read-only when !canManage. Enabling sudo (a root
// grant) asks for confirmation first — disabling and SSH stay instant.
export function AccessSwitch({ user, field, canManage = true }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const checked = field === "sudo" ? user.sudo : user.ssh_access;
  const label = field === "sudo" ? t("access.sudo") : t("access.ssh");

  async function apply(v) {
    setBusy(true);
    try {
      if (field === "sudo") {
        await setSystemUserSudo(user.id, v);
        toast.success(v ? t("toast.sudoOn") : t("toast.sudoOff"));
      } else {
        await setSystemUserSsh(user.id, v);
        toast.success(v ? t("toast.sshOn") : t("toast.sshOff"));
      }
      router.refresh();
    } catch (error) {
      toast.error(error.response?.data?.message || t("toast.failed"));
    } finally {
      setBusy(false);
    }
  }

  function onToggle(v) {
    // Confirm before granting root; everything else is instant.
    if (field === "sudo" && v) {
      setConfirmOpen(true);
      return;
    }
    apply(v);
  }

  return (
    <>
      <Switch
        checked={checked}
        disabled={busy || !canManage}
        onCheckedChange={canManage ? onToggle : undefined}
        aria-label={label}
      />

      {field === "sudo" ? (
        <ConfirmDialog
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          icon={ShieldAlert}
          tone="warning"
          title={t("sudoConfirm.title")}
          description={t("sudoConfirm.description", { username: user.username })}
          cancelLabel={t("cancel")}
          confirmLabel={t("sudoConfirm.confirm")}
          pending={busy}
          onConfirm={() => {
            setConfirmOpen(false);
            apply(true);
          }}
        />
      ) : null}
    </>
  );
}
