import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Folder, Loader2 } from "lucide-react";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CopyButton } from "@/components/ui/copy-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormModal } from "@/components/ui/form-modal";

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
// Refusals about what was typed, so they belong under the field. A 403, a
// rate limit or a server fault is not about the path and stays a toast.
const REFUSED_HERE = new Set([404, 409, 422]);

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
  // Rendered above the path field, and handed the field's own state — Compress
  // uses it for the format choice, which has to rewrite the extension in the
  // path rather than live beside it as a second source of truth.
  renderExtra,
  // Checked before the request goes out, so a wrong extension is an inline
  // message under the field instead of a toast after a round trip.
  validate,
  // Finishes what was typed before it is checked or sent — Compress adds the
  // chosen extension to a bare name.
  normalize = (value) => value,
  /*
   * The "where does this land" line under the field. Null hides it.
   *
   * A label rather than a boolean because the two dialogs that want it say
   * different things — Extract pours files INTO the path, Compress writes one
   * file AT it — and `destinationOf` is what makes that work: Extract's whole
   * value is the folder, Compress's folder is the value minus the filename.
   *
   * Rename has neither: its field is a new NAME, and echoing it back underneath
   * says nothing the field does not already show.
   */
  destinationLabel = null,
  destinationOf = (value) => value,
}) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const router = useRouter();
  // Mounted fresh per file (see files-panel.jsx), so the pre-filled default
  // is the initial state directly rather than something an effect resets.
  const [value, setValue] = useState(defaultTarget);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef(null);

  /*
   * The typed path as the breadcrumb would say it, and as a value to paste.
   *
   * Empty means the site's own root — the listing's `path=` convention
   * everywhere else in this feature — so it gets the same words the breadcrumb
   * uses rather than rendering as nothing at all.
   */
  const trimmedTarget = destinationOf(value.trim()).replace(/^\/+|\/+$/g, "");
  const destinationValue = trimmedTarget;
  const destinationText = trimmedTarget
    ? trimmedTarget.split("/").filter(Boolean).join(" / ")
    : t("root");

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
    const trimmed = normalize(value.trim());
    if ((!trimmed && !allowEmpty) || busy) return;
    const invalid = validate?.(trimmed);
    if (invalid) {
      setError(invalid);
      return;
    }
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
      } else if (REFUSED_HERE.has(err.response?.status)) {
        // "Something already exists at that path", "could not be found": the
        // API sends these with no field key, and a toast fading out beside a
        // dialog that stays open looked like nothing had been said.
        setError(apiMessage(err, failureMessage));
      } else {
        toast.error(apiMessage(err, failureMessage));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      asForm
      onSubmit={onSubmit}
      icon={Icon}
      title={title}
      description={description}
      // Wider than the default: the field holds a filesystem path.
      className="sm:max-w-lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)} disabled={busy}>
            {t("cancel")}
          </Button>
          <ReasonTooltip reason={!busy && !value.trim() && !allowEmpty ? tc("enterAValue") : null}>
          <Button type="submit" disabled={busy || (!value.trim() && !allowEmpty)}>
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {busy ? savingLabel : submitLabel}
          </Button>
          </ReasonTooltip>
        </>
      }
    >
      {renderExtra ? <div>{renderExtra({ value, setValue, busy })}</div> : null}

      <div className="space-y-2">
        <Label htmlFor="target-path" hint={t("targetDialog.pathLabelHint")}>{t("targetDialog.pathLabel")}</Label>
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

        {/*
         * Where this actually lands, spelled out and updated as you type.
         *
         * The field is relative to the top of the site's folder, and nothing on
         * screen said so — at the site root it renders as an EMPTY box behind a
         * "Site root" placeholder, which reads as an unanswered question rather
         * than as the answer it is. Someone extracting an archive could not
         * tell what they were about to overwrite or where.
         *
         * Deliberately NOT an absolute path. The file browser is rooted at
         * `publicHtmlPath()` — the code root — and no field the API sends is
         * reliably equal to it: `document_root` is deeper whenever a web root
         * is set, and `path` (codePath) diverges for a non-git site with a
         * custom web root. Printing either would be a confident guess at a
         * location, which is worse than naming no location at all. Same
         * vocabulary as the breadcrumb above the listing instead, so the two
         * describe one place the same way.
         */}
        {destinationLabel ? (
          <div className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
            <span className="shrink-0">{destinationLabel}</span>
            <span className="flex min-w-0 items-center gap-1 font-mono text-foreground">
              <Folder className="size-3 shrink-0" aria-hidden />
              <span className="truncate">{destinationText}</span>
            </span>
            {/* Nothing to copy at the site root — the path IS empty there,
                and a button that puts an empty string on the clipboard is a
                control that cannot do anything. */}
            {destinationValue ? (
              <CopyButton
                value={destinationValue}
                label={t("targetDialog.copyDestination")}
                className="size-5 shrink-0"
              />
            ) : null}
          </div>
        ) : null}
      </div>

      {warning ? (
        <p className="rounded-lg border border-warning/40 bg-warning/5 px-3 py-2 text-xs leading-relaxed text-warning">
          {warning}
        </p>
      ) : null}
    </FormModal>
  );
}
