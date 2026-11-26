import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Folder, Loader2 } from "lucide-react";
import { apiMessage } from "@/lib/api/error-message";
import { placeTarget } from "@/lib/files/path-helpers";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { CopyButton } from "@/components/ui/copy-button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormModal } from "@/components/ui/form-modal";
import { useRefresh } from "@/hooks/use-refresh";

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
  onSuccess,
  allowEmpty = false,
  emptyPlaceholder,
  renderExtra,
  validate,
  normalize = (value) => value,
  destinationLabel = null,
  destinationOf = (value) => value,
}) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [value, setValue] = useState(defaultTarget);
  const [error, setError] = useState(null);
  const [submitting, setBusy] = useState(false);
  const busy = submitting || refreshing;
  const inputRef = useRef(null);

  const place = (typed) => placeTarget(typed, file.path, defaultTarget);
  const trimmedTarget = destinationOf(place(value.trim())).replace(/^\/+|\/+$/g, "");
  const destinationValue = trimmedTarget;
  const destinationText = trimmedTarget
    ? trimmedTarget.split("/").filter(Boolean).join(" / ")
    : t("root");

  useEffect(() => {
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
    const trimmed = normalize(place(value.trim()));
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
      refreshThen(() => {
        toast.success(successMessage(file, trimmed));
        onSuccess?.(trimmed);
        onOpenChange?.(false);
      });
    } catch (err) {
      const targetError = err.response?.data?.errors?.target?.[0];
      if (targetError) {
        setError(targetError);
      } else if (REFUSED_HERE.has(err.response?.status)) {
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

        {destinationLabel ? (
          <div className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
            <span className="shrink-0">{destinationLabel}</span>
            <span className="flex min-w-0 items-center gap-1 font-mono text-foreground">
              <Folder className="size-3 shrink-0" aria-hidden />
              <span className="truncate">{destinationText}</span>
            </span>
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
