import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useVirtualizer } from "@tanstack/react-virtual";
import { ArrowDown, ArrowUp, FileWarning, Loader2, Lock, Inbox, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { LogLine } from "@/components/logs/log-line";
import { appended } from "@/lib/logs/appended";

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
  // Set only by the application log panel, whose API reports whether a
  // filtered read hit the line cap.
  searchCapped = false,
  loadingText = null,
  // The server's reason for a failed read, when it gave one.
  failedMessage = null,
  searchedLines,
  /*
   * Newest line at the TOP. Every "stick to the end" behaviour below anchors
   * on the newest end, chosen from this flag, so live tailing works in both orders.
   */
  newestFirst = false,
}) {
  const t = useTranslations("logs");
  const scrollRef = useRef(null);
  const [atBottom, setAtBottom] = useState(true);
  const [scrolled, setScrolled] = useState(false);
  const [unseen, setUnseen] = useState(0);
  // The previous buffer and its filter, to tell appended lines from a
  // replaced buffer (another filter, another log).
  const previous = useRef({ lines, key: `${group}|${term}|${severity}` });

  // eslint-disable-next-line react-hooks/incompatible-library -- TanStack Virtual's useVirtualizer is the same known false positive as useReactTable
  // Reversed for rendering only; `lines` stays chronological so append
  // counting works.
  const rows = useMemo(
    () => (newestFirst ? [...lines].reverse() : lines),
    [lines, newestFirst],
  );

  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => ROW_HEIGHT,
    overscan: 24,
  });

  // "The newest end", whichever end that is.
  const scrollToNewest = useCallback(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = newestFirst ? 0 : el.scrollHeight;
    setUnseen(0);
  }, [newestFirst]);

  const onScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    const bottom = newestFirst
      ? el.scrollTop <= BOTTOM_SLACK
      : el.scrollHeight - el.clientHeight - el.scrollTop <= BOTTOM_SLACK;
    setAtBottom(bottom);
    setScrolled(el.scrollTop > 4);
    onAtBottomChange?.(bottom);
    if (bottom) setUnseen(0);
  }, [onAtBottomChange, newestFirst]);

  // Appends land after paint; stick to the newest end only if the reader was
  // already there, otherwise count unseen lines. Counted by what is new, not
  // by length growth: a full tail window never changes length.
  useLayoutEffect(() => {
    const key = `${group}|${term}|${severity}`;
    const before = previous.current;
    previous.current = { lines, key };
    if (before.key !== key || !before.lines.length || !lines.length) return;
    const { added, dropped } = appended(before.lines, lines);
    if (added === 0) return;
    if (atBottom) {
      scrollToNewest();
      return;
    }
    setUnseen((n) => n + added);
    // Keep the reader on the line they were reading.
    const el = scrollRef.current;
    if (!el) return;
    if (newestFirst) el.scrollTop += added * ROW_HEIGHT;
    else if (dropped > 0) el.scrollTop = Math.max(0, el.scrollTop - dropped * ROW_HEIGHT);
  }, [lines, group, term, severity, atBottom, newestFirst, scrollToNewest]);

  // A new source (or a new filter) starts at the newest line.
  useEffect(() => {
    scrollToNewest();
    setAtBottom(true);
  }, [group, term, severity, newestFirst, scrollToNewest]);

  // A tab just switched to: never show the previous tab's lines meanwhile.
  if (status === "loading") {
    return (
      <div className="flex min-h-0 flex-1 items-center justify-center gap-2 bg-console text-sm text-console-muted" role="status">
        <Loader2 className="size-4 animate-spin" aria-hidden />
        {loadingText}
      </div>
    );
  }
  if (status === "locked") {
    return <Notice icon={Lock} title={t("locked.title")} body={t("locked.body")} />;
  }
  if (status === "missing") {
    return <Notice icon={FileWarning} title={t("missing.title")} body={t("missing.body")} />;
  }
  // A failed read must not look like an empty file.
  if (status === "failed") {
    return <Notice icon={TriangleAlert} title={t("readFailed.title")} body={failedMessage ?? t("readFailed.body")} />;
  }
  if (!lines.length) {
    // A search over the whole file proves the text is absent; a capped one
    // proves nothing about the rest, so the wording differs.
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
        // Announce politely only while following, not while the user scrolls.
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
              {/* The line's place in the file, not on screen. */}
              <LogLine
                index={newestFirst ? rows.length - item.index : item.index + 1}
                text={rows[item.index]}
                group={group}
                term={term}
                wrap={wrap}
                onCopy={onCopyLine}
                copyLabel={t("copyLine", {
                  index: newestFirst ? rows.length - item.index : item.index + 1,
                })}
              />
            </div>
          ))}
        </div>
      </div>

      {/* Edge fades. Bottom fade only while scrolled up, so a followed tail
          is not dimmed at the line being watched. */}
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
          <Button size="sm" className="pointer-events-auto shadow-md" onClick={scrollToNewest}>
            {/* Towards the newest end, which is the top in newest-first. */}
            {newestFirst ? <ArrowUp className="size-4" /> : <ArrowDown className="size-4" />}
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
