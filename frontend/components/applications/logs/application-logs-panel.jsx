"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info } from "lucide-react";
import {
  clearApplicationLog,
  readApplicationLog,
} from "@/lib/api/application-logs";
import { LINE_OPTIONS } from "@/lib/schemas/log";
import { matchesSeverity } from "@/lib/logs/severity";
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

const POLL_MS = 3000;
const TAIL_FAILURES_BEFORE_PAUSE = 3;

// Sources whose lines carry an HTTP status; `waf_detect` uses `combined` format.
const WEB_FORMAT_KEYS = new Set(["access", "waf_detect"]);
const groupFor = (key) => (WEB_FORMAT_KEYS.has(key) ? "web" : "system");

export function ApplicationLogsPanel({
  appId,
  sources,
  selected,
  initial,
  initialLines,
  // Live per source as the reader last left it; unset keys use the defaults.
  followPrefs = {},
  canManage = false,
}) {
  const t = useTranslations("logs");
  const tApp = useTranslations("applications.logs");

  // Tabs switch client-side (a navigation re-rendered the whole page on the
  // server); the URL is still updated so a reload or link opens the same tab.
  const [current, setCurrent] = useState(selected);
  // A real navigation to a different `?source=` (Back, a link) still wins.
  const [selectedProp, setSelectedProp] = useState(selected);
  if (selectedProp !== selected) {
    setSelectedProp(selected);
    setCurrent(selected);
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
          signal: ctrl.signal,
        });
        setLines(cleanLines(data?.log?.lines));
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
    [appId, source, lineCount, debouncedTerm, t],
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
  useEffect(() => {
    if (!follow || disabled) return undefined;
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
  }, [follow, disabled, load]);

  const group = source ? groupFor(source.key) : undefined;

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
    } catch (error) {
      toast.error(apiMessage(error, tApp("clear.failed")));
    } finally {
      setClearing(false);
    }
  }, [appId, current, source, tApp]);

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

  return (
    <Tabs
      value={current ?? undefined}
      onValueChange={selectSource}
      className="gap-4"
    >
      {/* Tabs, not a rail: a site has only 2–3 sources. Scrolls rather than
          wraps; ScrollFade signals more to the side. */}
      <ScrollFade className="-mx-1 px-1 pb-1">
        <TabsList className="!h-auto w-fit gap-1 p-1">
          {sources.map((s) => (
            <TabsTrigger
              key={s.key}
              value={s.key}
              className="!h-auto gap-2 px-4 py-2"
            >
              {s.label}
              {!s.exists ? (
                <span className="text-xs font-normal text-muted-foreground">
                  {tApp("empty.badge")}
                </span>
              ) : null}
            </TabsTrigger>
          ))}
        </TabsList>
      </ScrollFade>

      {/* Target of the tabs' aria-controls; text size and flex reset so the console is unchanged. */}
      <TabsContent value={current ?? ""} className="flex-none text-[length:inherit]">
      <section className="flex h-[calc(100svh-16rem)] min-h-[34rem] flex-col overflow-hidden rounded-xl border bg-card shadow-sm lg:min-h-[24rem]">
        <LogToolbar
          label={source?.label ?? t("noSource")}
          shown={visible.length}
          loaded={lines.length}
          wholeFile={!truncated && lines.length > 0}
          term={term}
          onTermChange={setTerm}
          severity={severity}
          onSeverityChange={setSeverity}
          lines={lineCount}
          onLinesChange={chooseLines}
          follow={follow}
          onFollowChange={chooseFollow}
          wrap={wrap}
          onWrapChange={setWrap}
          newestFirst={newestFirst}
          onNewestFirstChange={setNewestFirst}
          onReload={() => load()}
          onCopyVisible={() =>
            copy(
              visible.join("\n"),
              t("copiedLines", { count: visible.length }),
            )
          }
          showDownload={false}
          onClear={canManage ? () => setConfirmClear(true) : null}
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

        {showProxyHint ? (
          <p className="flex items-start gap-2 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3.5 shrink-0" />
            <span>{tApp("proxyHint")}</span>
          </p>
        ) : null}

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
          group={group}
          term={debouncedTerm}
          severity={severity}
          filtered={Boolean(debouncedTerm) || severity !== "all"}
          searchCapped={searchCapped}
          searchedLines={lineCount}
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
