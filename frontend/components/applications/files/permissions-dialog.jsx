"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useLocale, useTranslations } from "next-intl";
import { Loader2, Lock } from "lucide-react";
import { setFilePermissions } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { describeMode } from "@/lib/files/describe-mode";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

// Presets first, an escape hatch for the rest — same pattern as Cron Jobs'
// schedule field. Most people setting a file's permissions want one of these
// three; nobody should have to already know octal to use this dialog.
const PRESETS = [
  { mode: "644", labelKey: "permissionsDialog.preset644" },
  { mode: "755", labelKey: "permissionsDialog.preset755" },
  { mode: "600", labelKey: "permissionsDialog.preset600" },
];
const CUSTOM = "custom";

// Mounted fresh per file (see files-panel.jsx), so the pre-selected choice
// is the initial state directly rather than something an effect resets.
// When the listing sent a current `mode`, it's shown up front and used to
// preselect the matching preset (or Custom, pre-filled) instead of always
// defaulting to 644 regardless of what the file actually has — older
// backends that don't send `mode` yet still get exactly today's behavior.
export function PermissionsDialog({ appId, file, open, onOpenChange }) {
  const t = useTranslations("applications.files");
  const locale = useLocale();
  const router = useRouter();
  const currentMode = file?.mode ?? null;
  const matchingPreset = currentMode && PRESETS.some((p) => p.mode === currentMode) ? currentMode : null;
  const [choice, setChoice] = useState(() => matchingPreset ?? (currentMode ? CUSTOM : PRESETS[0].mode));
  const [custom, setCustom] = useState(() => (!matchingPreset && currentMode ? currentMode : ""));
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const mode = choice === CUSTOM ? custom.trim() : choice;
  const validCustom = /^[0-7]{3}$/.test(custom.trim());

  // A live, plain-language readout of whatever's currently selected — not
  // just for Custom (where it matters most, since no preset label is there
  // to lean on), but for the presets too, so "Read-only" doesn't have to be
  // taken on faith. Owner and "everyone else" collapse into one clause when
  // group and other match, which is true for all three presets.
  const permWords = { read: t("permissionsDialog.read"), write: t("permissionsDialog.write"), execute: t("permissionsDialog.execute") };
  const listFormat = new Intl.ListFormat(locale, { style: "long", type: "conjunction" });
  function describe(tokens) {
    return tokens.length ? listFormat.format(tokens.map((tok) => permWords[tok])) : t("permissionsDialog.noAccess");
  }
  const description = describeMode(mode);
  const descriptionText = description
    ? description.group.join() === description.other.join()
      ? t("permissionsDialog.describeSimple", { owner: describe(description.owner), rest: describe(description.group) })
      : t("permissionsDialog.describeFull", {
          owner: describe(description.owner),
          group: describe(description.group),
          other: describe(description.other),
        })
    : null;

  function handleOpenChange(next) {
    if (busy) return;
    onOpenChange?.(next);
  }

  async function onSubmit(e) {
    e.preventDefault();
    if (busy || !mode || (choice === CUSTOM && !validCustom)) return;
    setBusy(true);
    setError(null);
    try {
      await setFilePermissions(appId, file.path, mode);
      toast.success(t("permissionsDialog.done", { name: file.name }));
      handleOpenChange(false);
      router.refresh();
    } catch (err) {
      const modeError = err.response?.data?.errors?.mode?.[0];
      if (modeError) setError(modeError);
      else toast.error(apiMessage(err, t("permissionsDialog.failed")));
    } finally {
      setBusy(false);
    }
  }

  if (!file) return null;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={onSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Lock className="size-5" />
              </span>
              <DialogTitle>{t("permissionsDialog.title", { name: file.name })}</DialogTitle>
            </div>
            <DialogDescription className="pt-1">
              {currentMode
                ? t("permissionsDialog.subtitleWithCurrent", { mode: currentMode })
                : t("permissionsDialog.subtitle")}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2 py-4">
            {PRESETS.map((p) => (
              <button
                key={p.mode}
                type="button"
                onClick={() => setChoice(p.mode)}
                className={cn(
                  "flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors",
                  choice === p.mode ? "border-primary bg-primary/5" : "hover:bg-muted/50",
                )}
              >
                <span className="flex items-center gap-2">
                  {t(p.labelKey)}
                  {currentMode === p.mode ? (
                    <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                      {t("permissionsDialog.currentBadge")}
                    </span>
                  ) : null}
                </span>
                <span className="font-mono text-xs text-muted-foreground">{p.mode}</span>
              </button>
            ))}
            <button
              type="button"
              onClick={() => setChoice(CUSTOM)}
              className={cn(
                "flex w-full items-center justify-between gap-3 rounded-lg border px-3 py-2 text-left transition-colors",
                choice === CUSTOM ? "border-primary bg-primary/5" : "hover:bg-muted/50",
              )}
            >
              <span>{t("permissionsDialog.custom")}</span>
            </button>
            {choice === CUSTOM ? (
              <div className="space-y-1.5 pl-1">
                <Label htmlFor="custom-mode" className="sr-only">
                  {t("permissionsDialog.custom")}
                </Label>
                <Input
                  id="custom-mode"
                  value={custom}
                  onChange={(e) => setCustom(e.target.value)}
                  autoFocus
                  autoComplete="off"
                  inputMode="numeric"
                  placeholder="644"
                  className="w-24 font-mono"
                  aria-invalid={Boolean(custom) && !validCustom}
                />
                <p className="text-xs text-muted-foreground">{t("permissionsDialog.customHint")}</p>
              </div>
            ) : null}
            {descriptionText ? (
              <p className="rounded-lg border bg-muted/30 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
                {descriptionText}
              </p>
            ) : null}
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={busy || (choice === CUSTOM && !validCustom)}>
              {busy ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("permissionsDialog.submit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
