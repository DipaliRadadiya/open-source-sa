import { useState } from "react";
import { useTranslations } from "next-intl";
import { Plus, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { IconTooltip } from "@/components/ui/icon-tooltip";

// The API bounds both lists at 50 entries of 1–255 characters; mirrored here.
const MAX_ITEMS = 50;
const MAX_LENGTH = 255;

// A term this short matches inside ordinary words (".conf" blocks confirm.min.js).
// Warned, not blocked. Must cover "conf", the example the warning cites.
const SHORT_TERM_LENGTH = 6;

// Rows shown before the list becomes its own scroll region.
const VISIBLE_ROWS = 8;

// The API does a plain substring match, so nothing here is a regex.
export function RuleList({ items, onChange, disabled, placeholder, emptyText, warnShort = false, minLength = 1 }) {
  const t = useTranslations("applications.firewall");
  const [draft, setDraft] = useState("");
  const [error, setError] = useState(null);

  const full = items.length >= MAX_ITEMS;
  const hasShortTerm = warnShort && items.some((item) => item.length < SHORT_TERM_LENGTH);

  function add() {
    const value = draft.trim();
    if (!value) return;
    if (value.length > MAX_LENGTH) {
      setError(t("tooLong", { max: MAX_LENGTH }));
      return;
    }
    if (value.length < minLength) {
      setError(t("tooShort", { min: minLength }));
      return;
    }
    setError(null);
    // A duplicate just clears the box; the entry is already there.
    if (!items.includes(value)) onChange([...items, value]);
    setDraft("");
  }

  return (
    <div className="space-y-2">
      {items.length > 0 ? (
        // Scrolls in place past a screenful so the controls stay reachable.
        <ul
          className={cn(
            "space-y-1.5",
            items.length > VISIBLE_ROWS && "max-h-64 overflow-y-auto rounded-lg border bg-muted/20 p-2",
          )}
        >
          {items.map((item) => (
            <li
              key={item}
              className="flex items-center gap-2 rounded-lg border bg-background py-1 pr-1 pl-3"
            >
              <code className="min-w-0 flex-1 truncate font-mono text-xs">{item}</code>
              <IconTooltip label={t("remove", { value: item })}>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-7 shrink-0"
                  disabled={disabled}
                  onClick={() => onChange(items.filter((value) => value !== item))}
                  aria-label={t("remove", { value: item })}
                >
                  <X className="size-3.5" />
                </Button>
              </IconTooltip>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-muted-foreground">{emptyText}</p>
      )}

      <div className="flex gap-2">
        <Input
          value={draft}
          onChange={(event) => {
            setDraft(event.target.value);
            if (error) setError(null);
          }}
          // Enter adds the entry (not inside a <form>, so nothing else submits).
          onKeyDown={(event) => {
            if (event.key !== "Enter") return;
            event.preventDefault();
            add();
          }}
          placeholder={placeholder}
          spellCheck={false}
          autoComplete="off"
          // Deliberately no `maxLength`: it would silently truncate a paste and
          // make the length check above unreachable.
          disabled={disabled || full}
          aria-label={placeholder}
        />
        <Button type="button" variant="secondary" onClick={add} disabled={disabled || full || !draft.trim()}>
          <Plus className="size-3.5" />
          {t("add")}
        </Button>
      </div>

      {error ? <p className="text-xs text-destructive">{error}</p> : null}
      {full ? <p className="text-xs text-warning">{t("listFull", { max: MAX_ITEMS })}</p> : null}
      {hasShortTerm ? <p className="text-xs text-warning">{t("shortTermWarning")}</p> : null}
      {items.length > 0 && !full ? (
        <p className="text-xs text-muted-foreground">{t("countOf", { count: items.length, max: MAX_ITEMS })}</p>
      ) : null}
    </div>
  );
}
