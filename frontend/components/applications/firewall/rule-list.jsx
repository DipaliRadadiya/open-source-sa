import { useState } from "react";
import { useTranslations } from "next-intl";
import { Plus, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { IconTooltip } from "@/components/ui/icon-tooltip";

// The API bounds both lists at 50 entries of 1–255 characters. Enforced here
// too, so the limit is visible while typing instead of arriving as a 422.
const MAX_ITEMS = 50;
const MAX_LENGTH = 255;

// A term this short matches inside ordinary words — the documented case is a
// rule guarding ".conf" that also blocked confirm.min.js and confused.jpg.
// Worth saying, not worth blocking: a deliberate short term is still valid.
// The bound has to cover "conf" itself, or the warning cites an example it
// would not actually have warned about.
const SHORT_TERM_LENGTH = 6;

// Rows shown before the list becomes its own scroll region.
const VISIBLE_ROWS = 8;

/**
 * A bounded list of plain words — exceptions to skip, or extra terms to block.
 *
 * One entry per row rather than wrapping chips: these are request fragments of
 * wildly different lengths, and reflowed chips leave the remove buttons landing
 * wherever the text happens to end. Same shape as the fail2ban ignore list,
 * which solved this already.
 *
 * Nothing here is a regex — the API takes plain strings and does a substring
 * match, so the input stays a plain input with no syntax to get wrong.
 */
export function RuleList({ items, onChange, disabled, placeholder, emptyText, warnShort = false }) {
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
    setError(null);
    // A duplicate is not an error worth a message — the entry the user wanted
    // is already there, so clearing the box is the whole correct response.
    if (!items.includes(value)) onChange([...items, value]);
    setDraft("");
  }

  return (
    <div className="space-y-2">
      {items.length > 0 ? (
        // The API allows 50 entries, and 50 rows rendered straight out turn the
        // whole page into one long list with the controls that manage it pushed
        // off screen. Past a screenful it scrolls in place instead.
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
          // Enter adds the entry. This list is not inside a <form>, so there is
          // no submit to accidentally trigger — but the key still has to work,
          // because typing then reaching for the mouse is not how anyone
          // fills in a list.
          onKeyDown={(event) => {
            if (event.key !== "Enter") return;
            event.preventDefault();
            add();
          }}
          placeholder={placeholder}
          spellCheck={false}
          autoComplete="off"
          // Deliberately no `maxLength`: it caps a paste silently at 255 with
          // no explanation, and it also made the length check below permanently
          // unreachable. Letting the value through and saying why it was
          // rejected is the honest half of that trade.
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
