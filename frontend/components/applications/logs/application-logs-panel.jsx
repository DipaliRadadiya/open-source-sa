"use client";

import { cn } from "@/lib/utils";
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
const SOURCES_POLL_MS = 30000;
const TAIL_FAILURES_BEFORE_PAUSE = 3;
const RANGE_LINK =
  "rounded font-medium text-foreground underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

const WEB_FORMAT_KEYS = new Set(["access", "access_ssl", "waf_detect"]);
const PROXY_LOG_KEYS = new Set(["access", "error", "access_ssl", "error_ssl"]);
const groupFor = (key) => (WEB_FORMAT_KEYS.has(key) ? "web" : "system");

const SHORT_LOG = 15;

export function ApplicationLogsPanel({
  appId,
  sources: initialSources,
  selected,
  initial,
  initialLines,
  followPrefs = {},
  canManage = false,
}) {
  const t = useTranslations("logs");
  const tApp = useTranslations("applications.logs");
  const format = useFormatter();

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
      // keep the old figures
    }
  }, [appId]);

  const [current, setCurrent] = useState(selected);
  const [selectedProp, setSelectedProp] = useState(selected);
  const [filters, setFilters] = useState(EMPTY_LOG_FILTERS);
  if (selectedProp !== selected) {
    setSelectedProp(selected);
    setCurrent(selected);
    setFilters(EMPTY_LOG_FILTERS);
  }
  const source = sources.find((s) => s.key === current) ?? null;
  const hasAppOutput = sources.some((s) => s.key.startsWith("application"));

  const [lines, setLines] = useState(() => cleanLines(initial?.log?.lines));
  const [status, setStatus] = useState(initial?.status ?? "ok");
  const [failedMessage, setFailedMessage] = useState(initial?.message ?? null);
  const statusRef = useRef(status);
  useEffect(() => {
    statusRef.current = status;
  }, [status]);
  const [truncated, setTruncated] = useState(Boolean(initial?.log?.truncated));
  const [searchCapped, setSearchCapped] = useState(
    Boolean(initial?.log?.search_window_capped),
  );
  const [firstLine, setFirstLine] = useState(initial?.log?.first_line ?? null);
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  const [newestFirst, setNewestFirst] = useState(false);
  const [prefs, setPrefs] = useState(followPrefs);
  const [follow, setFollow] = useState(() => followPrefFor(current, followPrefs));
  const [busy, setBusy] = useState(false);
  const [tailState, setTailState] = useState("idle");
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
        if (error?.code === "ERR_CANCELED") return null;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else {
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

  const firstRun = useRef(true);
  useEffect(() => {
    if (firstRun.current) {
      firstRun.current = false;
      return;
    }
    load();
  }, [load]);

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

  useEffect(() => {
    if (!follow || disabled) return undefined;
    const id = setInterval(() => {
      if (!document.hidden) refreshSources();
    }, SOURCES_POLL_MS);
    return () => clearInterval(id);
  }, [follow, disabled, refreshSources]);

  const group = source ? groupFor(source.key) : undefined;
  const modified = parseModified(source?.modified);
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
  const shortLog =
    !["loading", "locked", "missing", "failed"].includes(status) && visible.length <= SHORT_LOG;

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

  const showProxyHint = hasAppOutput && PROXY_LOG_KEYS.has(current);

  const fieldFilters = current === "access";
  const serverFiltered = Boolean(debouncedTerm) || hasFieldFilters(filters);
  const pageable = firstLine != null && !serverFiltered;
  const goToLine = (line) => setFilters({ ...filters, from: String(Math.max(1, line)) });

  return (
    <Tabs
      value={current ?? undefined}
      onValueChange={selectSource}
      className="gap-4"
    >
      <TabsContent value={current ?? ""} className="flex-none text-[length:inherit]">
      <section
        className={cn(
          "flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm",
          shortLog ? "[&_[role=log]]:min-h-48" : "h-[calc(100svh-16rem)] min-h-[34rem] lg:min-h-[24rem]",
        )}
      >
        <LogToolbar
          label={source?.label ?? t("noSource")}
          heading={
            <ScrollFade className="-my-1 min-w-0 py-1">
              <TabsList className="!h-auto w-fit gap-1 p-1">
                {sources.map((s) => (
                  <TabsTrigger key={s.key} value={s.key} className="!h-auto gap-2 px-3.5 py-1.5">
                    {s.label}
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
