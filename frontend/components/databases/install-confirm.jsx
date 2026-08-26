"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CheckCircle2, Database } from "lucide-react";
import { installEngine } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

const SQL_ENGINES = [
  { engine: "mysql", driver: "sql" },
  { engine: "mariadb", driver: "sql" },
];

/**
 * Confirms installing ONE engine, already chosen.
 *
 * The choice is made in the engine list, where each option sits next to its
 * current state — so this only has to state the consequence. For MySQL and
 * MariaDB that consequence is permanent, and it is said here because this is
 * the click that commits.
 *
 * When `choosing` is true, neither SQL engine has been installed yet and the
 * user must pick one before the install begins.
 */
export function InstallConfirm({ engine, open, onOpenChange, choosing = false }) {
  const t = useTranslations("databases");
  const [phase, setPhase] = useState(choosing ? "picker" : "confirming");
  const [selected, setSelected] = useState(null);

  // Reset picker phase when dialog opens fresh — only relevant when choosing,
  // since the single-engine branch has no picker and stays in confirming.
  useEffect(() => {
    if (open) {
      setPhase(choosing ? "picker" : "confirming");
      setSelected(null);
    }
  }, [open, choosing]);

  async function onConfirm() {
    if (phase === "picker") {
      if (!selected) return;
      setPhase("confirming");
    }
    const engineName = phase === "picker" ? selected.engine : engine?.engine;
    // Show ConfirmDialog loading state while the API call runs.
    // Do NOT close the dialog here — the parent drives that via the `open`
    // prop, which is controlled by the `pending` state in engine-state.jsx.
    try {
      const { data } = await installEngine(engineName);
      toast.success(
        data?.queued === false ? t("install.already") : t("install.queued"),
      );
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    }
  }

  if (!choosing && !engine) return null;
  // ↑ "engine && choosing" can never both be falsy when choosing=true — engine
  // is set to null in that case so this guard blocks the picker. The guard only
  // needs to catch the "nothing to do" case.

  // ── Choosing phase: pick MySQL or MariaDB ──────────────────────────────────
  if (choosing && phase === "picker") {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>{t("confirmInstall.chooseEngine.title")}</DialogTitle>
            <DialogDescription>
              {t("confirmInstall.chooseEngine.description")}
            </DialogDescription>
          </DialogHeader>

          <div className="grid grid-cols-2 gap-3 py-2">
            {SQL_ENGINES.map(({ engine: sql }) => {
              // Both SQL engines are always available to pick in the choosing
              // dialog. engine-state already filtered installable rows before
              // offering the Install button, so no unavailable state is needed here.
              const active = selected === sql;
              return (
                <button
                  key={sql}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => setSelected(sql)}
                  className={cn(
                    "flex flex-col gap-1.5 rounded-xl border px-4 py-3 text-left transition-colors",
                    active && "border-primary/60 bg-primary/5",
                    !active && "hover:border-primary/40 hover:bg-muted/40",
                  )}
                >
                  <span className="flex items-center justify-between gap-2 font-medium">
                    {t(`engines.${sql}`)}
                    {active ? (
                      <CheckCircle2 className="size-4 shrink-0 text-primary" aria-hidden />
                    ) : null}
                  </span>
                  <span className="text-xs leading-snug text-muted-foreground">
                    {t(`install.hint.${sql}`)}
                  </span>
                </button>
              );
            })}
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => onOpenChange?.(false)}
            >
              {t("cancel")}
            </Button>
            <Button
              disabled={!selected || phase === "confirming"}
              disabledReason={!selected ? t("chooseEngineFirst") : null}
              onClick={onConfirm}
            >
              {phase === "confirming" ? t("install.installing") : t("confirmInstall.chooseEngine.submit")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  // ── Confirming / single-engine confirm ─────────────────────────────────────
  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Database}
      tone="warning"
      title={t("confirmInstall.title", { name: t(`engines.${engine.engine}`) })}
      description={t("confirmInstall.description")}
      cancelLabel={t("cancel")}
      confirmLabel={phase === "confirming" ? t("install.installing") : t("confirmInstall.submit")}
      pending={phase === "confirming"}
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
