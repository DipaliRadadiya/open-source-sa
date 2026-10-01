"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// A local-only engine answers 409 `restart_required`; resend with `restart_cluster: true` if confirmed.
// `ask(error)` returns null for any other error, else a promise of the answer.
export function useRestartConfirm() {
  const t = useTranslations("databases.restartForRemote");
  const [pending, setPending] = useState(null); // { message, resolve }

  function ask(error) {
    const data = error?.response?.data;
    if (error?.response?.status !== 409 || data?.code !== "restart_required") return null;
    return new Promise((resolve) => setPending({ message: data.message, resolve }));
  }

  function answer(value) {
    pending?.resolve(value);
    setPending(null);
  }

  const dialog = (
    <ConfirmDialog
      open={pending !== null}
      onOpenChange={(next) => !next && answer(false)}
      icon={RotateCw}
      tone="warning"
      title={t("title")}
      // Own wording: the API's message is written for a developer.
      description={t("description")}
      cancelLabel={t("cancel")}
      confirmLabel={t("confirm")}
      onConfirm={() => answer(true)}
    />
  );

  return { ask, dialog };
}
