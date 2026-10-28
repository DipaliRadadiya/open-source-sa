"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info } from "lucide-react";
import {
  clearApplicationLog,
  listApplicationLogs,
  readApplicationLog,
  applicationLogDownloadUrl,
} from "@/lib/api/application-logs";
import { LINE_OPTIONS, MAX_LINES } from "@/lib/schemas/log";
import { EMPTY_LOG_FILTERS } from "@/lib/schemas/application-log";
import { ActiveLogFilters, LogFiltersButton, hasFieldFilters } from "@/components/applications/logs/log-filters";
import { matchesSeverity, isAccessLog } from "@/lib/logs/severity";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { LogToolbar } from "@/components/logs/log-toolbar";
import { LogViewer } from "@/components/logs/log-viewer";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Eraser } from "lucide-react";
import { apiMessage } from "@/lib/api/error-message";
import {
  APP_LOG_FOLLOW_COOKIE,
  APP_LOG_LINES_COOKIE,
  followFor as followPrefFor,
  serializeFollowPrefs,
  writeCookie,
} from "@/lib/logs/app-log-prefs";
import { cleanLines } from "@/lib/logs/clean-lines";
import { formatBytes } from "@/lib/format/bytes";
import { parseModified } from "@/lib/logs/recent";

const POLL_MS = 3000;
// The tab sizes and last write, while the log is followed: each read stats every file.
const SOURCES_POLL_MS = 30000;
const TAIL_FAILURES_BEFORE_PAUSE = 3;
const RANGE_LINK =
  "rounded font-medium text-foreground underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

// Sources whose lines carry an HTTP status; `waf_detect` uses `combined` format.
const WEB_FORMAT_KEYS = new Set(["access", "waf_detect"]);
const groupFor = (key) => (WEB_FORMAT_KEYS.has(key) ? "web" : "system");

