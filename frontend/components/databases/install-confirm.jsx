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
export function InstallConfirm({ engine, open, onOpenChange, choosing = false, onSuccess }) {
  const t = useTranslations("databases");
  const [phase, setPhase] = useState(choosing ? "picker" : "confirming");
  // Store as { engine, driver } objects to match what engine-state passes.
  const [selected, setSelected] = useState(null);
  // Distinct from `phase === "confirming"` because the single-engine branch
  // (MongoDB) starts in confirming too — using phase for the button's pending
  // state shipped the dialog with the button already disabled and showing
  // "Installing…", so nothing could ever be clicked. `pending` is the actual
  // "API call in flight" flag and is only true between the click and the
  // resolved/awaited response.
  const [pending, setPending] = useState(false);

  // Reset state when dialog opens fresh — only the picker actually changes
  // phase; the single-engine branch stays in confirming, and `pending` is
  // cleared so a previous failed install can be retried.
  useEffect(() => {
    if (open) {
      setPhase(choosing ? "picker" : "confirming");
      setSelected(null);
      setPending(false);
    }
  }, [open, choosing]);

  async function onConfirm() {
    if (pending) return;
    if (phase === "picker") {
      if (!selected) return;
      setPhase("confirming");
      return; // do not fire the install on the same click — show the warning first
    }
    const engineName = phase === "picker" ? selected.engine : engine?.engine;
    if (!engineName) return;
    setPending(true);
    try {
      const { data } = await installEngine(engineName);
      toast.success(
        data?.queued === false ? t("install.already") : t("install.queued"),
      );
      // Tell the parent the install succeeded so it can close the dialog and
      // clear the pending state that keeps it open. The parent is responsible
      // for router.refresh() if it also wants a server re-render.
      onSuccess?.();
    } catch (error) {
      toast.error(apiMessage(error, t("install.failed")));
    } finally {
      setPending(false);
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
              const active = selected?.engine === sql;
              return (
                <button
                  key={sql}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => setSelected({ engine: sql, driver: "sql" })}
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
              disabled={!selected || pending}
              disabledReason={!selected ? t("chooseEngineFirst") : null}
              onClick={onConfirm}
            >
              {pending ? t("install.installing") : t("confirmInstall.chooseEngine.submit")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  // ── Confirming / single-engine confirm ─────────────────────────────────────
  // `engine` may be null here when `choosing=true` and the user just clicked
  // an option in the picker — the parent passes null so the single-engine
  // branch doesn't render its own "Install?" dialog alongside the picker.
  // Use `selected` as the source of truth in that case; it's only set when
  // the picker was used (and the picker is SQL-only, so driver="sql").
  const effectiveEngine = engine ?? selected;

  if (!effectiveEngine) return null;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      icon={Database}
      tone="warning"
      title={t("confirmInstall.title", { name: t(`engines.${effectiveEngine.engine}`) })}
      description={t("confirmInstall.description")}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("install.installing") : t("confirmInstall.submit")}
      pending={pending}
      onConfirm={onConfirm}
    >
      {effectiveEngine.driver === "sql" ? (
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("install.oneSqlOnly")}
        </p>
      ) : null}
      <p className="text-xs text-muted-foreground">{t("install.takesTime")}</p>
    </ConfirmDialog>
  );
}
