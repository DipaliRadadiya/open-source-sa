import { useState } from "react";
import { Search, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { isReference } from "@/lib/schemas/error-log";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

// Looked up via the URL on the server, since the entry may predate the loaded
// lines. The backend 422s anything that is not a uuid, so validate here.
export function ReferenceLookup({ value, onSubmit, onClear }) {
  const t = useTranslations("errorLogs");
  const [draft, setDraft] = useState(value ?? "");

  const trimmed = draft.trim();
  const valid = isReference(trimmed);
  const dirty = trimmed.length > 0;

  return (
    <form noValidate
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
        {/* Only once something is typed; an empty box is not an error. */}
        {dirty && !valid ? (
          <p className="mt-1 text-xs text-destructive">{t("referenceInvalid")}</p>
        ) : null}
      </div>

      <Button
        type="submit"
        variant="secondary"
        disabled={!valid}
        // The inline message covers a wrong reference; this covers the empty box.
        disabledReason={dirty ? t("referenceInvalid") : t("referenceEmpty")}
      >
        {t("referenceSubmit")}
      </Button>
    </form>
  );
}
