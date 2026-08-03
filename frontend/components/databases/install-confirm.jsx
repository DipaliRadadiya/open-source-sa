"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database } from "lucide-react";
import { installEngine } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Confirms installing ONE engine, already chosen.
 *
 * The choice is made in the engine list, where each option sits next to its
 * current state — so this only has to state the consequence. For MySQL and
 * MariaDB that consequence is permanent, and it is said here because this is
 * the click that commits.
 */
export function InstallConfirm({ engine, open, onOpenChange }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function onConfirm() {
    setPending(true);
    try {
      const { data } = await installEngine(engine.engine);
      // 200 + `queued: false` means it was already there — a migrated server
      // that already had MariaDB is a success, not a conflict.
      toast.success(
        data?.queued === false ? t("install.already") : t("install.queued"),
      );
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    } finally {
      setPending(false);
    }
  }

  if (!engine) return null;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Database}
      tone="warning"
      title={t("confirmInstall.title", { name: t(`engines.${engine.engine}`) })}
      description={t("confirmInstall.description")}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("install.installing") : t("confirmInstall.submit")}
      pending={pending}
      onConfirm={onConfirm}
    >
      {engine.driver === "sql" ? (
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("install.oneSqlOnly")}
        </p>
      ) : null}
      <p className="text-xs text-muted-foreground">{t("install.takesTime")}</p>
    </ConfirmDialog>
  );
}
