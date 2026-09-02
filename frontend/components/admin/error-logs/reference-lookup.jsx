import { useState } from "react";
import { Search, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { isReference } from "@/lib/schemas/error-log";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/**
 * Look one failure up by the reference the person in front of you is reading out.
 *
 * This is the workflow the endpoint's new `?reference=` parameter exists for:
 * a server operation hands its reference back to whoever triggered it, so the
 * panel shows "Install failed — reference abc-…" and that string is the only
 * thing connecting their screen to this log.
 *
 * It goes through the URL rather than filtering in memory, unlike the text
 * search beside it: the reference the reader has may well be older than the
 * last 100 lines, so it has to reach the server to be found at all.
 *
 * Validated here because the backend requires a uuid and 422s anything else —
 * a half-pasted reference would come back as a validation error rendered where
 * the reader expects "no entry with that reference".
 */
export function ReferenceLookup({ value, onSubmit, onClear }) {
  const t = useTranslations("errorLogs");
  const [draft, setDraft] = useState(value ?? "");

  const trimmed = draft.trim();
  const valid = isReference(trimmed);
  const dirty = trimmed.length > 0;

  return (
    <form
      className="flex w-full items-start gap-2 sm:w-auto"
      onSubmit={(event) => {
        event.preventDefault();
        if (valid) onSubmit(trimmed);
      }}
    >
      <div className="w-full sm:w-72">
        <div className="relative">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden
          />
          <Input
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder={t("referencePlaceholder")}
            aria-label={t("referenceLabel")}
            aria-invalid={dirty && !valid ? true : undefined}
            className="px-8 font-mono text-xs"
          />
          {dirty ? (
            <button
              type="button"
              onClick={() => {
                setDraft("");
                onClear();
              }}
              aria-label={t("referenceClear")}
              className="absolute right-2 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
            >
              <X className="size-4" aria-hidden />
            </button>
          ) : null}
        </div>
        {/* Only once they have typed enough to be wrong — an empty box is not
            an error, and marking it as one the moment it is focused is noise. */}
        {dirty && !valid ? (
          <p className="mt-1 text-xs text-destructive">{t("referenceInvalid")}</p>
        ) : null}
      </div>

      <Button
        type="submit"
        variant="secondary"
        disabled={!valid}
        // The inline message below covers a wrong reference; this covers the
        // empty box, which shows nothing at all.
        disabledReason={dirty ? t("referenceInvalid") : t("referenceEmpty")}
      >
        {t("referenceSubmit")}
      </Button>
    </form>
  );
}
