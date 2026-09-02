import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database } from "lucide-react";
import { installEngine } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Confirms installing ONE engine, already chosen.
 *
 * It never asks which. Every caller reaches this from a control that names the
 * engine — a row, a menu item, a radio in the setup wizard — so asking again
 * was the panel forgetting the click that opened it. It used to: clicking
 * Install on the MySQL row threw the choice away and opened a "choose a SQL
 * engine" step. Every panel worth copying (aaPanel, Coolify) treats the click
 * that names an engine as the click that starts it.
 *
 * What it does keep is the consequence. One SQL engine per server, no
 * migration afterwards, so MySQL-or-MariaDB is a decision worth stating at the
 * moment it commits.
 */
export function InstallConfirm({ engine, open, onOpenChange, onSuccess }) {
  const t = useTranslations("databases");
  const [pending, setPending] = useState(false);

  if (!engine?.engine) return null;

  async function handleConfirm() {
    if (pending) return;
    setPending(true);
    try {
      const { data } = await installEngine(engine.engine);
      toast.success(
        data?.queued === false ? t("install.already") : t("install.queued"),
      );
      onSuccess?.({ engine: engine.engine, queued: data?.queued !== false });
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    } finally {
      setPending(false);
    }
  }

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
      onConfirm={handleConfirm}
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
