"use client";

import { useState } from "react";
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
export function InstallConfirm(props) {
  if (!props.open) return null;

  // The inner component exists only while the dialog is open. Closing it
  // unmounts the session, so phase, selection and pending state reset naturally
  // on the next open instead of being synchronously rewritten from an effect.
  const sessionKey = `${props.choosing ? "picker" : "direct"}:${
    props.engine?.engine ?? "sql"
  }`;

  return <InstallConfirmSession key={sessionKey} {...props} />;
}

function InstallConfirmSession({ engine, open, onOpenChange, choosing = false, onSuccess }) {
  const t = useTranslations("databases");

  // phase tracks which step the dialog is on:
  //   "picker"  — choosing between MySQL / MariaDB
  //   "confirm" — showing the "Install X?" confirmation
  const [phase, setPhase] = useState(choosing ? "picker" : "confirm");
  // The engine the user picked in the picker (SQL engines only).
  const [picked, setPicked] = useState(null);
  // True while the API call is in flight.
  const [pending, setPending] = useState(false);

  // ── Picker step ─────────────────────────────────────────────────────────────
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
            {SQL_ENGINES.map(({ engine: sql }) => (
              <button
                key={sql}
                type="button"
                role="radio"
                aria-checked={picked?.engine === sql}
                onClick={() => setPicked({ engine: sql, driver: "sql" })}
                className={cn(
                  "flex flex-col gap-1.5 rounded-xl border px-4 py-3 text-left transition-colors",
                  picked?.engine === sql && "border-primary/60 bg-primary/5",
                  picked?.engine !== sql && "hover:border-primary/40 hover:bg-muted/40",
                )}
              >
                <span className="flex items-center justify-between gap-2 font-medium">
                  {t(`engines.${sql}`)}
                  {picked?.engine === sql ? (
                    <CheckCircle2 className="size-4 shrink-0 text-primary" aria-hidden />
                  ) : null}
                </span>
                <span className="text-xs leading-snug text-muted-foreground">
                  {t(`install.hint.${sql}`)}
                </span>
              </button>
            ))}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => onOpenChange?.(false)}>
              {t("cancel")}
            </Button>
            <Button
              disabled={!picked || pending}
              onClick={() => {
                if (!picked) return;
                setPhase("confirm");
              }}
            >
              {t("confirmInstall.chooseEngine.submit")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  // ── Confirm step ───────────────────────────────────────────────────────────
  // The engine to confirm: from the parent (single-engine path) or from the
  // picker (SQL engine path).
  const effectiveEngine = engine ?? picked;

  if (!effectiveEngine) return null;

  async function handleConfirm() {
    if (pending) return;
    const engineName = effectiveEngine.engine;
    if (!engineName) return;
    setPending(true);
    try {
      const { data } = await installEngine(engineName);
      toast.success(
        data?.queued === false ? t("install.already") : t("install.queued"),
      );
      onSuccess?.({
        engine: engineName,
        queued: data?.queued !== false,
      });
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
      title={t("confirmInstall.title", { name: t(`engines.${effectiveEngine.engine}`) })}
      description={t("confirmInstall.description")}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("install.installing") : t("confirmInstall.submit")}
      pending={pending}
      onConfirm={handleConfirm}
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
