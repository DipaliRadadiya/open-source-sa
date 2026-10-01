import { Copy } from "lucide-react";
import { cn } from "@/lib/utils";
import { LEVEL_CLASS, lineLevel, splitOnTerm } from "@/lib/logs/severity";
import { tokenizeLine } from "@/lib/logs/tokenize";

// Level pills: colour plus the word, so severity never depends on colour alone.
const LEVEL_PILL = {
  error: "bg-console-error/15 text-console-error",
  warn: "bg-console-warning/15 text-console-warning",
  info: "bg-console-foreground/10 text-console-foreground/80",
  notice: "bg-console-foreground/5 text-console-muted",
};

/**
 * One log row in three tiers: dimmed timestamp, coloured level, bright message,
 * so uniform INFO lines stay scannable.
 */
export function LogLine({ index, text, group, term, wrap, onCopy, copyLabel }) {
  const { time, level, levelKey, message } = tokenizeLine(text);
  // Fall back to whole-line detection (HTTP status, keywords) when the line
  // carries no explicit level word.
  const severity = levelKey ?? lineLevel(text, group);

  return (
    <div className="group flex gap-3 px-3 hover:bg-console-foreground/[0.06]">
      {/* The gutter doubles as the copy button. Out of the Tab order (one stop
          per line made the log impassable); the log scrolls by keyboard and
          the toolbar's "Copy visible lines" covers keyboard copying. */}
      <button
        type="button"
        tabIndex={-1}
        onClick={() => onCopy?.(text)}
        aria-label={copyLabel}
        className="relative w-12 shrink-0 select-none text-right text-xs leading-6 tabular-nums text-console-muted/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
      >
        <span className="transition-opacity group-hover:opacity-0">{index}</span>
        <Copy
          aria-hidden="true"
          className="absolute right-0 top-1/2 size-3 -translate-y-1/2 opacity-0 transition-opacity group-hover:opacity-100"
        />
      </button>
      <span
        className={cn(
          "min-w-0 flex-1 font-mono text-[13px] leading-6",
          wrap ? "break-words whitespace-pre-wrap" : "whitespace-pre",
        )}
      >
        {time ? (
          <span className="text-console-muted">
            <Marked text={time} term={term} />{" "}
          </span>
        ) : null}
        {level ? (
          <span
            className={cn(
              "mr-1.5 rounded px-1 py-px text-xs font-medium uppercase",
              LEVEL_PILL[severity] ?? LEVEL_PILL.notice,
            )}
          >
            <Marked text={level.replace(/[[\]]/g, "")} term={term} />
          </span>
        ) : null}
        <span
          className={cn(
            "text-console-foreground",
            // Tint the message only when no level pill already states severity.
            !level && severity ? LEVEL_CLASS[severity] : "",
          )}
        >
          <Marked text={message} term={term} />
        </span>
      </span>
    </div>
  );
}

function Marked({ text, term }) {
  return splitOnTerm(text, term).map((part, i) =>
    part.match ? (
      <mark key={i} className="rounded-sm bg-console-warning/35 px-0.5 text-console-foreground">
        {part.text}
      </mark>
    ) : (
      part.text
    ),
  );
}
