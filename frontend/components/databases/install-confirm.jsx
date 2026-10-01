import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Database } from "lucide-react";
import { installEngine } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// The engine is already chosen by the opener. States the consequence: one SQL
// engine per server, no migration afterwards.
export function InstallConfirm({ engine, open, onOpenChange, onSuccess }) {
  const t = useTranslations("databases");
  const [pending, setPending] = useState(false);

  if (!engine?.engine) return null;

  async function handleConfirm() {
    if (pending) return;
    setPending(true);
    try {
      const { data } = await installEngine(engine.engine);
      // Named: this toast is all that shows before the progress card appears.
      const name = t(`engines.${engine.engine}`);

      toast.success(
        data?.queued === false
          ? t("install.already", { name })
          : t("install.queued", { name }),
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
