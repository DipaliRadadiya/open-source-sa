"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useLocale, useTranslations } from "next-intl";
import { Loader2, Lock, TriangleAlert } from "lucide-react";
import { setFilePermissions } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import {
  AUDIENCES,
  describeMode,
  hasPermission,
  isWorldWritable,
  withPermission,
} from "@/lib/files/describe-mode";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
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
  // One piece of state: the mode itself. The checkboxes edit its digits, so no
  // combination can be entered that the server would reject.
  const [mode, setMode] = useState(() =>
    /^[0-7]{3}$/.test(currentMode ?? "") ? currentMode : PRESETS[0].mode,
  );
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  // A live, plain-language readout of whatever's currently selected — not
  // just for Custom (where it matters most, since no preset label is there
  // to lean on), but for the presets too, so "Read-only" doesn't have to be
  // taken on faith. Owner and "everyone else" collapse into one clause when
  // group and other match, which is true for all three presets.
  // Sentence verbs, not the column headers: "Owner: read and write." needs
  // lowercase verbs, the grid needs capitalised nouns.
  const permWords = {
    read: t("permissionsDialog.verbRead"),
    write: t("permissionsDialog.verbWrite"),
    execute: t("permissionsDialog.verbExecute"),
  };
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
    if (busy || !/^[0-7]{3}$/.test(mode)) return;
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

          <div className="space-y-3 py-4">
            {/* The three answers almost everyone wants, still one click. */}
            <div className="flex flex-wrap gap-2">
              {PRESETS.map((p) => (
                <Button
                  key={p.mode}
                  type="button"
                  size="sm"
                  variant={mode === p.mode ? "default" : "outline"}
                  onClick={() => setMode(p.mode)}
                >
                  {t(p.labelKey)}
                </Button>
              ))}
            </div>

            {/* Nine checkboxes rather than a number. "644" is only meaningful
                to someone who already knows the 4/2/1 mask, and everyone else
                was being asked to learn octal to change a file. The mode is
                still what gets sent — it is just no longer what gets typed. */}
            <div className="overflow-hidden rounded-lg border">
              <div className="grid grid-cols-[1fr_repeat(3,4.5rem)] items-center gap-y-1 px-3 py-2 text-[11px] font-medium text-muted-foreground">
                <span />
                <span className="text-center">{t("permissionsDialog.read")}</span>
                <span className="text-center">{t("permissionsDialog.write")}</span>
                <span className="text-center">{t("permissionsDialog.execute")}</span>
              </div>
              <div className="divide-y border-t">
                {AUDIENCES.map((audience) => (
                  <div
                    key={audience}
                    className="grid grid-cols-[1fr_repeat(3,4.5rem)] items-center px-3 py-2"
                  >
                    <span className="text-sm font-medium">
                      {t(`permissionsDialog.audience.${audience}`)}
                    </span>
                    {["read", "write", "execute"].map((permission) => (
                      <span key={permission} className="flex justify-center">
                        <Checkbox
                          checked={hasPermission(mode, audience, permission)}
                          aria-label={t("permissionsDialog.boxLabel", {
                            audience: t(`permissionsDialog.audience.${audience}`),
                            permission: permWords[permission],
                          })}
                          onCheckedChange={(next) =>
                            setMode(withPermission(mode, audience, permission, next === true))
                          }
                        />
                      </span>
                    ))}
                  </div>
                ))}
              </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
              {descriptionText ? (
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {descriptionText}
                </p>
              ) : (
                <span />
              )}
              {/* Kept, small: the number still means something to people who
                  know it, and it is what the server is given. */}
              <span className="font-mono text-xs text-muted-foreground tabular-nums">
                {mode}
              </span>
            </div>

            {/* Anyone with a login on the box could change the file — almost
                never intended, and the one combination worth interrupting for. */}
            {isWorldWritable(mode) ? (
              <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                {t("columns.worldWritableHint")}
              </p>
            ) : null}

            {error ? <p className="text-sm text-destructive">{error}</p> : null}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={busy}>
              {busy ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("permissionsDialog.submit")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
