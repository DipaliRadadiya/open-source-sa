import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useVirtualizer } from "@tanstack/react-virtual";
import { ArrowDown, FileWarning, Lock, Inbox, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { LogLine } from "@/components/logs/log-line";

const ROW_HEIGHT = 24;
// "Already at the bottom" needs slack: fractional scroll positions and the
// last row's border mean an exact equality check never fires.
const BOTTOM_SLACK = 12;

/**
 * The log surface. Virtualized so a 5 000-line buffer keeps ~30 nodes in the
 * DOM, with smart-sticky auto-scroll: follow only while the reader is already
 * at the bottom, and offer a way back when they're not.
 */
export function LogViewer({
  lines,
  group,
  term,
  wrap,
  status,
  following,
  onAtBottomChange,
  onCopyLine,
  filtered,
  severity,
  // Set only by the application log panel: its API says whether a filtered
  // read reached the line cap. The server log endpoint has no equivalent, so
  // this is undefined there and the wording stays as it was.
  searchCapped = false,
  searchedLines,
}) {
  const t = useTranslations("logs");
  const scrollRef = useRef(null);
  const [atBottom, setAtBottom] = useState(true);
  const [scrolled, setScrolled] = useState(false);
  const [unseen, setUnseen] = useState(0);
  const lastCount = useRef(lines.length);

  // eslint-disable-next-line react-hooks/incompatible-library -- TanStack Virtual's useVirtualizer is the same known false positive as useReactTable
  const virtualizer = useVirtualizer({
    count: lines.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 24,
  });

  const scrollToBottom = useCallback(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
    setUnseen(0);
  }, []);

  const onScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    const bottom = el.scrollHeight - el.clientHeight - el.scrollTop <= BOTTOM_SLACK;
    setAtBottom(bottom);
    setScrolled(el.scrollTop > 4);
    onAtBottomChange?.(bottom);
    if (bottom) setUnseen(0);
  }, [onAtBottomChange]);

  // Appends land after paint; stick to the bottom only if the reader was
  // already there, otherwise count what they haven't seen.
  useLayoutEffect(() => {
    const added = lines.length - lastCount.current;
    lastCount.current = lines.length;
    if (added === 0) return;
    if (atBottom) scrollToBottom();
    else if (added > 0) setUnseen((n) => n + added);
  }, [lines.length, atBottom, scrollToBottom]);

  // A new source (or a new filter) starts at the newest line.
  useEffect(() => {
    scrollToBottom();
    setAtBottom(true);
  }, [group, term, severity, scrollToBottom]);

  if (status === "locked") {
    return <Notice icon={Lock} title={t("locked.title")} body={t("locked.body")} />;
  }
  if (status === "missing") {
    return <Notice icon={FileWarning} title={t("missing.title")} body={t("missing.body")} />;
  }
  // The read failed — say so rather than showing an empty console, which reads
  // as "this file has nothing in it".
  if (status === "failed") {
    return <Notice icon={TriangleAlert} title={t("readFailed.title")} body={t("readFailed.body")} />;
  }
  if (!lines.length) {
    // "No matches" is two different answers and they were rendered as one. A
    // search that read the whole file proves the text is not there; one that
    // read the last few thousand lines proves nothing about the rest, and
    // saying so is the difference between trusting the result and being
    // misled by it.
    const noMatchBody =
      searchCapped && searchedLines
        ? t("noMatches.bodyCapped", { count: searchedLines })
        : t("noMatches.bodyWholeFile");

    return (
      <Notice
        icon={Inbox}
        title={filtered ? t("noMatches.title") : t("emptyFile.title")}
        body={filtered ? noMatchBody : t("emptyFile.body")}
      />
    );
  }

  return (
    <div className="relative min-h-0 flex-1">
      <div
        ref={scrollRef}
        onScroll={onScroll}
        // role=log + polite announcements only while following, so a screen
        // reader isn't narrating a file the user is quietly scrolling.
        role="log"
        aria-live={following ? "polite" : "off"}
        aria-label={t("viewerLabel")}
        tabIndex={0}
        className={cn(
          "console-scroll h-full overflow-auto bg-console py-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring",
          wrap ? "overflow-x-hidden" : "overflow-x-auto",
        )}
      >
        <div
          style={{ height: virtualizer.getTotalSize(), position: "relative" }}
          className={wrap ? "" : "min-w-max"}
        >
          {virtualizer.getVirtualItems().map((item) => (
            <div
              key={item.key}
              ref={wrap ? virtualizer.measureElement : undefined}
              data-index={item.index}
              style={{
                position: "absolute",
                top: 0,
                left: 0,
                width: "100%",
                transform: `translateY(${item.start}px)`,
              }}
            >
              <LogLine
                index={item.index + 1}
                text={lines[item.index]}
                group={group}
                term={term}
                wrap={wrap}
                onCopy={onCopyLine}
                copyLabel={t("copyLine", { index: item.index + 1 })}
              />
            </div>
          ))}
        </div>
      </div>

      {/* Edge fades: content that continues past the viewport otherwise just
          stops mid-line. Top fade whenever there's history above; bottom fade
          only while scrolled up, so a followed tail isn't dimmed at the very
          line you're watching. */}
      <div
        aria-hidden="true"
        className={cn(
          "pointer-events-none absolute inset-x-0 top-0 h-6 bg-gradient-to-b from-console to-transparent transition-opacity",
          scrolled ? "opacity-100" : "opacity-0",
        )}
      />
      <div
        aria-hidden="true"
        className={cn(
          "pointer-events-none absolute inset-x-0 bottom-0 h-6 bg-gradient-to-t from-console to-transparent transition-opacity",
          atBottom ? "opacity-0" : "opacity-100",
        )}
      />

      {!atBottom ? (
        <div className="pointer-events-none absolute inset-x-0 bottom-4 flex justify-center">
          <Button size="sm" className="pointer-events-auto shadow-md" onClick={scrollToBottom}>
            <ArrowDown className="size-4" />
            {unseen > 0 ? t("jumpWithCount", { count: unseen }) : t("jump")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

function Notice({ icon: Icon, title, body }) {
  return (
    <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-console px-6 py-10 text-center sm:py-16">
      <span className="flex size-11 items-center justify-center rounded-full bg-console-foreground/10 text-console-muted">
        <Icon className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium text-console-foreground">{title}</p>
        <p className="max-w-sm text-sm text-console-muted">{body}</p>
      </div>
    </div>
  );
}
