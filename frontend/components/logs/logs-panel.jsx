"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  clearLog,
  readLog,
  listLogSources,
  logDownloadUrl,
} from "@/lib/api/logs";
import { LINE_OPTIONS, logSourcesResponseSchema } from "@/lib/schemas/log";
import { matchesSeverity, isAccessLog } from "@/lib/logs/severity";
import { LogSourceList } from "@/components/logs/log-source-list";
import { LogToolbar } from "@/components/logs/log-toolbar";
import { LogViewer } from "@/components/logs/log-viewer";
import { AUTO_FOLLOW_MAX_BYTES, FOLLOW_COOKIE, LINES_COOKIE, resolveFollow } from "@/lib/logs/follow-preference";
import { writeCookie } from "@/lib/logs/app-log-prefs";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Eraser } from "lucide-react";
import { cleanLines } from "@/lib/logs/clean-lines";
import { DENIED } from "@/components/ui/reason-tooltip";

const POLL_MS = 3000;
const MAX_BUFFER = 10000;

const TAIL_FAILURES_BEFORE_PAUSE = 3;
const CATALOG_MS = 30000;

export function LogsPanel({
  sources: initialSources,
  selected,
  initial,
  initialLines,
  followPreference,
  renderedAt,
  canManage = false,
}) {
  const t = useTranslations("logs");

  const [polledSources, setPolledSources] = useState(null);
  const [now, setNow] = useState(renderedAt);
  const [renderedWith, setRenderedWith] = useState(initialSources);
  if (renderedWith !== initialSources) {
    setRenderedWith(initialSources);
    setPolledSources(null);
    setNow(renderedAt);
  }
  const sources = polledSources ?? initialSources;
  const [current, setCurrent] = useState(selected);
  const source = sources.find((s) => s.key === current) ?? null;
  const sourceKey = source?.key ?? null;
  const readable = Boolean(source?.readable);
  const appends = source?.follow !== false;

  const [lines, setLines] = useState(() => cleanLines(initial?.log?.lines));
  const [status, setStatus] = useState(initial?.status ?? "ok");
  const [failedMessage, setFailedMessage] = useState(initial?.message ?? null);
  const statusRef = useRef(status);
  useEffect(() => {
    statusRef.current = status;
  }, [status]);
  const [truncated, setTruncated] = useState(Boolean(initial?.log?.truncated));
  const [searchCapped, setSearchCapped] = useState(Boolean(initial?.log?.search_window_capped));
  const [clearing, setClearing] = useState(false);
  const [confirmClear, setConfirmClear] = useState(false);
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  const [newestFirst, setNewestFirst] = useState(false);
  const [follow, setFollow] = useState(() => resolveFollow(followPreference, source));
  const [followPref, setFollowPref] = useState(followPreference);
  const [busy, setBusy] = useState(false);

  const changeFollow = useCallback((next) => {
    setFollow(next);
    setFollowPref(next ? "on" : "off");
    try {
      document.cookie = `${FOLLOW_COOKIE}=${next ? "on" : "off"}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    } catch {
      // storage blocked; ignore
    }
  }, []);
  const chooseLines = useCallback((next) => {
    setLineCount(next);
    writeCookie(LINES_COOKIE, String(next));
  }, []);
  const [tailState, setTailState] = useState("idle");

  const cursor = useRef(initial?.log?.cursor ?? 0);

  // A new ?source= doesn't remount, so reset follow and lines during render.
  const [renderedSource, setRenderedSource] = useState(selected);
  if (renderedSource !== selected) {
    setRenderedSource(selected);
    setCurrent(selected);
    setLines(cleanLines(initial?.log?.lines));
    setStatus(initial?.status ?? "ok");
    setFailedMessage(initial?.message ?? null);
    setTruncated(Boolean(initial?.log?.truncated));
    setSearchCapped(Boolean(initial?.log?.search_window_capped));
    setTailState("idle");
    setFollow(resolveFollow(followPref, sources.find((s) => s.key === selected)));
  }

  // A ref can't be written during render.
  useEffect(() => {
    cursor.current = initial?.log?.cursor ?? 0;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected]);
  const controller = useRef(null);
  const atBottom = useRef(true);

  const disabled = !source?.readable || status !== "ok";

  useEffect(() => {
    const id = setTimeout(() => setDebouncedTerm(term.trim()), 300);
    return () => clearTimeout(id);
  }, [term]);

  const reloadSources = useCallback(async (isActive = () => true) => {
    try {
      const { data } = await listLogSources();
      const parsed = logSourcesResponseSchema.safeParse(data);
      if (isActive() && parsed.success) {
        setPolledSources(parsed.data.logs);
        setNow(Date.now());
      }
    } catch {
      // storage blocked; ignore
    }
  }, []);

  useEffect(() => {
    let active = true;

    async function tick() {
      if (document.hidden) return;
      await reloadSources(() => active);
    }

    const id = setInterval(tick, CATALOG_MS);
    return () => {
      active = false;
      clearInterval(id);
    };
  }, [reloadSources]);

  const load = useCallback(
    async ({ silent } = {}) => {
      if (!sourceKey || !readable) return;
      controller.current?.abort();
      const ctrl = new AbortController();
      controller.current = ctrl;
      if (!silent) setBusy(true);
      try {
        const { data } = await readLog(sourceKey, {
          lines: lineCount,
          grep: debouncedTerm || undefined,
          signal: ctrl.signal,
        });
        setLines(cleanLines(data?.log?.lines));
        setTruncated(Boolean(data?.log?.truncated));
        setSearchCapped(Boolean(data?.log?.search_window_capped));
        setStatus("ok");
        setFailedMessage(null);
        cursor.current = data?.log?.cursor ?? 0;
      } catch (error) {
        if (error?.code === "ERR_CANCELED") return;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else if (statusRef.current === "loading") {
          setStatus("failed");
          setFailedMessage(apiMessage(error, null) || null);
        } else toast.error(apiMessage(error, t("loadFailed")));
      } finally {
        setBusy(false);
      }
    },
    [sourceKey, readable, lineCount, debouncedTerm, t],
  );

  const firstRun = useRef(true);
  useEffect(() => {
    if (firstRun.current) {
      firstRun.current = false;
      return;
    }
    load();
  }, [load]);

  // The API filters the whole file, not the new slice, so following pauses while a filter is on.
  useEffect(() => {
    if (!follow || disabled || debouncedTerm) return undefined;
    let active = true;

    let failures = 0;

    async function tick() {
      if (document.hidden) return;
      try {
        if (!appends) {
          const { data } = await readLog(sourceKey, { lines: lineCount });
          if (!active) return;
          setLines(cleanLines(data?.log?.lines));
          setTruncated(Boolean(data?.log?.truncated));
          failures = 0;
          setTailState("live");
          return;
        }
        const { data } = await readLog(sourceKey, { after: cursor.current });
        if (!active) return;
        const next = data?.log?.cursor ?? 0;
        const fresh = cleanLines(data?.log?.lines);
        if (next < cursor.current) setLines(fresh);
        else if (fresh.length) {
          setLines((prev) => [...prev, ...fresh].slice(-MAX_BUFFER));
        }
        cursor.current = next;
        failures = 0;
        setTailState("live");
      } catch {
        if (!active) return;
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
  }, [follow, disabled, debouncedTerm, sourceKey, appends, lineCount]);

  const visible = useMemo(
    () =>
      severity === "all"
        ? lines
        : lines.filter((line) => matchesSeverity(line, source?.group, severity)),
    [lines, severity, source],
  );

  const effectiveTail =
    tailState === "paused"
      ? "paused"
      : debouncedTerm && follow && !disabled
        ? "filtering"
        : follow && !disabled
          ? tailState === "reconnecting"
            ? "reconnecting"
            : "live"
          : "idle";

  const nextLineStep = LINE_OPTIONS.find((n) => n > lineCount) ?? null;

  const searchRef = useRef(null);

  useEffect(() => {
    function onKey(event) {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target?.tagName ?? "");
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

  // The server truncates in place, so the byte cursor must go back to 0.
  const clearSelected = useCallback(async () => {
    if (!source) return;

    setClearing(true);
    try {
      await clearLog(source.key);
      setLines([]);
      setTruncated(false);
      cursor.current = 0;
      setConfirmClear(false);
      toast.success(t("clearDone", { label: source.label }));
      reloadSources();
    } catch (error) {
      toast.error(apiMessage(error, t("clearFailed")));
    } finally {
      setClearing(false);
    }
  }, [source, t, reloadSources]);

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
      setStatus("loading");
      setFailedMessage(null);
      setTailState("idle");
      setFollow(resolveFollow(followPref, sources.find((s) => s.key === key)));
      cursor.current = 0;
      const url = new URL(window.location.href);
      url.searchParams.set("source", key);
      window.history.replaceState(window.history.state, "", url);
    },
    [current, followPref, sources],
  );

  return (
    <div className="grid gap-6 lg:h-[calc(100svh-13rem)] lg:min-h-[24rem] lg:grid-cols-[16.5rem_minmax(0,1fr)]">
      <aside className="lg:h-full lg:overflow-y-auto">
        <LogSourceList sources={sources} selected={current} onSelect={selectSource} now={now} />
      </aside>

      <section className="flex h-[calc(100svh-13rem)] min-h-[24rem] flex-col overflow-hidden rounded-xl border bg-card shadow-sm lg:h-full lg:min-h-0">
        <LogToolbar
          label={source?.label ?? t("noSource")}
          shown={visible.length}
          loaded={lines.length}
          wholeFile={!truncated && lines.length > 0 && source?.size != null}
          term={term}
          onTermChange={setTerm}
          severity={severity}
          statusFilter={isAccessLog(source?.key)}
          onSeverityChange={setSeverity}
          lines={lineCount}
          onLinesChange={chooseLines}
          follow={follow}
          onFollowChange={changeFollow}
          wrap={wrap}
          newestFirst={newestFirst}
          onNewestFirstChange={setNewestFirst}
          onWrapChange={setWrap}
          onReload={() => load()}
          onCopyVisible={() =>
            copy(visible.join("\n"), t("copiedLines", { count: visible.length }))
          }
          downloadUrl={source ? logDownloadUrl(source.key) : undefined}
          downloadReason={source?.downloadable === false ? t("notDownloadable") : null}
          onClear={source ? () => setConfirmClear(true) : null}
          clearReason={!source?.clearable ? t("notClearable") : canManage ? null : DENIED}
          followHint={
            !follow && followPref !== "off" && appends && source?.readable && (source?.size ?? 0) > AUTO_FOLLOW_MAX_BYTES
              ? t("liveOffLarge")
              : null
          }
          clearing={clearing}
          busy={busy}
          disabled={disabled}
          reloadable={status === "failed"}
          reloadReason={status === "locked" ? t("locked.title") : status === "missing" ? t("missing.title") : null}
          searchRef={searchRef}
          tailState={effectiveTail}
          onResume={() => {
            setTailState("idle");
            setFollow(true);
          }}
        />

        {truncated && status === "ok" && lines.length > 0 ? (
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
          group={source?.group}
          term={debouncedTerm}
          severity={severity}
          filtered={Boolean(debouncedTerm) || severity !== "all"}
          searchCapped={searchCapped}
          searchedLines={lineCount}
          wrap={wrap}
          newestFirst={newestFirst}
          status={status}
          loadingText={t("loadingSource", { label: source?.label ?? "" })}
          failedMessage={failedMessage}
          following={follow && !debouncedTerm}
          onCopyLine={(text) => copy(text, t("copiedLine"))}
          onAtBottomChange={(v) => {
            atBottom.current = v;
          }}
        />
      </section>

      <ConfirmDialog
        open={confirmClear}
        onOpenChange={setConfirmClear}
        icon={Eraser}
        tone="destructive"
        title={t("clearTitle", { label: source?.label ?? "" })}
        description={
          source?.clear_sensitive ? t("clearBodyAudit") : t("clearBody")
        }
        cancelLabel={t("clearCancel")}
        confirmLabel={t("clearSubmit")}
        pending={clearing}
        onConfirm={clearSelected}
      />
    </div>
  );
}