export function ApplicationLogsPanel({
  appId,
  sources: initialSources,
  selected,
  initial,
  initialLines,
  // Live per source as the reader last left it; unset keys use the defaults.
  followPrefs = {},
  canManage = false,
}) {
  const t = useTranslations("logs");
  const tApp = useTranslations("applications.logs");
  const format = useFormatter();

  // Sizes and last writes, re-read after a clear or a reload; a new page read wins.
  const [sources, setSources] = useState(initialSources);
  const [sourcesProp, setSourcesProp] = useState(initialSources);
  if (sourcesProp !== initialSources) {
    setSourcesProp(initialSources);
    setSources(initialSources);
  }
  const refreshSources = useCallback(async () => {
    try {
      setSources(await listApplicationLogs(appId));
    } catch {
      // The figures stay as they were; the lines are what the reader asked for.
    }
  }, [appId]);

  // Tabs switch client-side (a navigation re-rendered the whole page on the
  // server); the URL is still updated so a reload or link opens the same tab.
  const [current, setCurrent] = useState(selected);
  // A real navigation to a different `?source=` (Back, a link) still wins.
  const [selectedProp, setSelectedProp] = useState(selected);
  // Line numbers and access-log fields belong to one file, so a tab starts unfiltered.
  const [filters, setFilters] = useState(EMPTY_LOG_FILTERS);
  if (selectedProp !== selected) {
    setSelectedProp(selected);
    setCurrent(selected);
    setFilters(EMPTY_LOG_FILTERS);
  }
  const source = sources.find((s) => s.key === current) ?? null;
  // With an app process, access/error describe the reverse proxy. Keyed on the
  // source key, not `kind`: the unit can write to files too.
  const hasAppOutput = sources.some((s) => s.key.startsWith("application"));

  const [lines, setLines] = useState(() => cleanLines(initial?.log?.lines));
  const [status, setStatus] = useState(initial?.status ?? "ok");
  const [failedMessage, setFailedMessage] = useState(initial?.message ?? null);
  // Read inside `load`'s catch: a first read that fails shows its box, a
  // reload of lines already on screen keeps them and says so in a toast.
  const statusRef = useRef(status);
  useEffect(() => {
    statusRef.current = status;
  }, [status]);
  const [truncated, setTruncated] = useState(Boolean(initial?.log?.truncated));
  // Only meaningful while filtering: the API sets it when the search covered
  // just the tail of the file.
  const [searchCapped, setSearchCapped] = useState(
    Boolean(initial?.log?.search_window_capped),
  );
  const [firstLine, setFirstLine] = useState(initial?.log?.first_line ?? null);
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  // Oldest-first by default, like a console and the server Logs panel. The
  // toolbar requires `onNewestFirstChange`; omitting it throws on click.
  const [newestFirst, setNewestFirst] = useState(false);
  const [prefs, setPrefs] = useState(followPrefs);
  const [follow, setFollow] = useState(() => followPrefFor(current, followPrefs));
  const [busy, setBusy] = useState(false);
  const [tailState, setTailState] = useState("idle");
  // Each tab opens with its own follow default. The component survives tab
  // switches, so reset during render (not in an effect) to avoid polling the wrong tab.
  const [followFor, setFollowFor] = useState(current);
  if (followFor !== current) {
    setFollowFor(current);
    setFollow(followPrefFor(current, prefs));
    setTailState("idle");
  }
  const [clearing, setClearing] = useState(false);
  const [confirmClear, setConfirmClear] = useState(false);

  const controller = useRef(null);
  const disabled =
    status === "locked" || status === "missing" || status === "failed";

  useEffect(() => {
    const id = setTimeout(() => setDebouncedTerm(term.trim()), 300);
    return () => clearTimeout(id);
  }, [term]);

  const load = useCallback(
    async ({ silent } = {}) => {
      if (!source) return;
      controller.current?.abort();
      const ctrl = new AbortController();
      controller.current = ctrl;
      if (!silent) setBusy(true);
      try {
        const { data } = await readApplicationLog(appId, source.key, {
          lines: lineCount,
          grep: debouncedTerm || undefined,
          filters,
          signal: ctrl.signal,
        });
        setLines(cleanLines(data?.log?.lines));
        setFirstLine(data?.log?.first_line ?? null);
        setTruncated(Boolean(data?.log?.truncated));
        setSearchCapped(Boolean(data?.log?.search_window_capped));
        setStatus("ok");
        setFailedMessage(null);
        return true;
      } catch (error) {
        // Cancelled by a newer read (a search, a reload), not a failure: `null`
        // so the live tail does not count it towards pausing itself.
        if (error?.code === "ERR_CANCELED") return null;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else {
          // A failed first read shows the server's message; a failed reload
          // keeps the lines on screen and toasts.
          if (statusRef.current === "loading") {
            setStatus("failed");
            setFailedMessage(apiMessage(error, null) || null);
          } else if (!silent) toast.error(apiMessage(error, t("loadFailed")));
        }
        return false;
      } finally {
        if (!silent) setBusy(false);
      }
    },
    [appId, source, lineCount, debouncedTerm, filters, t],
  );

  // Re-read on source / window / filter change. The first paint is server-fed.
  const firstRun = useRef(true);
  useEffect(() => {
    if (firstRun.current) {
      firstRun.current = false;
      return;
    }
    load();
  }, [load]);

  // Live tail: no cursor, so re-read the last N (with the same grep) and replace
  // the buffer. Every poll re-filters the whole file, so a filter needs no pause.
  // A read from a line is a fixed window; re-reading it shows nothing new.
  const fromRead = filters.from !== "";
  useEffect(() => {
    if (!follow || disabled || fromRead) return undefined;
    let active = true;
    let failures = 0;

    async function tick() {
      if (document.hidden) return;
      const ok = await load({ silent: true });
      if (!active || ok === null) return;
      if (ok) {
        failures = 0;
        setTailState("live");
      } else {
        failures += 1;
        if (failures >= TAIL_FAILURES_BEFORE_PAUSE) {
          setTailState("paused");
          setFollow(false);
        } else {
          setTailState("reconnecting");
        }
      }
    }

    const id = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      active = false;
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [follow, disabled, fromRead, load]);

  // Live grows the file the tabs measured at load, so their sizes are read again too.
  useEffect(() => {
    if (!follow || disabled) return undefined;
    const id = setInterval(() => {
      if (!document.hidden) refreshSources();
    }, SOURCES_POLL_MS);
    return () => clearInterval(id);
  }, [follow, disabled, refreshSources]);

  const group = source ? groupFor(source.key) : undefined;
  const modified = parseModified(source?.modified);
  // Non-breaking: on a phone the line wrapped between "2:27" and "PM".
  const writtenAt = modified
    ? format.dateTime(modified, { dateStyle: "medium", timeStyle: "short" }).replace(/ /g, "\u00a0")
    : null;

  const visible = useMemo(
    () =>
      severity === "all"
        ? lines
        : lines.filter((line) => matchesSeverity(line, group, severity)),
    [lines, severity, group],
  );

  const effectiveTail =
    tailState === "paused"
      ? "paused"
      : follow && fromRead
        ? "filtering"
        : follow && !disabled
        ? tailState === "reconnecting"
          ? "reconnecting"
          : "live"
        : "idle";

  // Only the reader's choices are remembered, not the automatic pause after failures.
  function chooseFollow(next) {
    setFollow(next);
    const updated = { ...prefs, [current]: next };
    setPrefs(updated);
    writeCookie(APP_LOG_FOLLOW_COOKIE, serializeFollowPrefs(updated));
  }

  function chooseLines(next) {
    setLineCount(next);
    writeCookie(APP_LOG_LINES_COOKIE, String(next));
  }

  const nextLineStep = LINE_OPTIONS.find((n) => n > lineCount) ?? null;
  const searchRef = useRef(null);

  useEffect(() => {
    function onKey(event) {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(
        event.target?.tagName ?? "",
      );
      if (event.key === "/" && !typing) {
        event.preventDefault();
        searchRef.current?.focus();
      } else if (event.key === "Escape" && event.target === searchRef.current) {
        setTerm("");
      }
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, []);

  // Cleared locally, not re-read: a busy log may already have new lines.
  const clearLog = useCallback(async () => {
    setClearing(true);
    try {
      await clearApplicationLog(appId, current);
      setLines([]);
      setTruncated(false);
      setSearchCapped(false);
      setConfirmClear(false);
      toast.success(tApp("clear.done", { label: source?.label ?? current }));
      refreshSources();
    } catch (error) {
      toast.error(apiMessage(error, tApp("clear.failed")));
    } finally {
      setClearing(false);
    }
  }, [appId, current, source, tApp, refreshSources]);

  const copy = useCallback(
    async (text, message) => {
      try {
        await navigator.clipboard.writeText(text);
        toast.success(message);
      } catch {
        toast.error(t("copyFailed"));
      }
    },
    [t],
  );

  const selectSource = useCallback(
    (key) => {
      if (key === current) return;
      setCurrent(key);
      // Clear the old tab's lines while the new tab's load.
      setLines([]);
      setTruncated(false);
      setSearchCapped(false);
      setFirstLine(null);
      setFilters(EMPTY_LOG_FILTERS);
      setStatus("loading");
      setFailedMessage(null);
      const url = new URL(window.location.href);
      url.searchParams.set("source", key);
      window.history.replaceState(window.history.state, "", url);
    },
    [current],
  );

  // Reverse proxy hint: only on the proxy logs (access/error) of a process site.
  const showProxyHint =
    hasAppOutput && (current === "access" || current === "error");

  // IP, status, method and URL are parsed from the access log's format only.
  const fieldFilters = current === "access";
  const serverFiltered = Boolean(debouncedTerm) || hasFieldFilters(filters);
  // Pages only an unfiltered read: a filtered one searches 5,000 lines, not `lines`.
  const pageable = firstLine != null && !serverFiltered;
  const goToLine = (line) => setFilters({ ...filters, from: String(Math.max(1, line)) });

  return (
    <Tabs
      value={current ?? undefined}
      onValueChange={selectSource}
      className="gap-4"
    >
      {/* Target of the tabs' aria-controls; text size and flex reset so the console is unchanged. */}
      <TabsContent value={current ?? ""} className="flex-none text-[length:inherit]">
      <section className="flex h-[calc(100svh-16rem)] min-h-[34rem] flex-col overflow-hidden rounded-xl border bg-card shadow-sm lg:min-h-[24rem]">
        <LogToolbar
          label={source?.label ?? t("noSource")}
          // The source switch IS the card's title: a strip above the card and the same
          // name as its heading said it twice (7 Oct). Scrolls rather than wraps.
          heading={
            <ScrollFade className="-my-1 min-w-0 py-1">
              <TabsList className="!h-auto w-fit gap-1 p-1">
                {sources.map((s) => (
                  <TabsTrigger key={s.key} value={s.key} className="!h-auto gap-2 px-3.5 py-1.5">
                    {s.label}
                    {/* Which log is filling the disk, readable across the tabs. */}
                    {s.size != null ? (
                      <span className="text-xs font-normal tabular-nums text-muted-foreground">
                        {formatBytes(s.size, format)}
                      </span>
                    ) : null}
                    {!s.exists ? (
                      <span className="text-xs font-normal text-muted-foreground">
                        {tApp("empty.badge")}
                      </span>
                    ) : null}
                  </TabsTrigger>
                ))}
              </TabsList>
            </ScrollFade>
          }
          shown={visible.length}
          loaded={lines.length}
          wholeFile={!truncated && lines.length > 0 && !hasFieldFilters(filters) && !fromRead}
          term={term}
          onTermChange={setTerm}
          severity={severity}
          statusFilter={isAccessLog(source?.key)}
          onSeverityChange={setSeverity}
          lines={lineCount}
          onLinesChange={chooseLines}
          follow={follow}
          onFollowChange={chooseFollow}
          wrap={wrap}
          onWrapChange={setWrap}
          newestFirst={newestFirst}
          onNewestFirstChange={setNewestFirst}
          onReload={() => {
            load();
            refreshSources();
          }}
          onCopyVisible={() =>
            copy(
              visible.join("\n"),
              t("copiedLines", { count: visible.length }),
            )
          }
          // Only a file can be downloaded or cleared: Docker keeps a container's
          // output, so the API refuses the download and clear does nothing.
          downloadUrl={source?.kind === "file" ? applicationLogDownloadUrl(appId, source.key) : undefined}
          showDownload={source?.kind === "file"}
          onClear={canManage && source?.kind === "file" ? () => setConfirmClear(true) : null}
          clearing={clearing}
          busy={busy}
          disabled={disabled}
          reloadable={status === "failed"}
          reloadReason={status === "locked" ? t("locked.title") : status === "missing" ? t("missing.title") : null}
          details={writtenAt ? [t("lastWritten", { time: writtenAt })] : []}
          searchRef={searchRef}
          searchAddon={
            <LogFiltersButton
              key={current}
              filters={filters}
              onApply={setFilters}
              fieldFilters={fieldFilters}
              fromAllowed={source?.kind === "file"}
              disabled={disabled}
            />
          }
          tailState={effectiveTail}
          onResume={() => {
            setTailState("idle");
            setFollow(true);
          }}
        />

        {showProxyHint ? (
          <p className="flex items-start gap-2 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3.5 shrink-0" />
            <span>{tApp("proxyHint")}</span>
          </p>
        ) : null}

        <ActiveLogFilters filters={filters} onChange={setFilters} />

        {firstLine != null && status === "ok" ? (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
            <span>
              {pageable && lines.length
                ? tApp("filters.range", {
                    first: format.number(firstLine),
                    last: format.number(firstLine + lines.length - 1),
                  })
                : tApp("filters.rangeFrom", { first: format.number(firstLine) })}
            </span>
            {pageable && firstLine > 1 ? (
              <button type="button" onClick={() => goToLine(firstLine - lineCount)} className={RANGE_LINK}>
                {tApp("filters.previous", { count: lineCount })}
              </button>
            ) : null}
            {pageable && truncated ? (
              <button type="button" onClick={() => goToLine(firstLine + lineCount)} className={RANGE_LINK}>
                {tApp("filters.next", { count: lineCount })}
              </button>
            ) : null}
          </div>
        ) : null}

        {truncated && firstLine == null && status === "ok" && lines.length > 0 ? (
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
            <span>{t("olderNotLoaded")}</span>
            {nextLineStep ? (
              <button
                type="button"
                onClick={() => chooseLines(nextLineStep)}
                className="rounded font-medium text-foreground underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                {t("loadMore", { count: nextLineStep })}
              </button>
            ) : null}
          </div>
        ) : null}

        <LogViewer
          lines={visible}
          group={group}
          term={debouncedTerm}
          severity={severity}
          filtered={serverFiltered || fromRead || severity !== "all"}
          searchCapped={searchCapped}
          // A filtered read searches the API's whole window, whatever `lines` says.
          searchedLines={MAX_LINES}
          wrap={wrap}
          newestFirst={newestFirst}
          status={status}
          loadingText={t("loadingSource", { label: source?.label ?? current })}
          failedMessage={failedMessage}
          following={follow}
          onCopyLine={(text) => copy(text, t("copiedLine"))}
        />
      </section>
      </TabsContent>

      {/* Names the log being cleared; this cannot be undone. */}
      <ConfirmDialog
        open={confirmClear}
        onOpenChange={setConfirmClear}
        icon={Eraser}
        tone="destructive"
        title={tApp("clear.title", { label: source?.label ?? current })}
        description={tApp("clear.body")}
        cancelLabel={tApp("clear.cancel")}
        confirmLabel={tApp("clear.submit")}
        pending={clearing}
        onConfirm={clearLog}
      />
    </Tabs>
  );
}
