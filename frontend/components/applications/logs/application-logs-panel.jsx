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
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { LogToolbar } from "@/components/logs/log-toolbar";
import { LogViewer } from "@/components/logs/log-viewer";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Eraser } from "lucide-react";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 3000;
// Access logs are a firehose on a busy site — open them paused; error and the
// app's own output are the ones you usually want tailing.
const AUTO_FOLLOW_KEYS = new Set(["error", "application", "application_error"]);
const TAIL_FAILURES_BEFORE_PAUSE = 3;

/*
 * Which lines carry an HTTP status (colour and severity by 2xx/3xx/4xx/5xx)
 * rather than a level word.
 *
 * `waf_detect` belongs here too. It is written by the web server in `combined`
 * — byte for byte the same shape as the access log, as parse-detect-log.js says
 * in as many words — but it was falling into "system", so `lineLevel` skipped
 * the status parsing and looked for words like "error" that a combined line
 * never contains. Every line came back with no level, so Errors and Warnings
 * filtered the whole tab down to nothing and the severity tint never appeared.
 */
const WEB_FORMAT_KEYS = new Set(["access", "waf_detect"]);
const groupFor = (key) => (WEB_FORMAT_KEYS.has(key) ? "web" : "system");

export function ApplicationLogsPanel({
  appId,
  sources,
  selected,
  initial,
  initialLines,
  canManage = false,
}) {
  const t = useTranslations("logs");
  const tApp = useTranslations("applications.logs");

  /*
   * The tab on screen. Switched here rather than by navigating: a navigation
   * re-rendered the whole page on the server — about eight requests, the log
   * read twice (once for a first paint this component then ignored) — and on a
   * slow answer the old tab stayed selected for seconds with nothing to say a
   * click had landed. The URL is still updated, so a reload or a shared link
   * opens the same tab.
   */
  const [current, setCurrent] = useState(selected);
  // A real navigation to a different `?source=` (Back, a link) still wins.
  const [selectedProp, setSelectedProp] = useState(selected);
  if (selectedProp !== selected) {
    setSelectedProp(selected);
    setCurrent(selected);
  }
  const source = sources.find((s) => s.key === current) ?? null;
  // An "application" source only exists on a site that runs a process; when it
  // does, access/error describe the reverse proxy, not the app.
  //
  // Keyed on the source key, not its kind: these used to be the only journal
  // sources, so `kind === "journal"` was a workable stand-in until the unit
  // started writing to files in the site's own directory — at which point the
  // test silently stopped matching anything and the hint explaining that
  // access/error are the *proxy's* logs stopped appearing on exactly the sites
  // that need it.
  const hasAppOutput = sources.some((s) => s.key.startsWith("application"));

  const [lines, setLines] = useState(initial?.log?.lines ?? []);
  const [status, setStatus] = useState(initial?.status ?? "ok");
  const [truncated, setTruncated] = useState(Boolean(initial?.log?.truncated));
  // Only meaningful while filtering: the API sets it when the search covered
  // just the tail of the file, which is what makes an empty result honest.
  const [searchCapped, setSearchCapped] = useState(
    Boolean(initial?.log?.search_window_capped),
  );
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  /*
   * Which end the newest line sits at. Oldest-first by default, because that is
   * how a console reads and how a live tail appends — the same default the
   * server Logs panel uses.
   *
   * This was missing entirely. The toolbar renders the control from its own
   * props and the panel passed neither, so `onNewestFirstChange` arrived as
   * undefined and clicking "Newest first" threw `is not a function`. Nothing
   * caught it: a missing prop is not a build error in plain JS, and the server
   * Logs page — which does wire it — works, so the control looked proven.
   */
  const [newestFirst, setNewestFirst] = useState(false);
  const [follow, setFollow] = useState(AUTO_FOLLOW_KEYS.has(current));
  const [busy, setBusy] = useState(false);
  const [tailState, setTailState] = useState("idle");
  // Each tab opens with its own default. Switching tabs keeps this component
  // (only the URL changes), so `follow` used to carry over: Access → Error never
  // started tailing, and Error → Access kept polling the busiest log there is.
  // Adjusted during render, not in an effect, so no frame polls the wrong one.
  const [followFor, setFollowFor] = useState(current);
  if (followFor !== current) {
    setFollowFor(current);
    setFollow(AUTO_FOLLOW_KEYS.has(current));
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
        setLines(data?.log?.lines ?? []);
        setTruncated(Boolean(data?.log?.truncated));
        setSearchCapped(Boolean(data?.log?.search_window_capped));
        setStatus("ok");
        return true;
      } catch (error) {
        // Cancelled by a newer read (a search, a reload), not a failure: `null`
        // so the live tail does not count it towards pausing itself.
        if (error?.code === "ERR_CANCELED") return null;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else {
          // A tab whose first read failed has nothing to show but the failure;
          // a reload of lines already on screen keeps them and says so.
          setStatus((now) => (now === "loading" ? "failed" : now));
          if (!silent) toast.error(apiMessage(error, t("loadFailed")));
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
  // the buffer. grep and tailing compose here because every poll re-filters the
  // whole file — no need to pause following while a filter is active.
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

  /**
   * Empty the selected log.
   *
   * The server truncates rather than deletes, so the source still exists and
   * the viewer is simply emptied — no navigation, no refetch. The lines are
   * cleared from state directly rather than re-reading: a re-read of a
   * just-truncated busy access log can come back with the handful of requests
   * that arrived in between, which reads as the clear having failed.
   */
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
      // The old tab's lines must not sit under the new tab's name while its
      // own are on their way.
      setLines([]);
      setTruncated(false);
      setSearchCapped(false);
      setStatus("loading");
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
      {/* Source picker as tabs: a site has only 2–3 sources, so a full-height
          rail would leave dead space and the console loses width. */}
      {/* Scrolls rather than wraps, same as the Settings tab bar: a bar that
          reflows to two rows stops reading as one control. ScrollFade is what
          says there is more to the side. */}
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
          onLinesChange={setLineCount}
          follow={follow}
          onFollowChange={setFollow}
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
                onClick={() => setLineCount(nextLineStep)}
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
          following={follow}
          onCopyLine={(text) => copy(text, t("copiedLine"))}
        />
      </section>

      {/* Names the log, because "Clear log?" beside a tab strip is ambiguous
          about which one — and this cannot be undone. `destructive` for the
          same reason the restart dialog is: the confirm button should look
          like what it does. */}
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
