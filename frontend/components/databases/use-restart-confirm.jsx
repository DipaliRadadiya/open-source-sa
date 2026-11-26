"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

export function useRestartConfirm() {
  const t = useTranslations("databases.restartForRemote");
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);

  async function retry(error, resend) {
    const data = error?.response?.data;
    if (error?.response?.status !== 409 || data?.code !== "restart_required") return false;
    const confirmed = await new Promise((resolve) => setPending({ resolve }));
    if (!confirmed) return true;
    setBusy(true);
    try {
      await resend();
    } finally {
      setBusy(false);
      setPending(null);
    }
    return true;
  }

  function cancel() {
    pending?.resolve(false);
    setPending(null);
  }

  const dialog = (
    <ConfirmDialog
      open={pending !== null}
      onOpenChange={(next) => !next && !busy && cancel()}
      icon={RotateCw}
      tone="warning"
      title={t("title")}
      description={t("description")}
      cancelLabel={t("cancel")}
      confirmLabel={busy ? t("working") : t("confirm")}
      pending={busy}
      onConfirm={() => pending?.resolve(true)}
    />
  );

  return { retry, dialog };
}
