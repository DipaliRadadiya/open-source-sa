"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { apiMessage } from "@/lib/api/error-message";
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

/**
 * The shared shape behind Rename, Copy, Compress and Extract: one "target
 * path" field, pre-filled with a sensible default instead of a blank input —
 * the API takes exactly `{path, target}` for all four, only the default and
 * the copy differ.
 *
 * `selectFrom`/`selectTo` pre-select part of the pre-filled value on open
 * (Rename selects just the filename, so typing replaces the name without
 * touching the directory — the same trick OS file pickers use for "rename").
 */
export function TargetPathDialog({
  appId,
  file,
  open,
  onOpenChange,
  icon: Icon,
  title,
  description,
  submitLabel,
  savingLabel,
  defaultTarget,
  selectFrom,
  selectTo,
  apply,
  successMessage,
  failureMessage,
  warning,
  // Called with the final target path right after the API confirms success —
  // lets the panel flash the row once it reappears post-refresh, so "did that
  // work, and where did it go" has an answer without hunting the list.
  onSuccess,
  // Extract-at-root is the one legitimate case where the target IS the
  // site's own root, which this app represents as an empty path everywhere
  // else (the listing's own `path=` convention) — every other use of this
  // dialog genuinely requires a non-empty target.
  allowEmpty = false,
  emptyPlaceholder,
}) {
  const t = useTranslations("applications.files");
  const router = useRouter();
  // Mounted fresh per file (see files-panel.jsx), so the pre-filled default
  // is the initial state directly rather than something an effect resets.
  const [value, setValue] = useState(defaultTarget);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);

  useEffect(() => {
    // Runs after the value's committed to the DOM so the selection sticks.
    // Focus/selection is a DOM side effect, not React state — nothing here
    // calls a setState setter.
    const id = requestAnimationFrame(() => {
      inputRef.current?.focus();
      if (selectFrom !== undefined) {
        inputRef.current?.setSelectionRange(selectFrom, selectTo ?? defaultTarget.length);
      } else {
        inputRef.current?.select();
      }
    });
    return () => cancelAnimationFrame(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleOpenChange(next) {
    if (busy) return;
    onOpenChange?.(next);
  }

  async function onSubmit(e) {
    e.preventDefault();
    const trimmed = value.trim();
    if ((!trimmed && !allowEmpty) || busy) return;
    setBusy(true);
    setError(null);
    try {
      await apply(appId, file.path, trimmed);
      toast.success(successMessage(file, trimmed));
      onSuccess?.(trimmed);
      handleOpenChange(false);
      router.refresh();
    } catch (err) {
      const targetError = err.response?.data?.errors?.target?.[0];
      if (targetError) {
        setError(targetError);
      } else {
        toast.error(apiMessage(err, failureMessage));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <form onSubmit={onSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Icon className="size-5" />
              </span>
              <DialogTitle>{title}</DialogTitle>
            </div>
            <DialogDescription className="pt-1">{description}</DialogDescription>
          </DialogHeader>

          <div className="space-y-2 py-4">
            <Label htmlFor="target-path">{t("targetDialog.pathLabel")}</Label>
            <Input
              id="target-path"
              ref={inputRef}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder={emptyPlaceholder}
              autoComplete="off"
              spellCheck={false}
              className="font-mono text-xs"
              aria-invalid={Boolean(error)}
            />
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
          </div>

          {warning ? (
            <p className="rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs leading-relaxed text-warning">
              {warning}
            </p>
          ) : null}

          <DialogFooter className="mt-4">
            <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={busy || (!value.trim() && !allowEmpty)}>
              {busy ? <Loader2 className="size-4 animate-spin" /> : null}
              {busy ? savingLabel : submitLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
