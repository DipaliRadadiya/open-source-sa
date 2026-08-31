"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { readLog, listLogSources, logDownloadUrl } from "@/lib/api/logs";
import { LINE_OPTIONS, logSourcesResponseSchema } from "@/lib/schemas/log";
import { matchesSeverity } from "@/lib/logs/severity";
import { LogSourceList } from "@/components/logs/log-source-list";
import { LogToolbar } from "@/components/logs/log-toolbar";
import { LogViewer } from "@/components/logs/log-viewer";
import { FOLLOW_COOKIE, resolveFollow } from "@/lib/logs/follow-preference";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 3000;
// Long tailing sessions must not grow without bound.
const MAX_BUFFER = 10000;
// Tail by default only for logs small enough that "live" is useful rather than
// a firehose; big access logs open paused.

// One blip is noise; three in a row means the tail isn't working.
const TAIL_FAILURES_BEFORE_PAUSE = 3;
// The rail's sizes and "written just now" dots are only true at fetch time;
// re-read them often enough that sitting on the page doesn't turn them into a
// picture of ten minutes ago.
const CATALOG_MS = 30000;

export function LogsPanel({
  sources: initialSources,
  selected,
  initial,
  initialLines,
  followPreference,
}) {
  const t = useTranslations("logs");
  const router = useRouter();
  const searchParams = useSearchParams();

  // The catalog has two sources of truth: the server render (authoritative,
  // and newer on every navigation) and our poll. Rather than syncing them in an
  // effect, the poll's result is held separately and dropped the moment a fresh
  // server render arrives.
  const [polledSources, setPolledSources] = useState(null);
  const [renderedWith, setRenderedWith] = useState(initialSources);
  if (renderedWith !== initialSources) {
    setRenderedWith(initialSources);
    setPolledSources(null);
  }
  const sources = polledSources ?? initialSources;
  const source = sources.find((s) => s.key === selected) ?? null;

  const [lines, setLines] = useState(initial?.log?.lines ?? []);
  const [status, setStatus] = useState(initial?.status ?? "ok");
  const [truncated, setTruncated] = useState(Boolean(initial?.log?.truncated));
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  const [follow, setFollow] = useState(() => resolveFollow(followPreference, source));
  const [busy, setBusy] = useState(false);

  // Remembered across refreshes and across log sources. A cookie rather than
  // localStorage so the server render already knows — read after mount, the
  // tail would start, then stop, which is the flicker this exists to remove.
  const changeFollow = useCallback((next) => {
    setFollow(next);
    try {
      document.cookie = `${FOLLOW_COOKIE}=${next ? "on" : "off"}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    } catch {
      // A blocked cookie costs the preference, nothing else.
    }
  }, []);
  // "reconnecting" after a blip, "paused" once we stop trying — a tail that
  // silently stops is indistinguishable from a log that went quiet.
  const [tailState, setTailState] = useState("idle");

  const cursor = useRef(initial?.log?.cursor ?? 0);

  /*
   * Picking a different log only replaces the URL, so this component is NOT
   * remounted — and every `useState` above keeps the value it was seeded with
   * for the source you were reading before. Two of those matter:
   *
   *   - `follow`. Auto-follow is deliberately off above AUTO_FOLLOW_MAX_BYTES,
   *     but that was decided once, from the FIRST source. Opening a 4 KB
   *     nginx error log and then switching to a 10 MB syslog left the tail
   *     running against exactly the file the limit exists to protect.
   *   - `lines`. The previous log's content sat under the new log's name until
   *     the re-read landed, which on a large grep is seconds of the wrong file
   *     presented as the right one.
   *
   * Reset during render, the same way the catalog above drops a stale poll,
   * rather than in an effect — an effect would paint the old log first.
   *
   * Deliberately NOT reset: the search term, severity, wrap and line count.
   * Those are how the reader wants logs shown, not facts about one file, and
   * the toolbar keeps them visible.
   */
  const [renderedSource, setRenderedSource] = useState(selected);
  if (renderedSource !== selected) {
    setRenderedSource(selected);
    setLines(initial?.log?.lines ?? []);
    setStatus(initial?.status ?? "ok");
    setTruncated(Boolean(initial?.log?.truncated));
    setTailState("idle");
    setFollow(resolveFollow(followPreference, source));
  }

  // The cursor moves with them, but a ref cannot be written during render and
  // the lint rule is right to say so. An effect is early enough: the tail's
  // first tick is a full POLL_MS away, so it can never read the old file's
  // offset against the new file.
  useEffect(() => {
    cursor.current = initial?.log?.cursor ?? 0;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected]);
  const controller = useRef(null);
  const atBottom = useRef(true);

  const disabled = !source?.readable || status !== "ok";

  // Server-side grep: debounced so typing doesn't hammer a 10 MB file.
  useEffect(() => {
    const id = setTimeout(() => setDebouncedTerm(term.trim()), 300);
    return () => clearTimeout(id);
  }, [term]);

  // Catalog refresh. Failure is silent: a rail one interval out of date beats a
  // toast for something the reader never asked to happen.
  useEffect(() => {
    let active = true;

    async function tick() {
      if (document.hidden) return;
      try {
        const { data } = await listLogSources();
        const parsed = logSourcesResponseSchema.safeParse(data);
        if (active && parsed.success) setPolledSources(parsed.data.logs);
      } catch {
        /* keep the last known catalog */
      }
    }

    const id = setInterval(tick, CATALOG_MS);
    return () => {
      active = false;
      clearInterval(id);
    };
  }, []);

  const load = useCallback(
    async ({ silent } = {}) => {
      if (!source?.readable) return;
      controller.current?.abort();
      const ctrl = new AbortController();
      controller.current = ctrl;
      if (!silent) setBusy(true);
      try {
        const { data } = await readLog(source.key, {
          lines: lineCount,
          grep: debouncedTerm || undefined,
          signal: ctrl.signal,
        });
        setLines(data?.log?.lines ?? []);
        setTruncated(Boolean(data?.log?.truncated));
        setStatus("ok");
        cursor.current = data?.log?.cursor ?? 0;
      } catch (error) {
        if (error?.code === "ERR_CANCELED") return;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else toast.error(apiMessage(error, t("loadFailed")));
      } finally {
        setBusy(false);
      }
    },
    [source, lineCount, debouncedTerm, t],
  );

  // Re-read whenever the source, window size or filter changes. The initial
  // render already has server-fetched content, so skip that first pass.
  const firstRun = useRef(true);
  useEffect(() => {
    if (firstRun.current) {
      firstRun.current = false;
      return;
    }
    load();
  }, [load]);

  // Live tail. Grep and tailing don't compose (the API filters the whole file,
  // not the appended slice), so following pauses while a filter is active.
  useEffect(() => {
    if (!follow || disabled || debouncedTerm) return undefined;
    let active = true;

    let failures = 0;

    async function tick() {
      if (document.hidden) return;
      try {
        const { data } = await readLog(source.key, { after: cursor.current });
        if (!active) return;
        const next = data?.log?.cursor ?? 0;
        const fresh = data?.log?.lines ?? [];
        // Rotation: the file shrank, so what we hold is history of a file that
        // no longer exists — replace rather than append.
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
        // One blip is noise; three in a row means the tail is not working, and
        // a silently-stalled tail looks exactly like a log that went quiet.
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
  }, [follow, disabled, debouncedTerm, source]);

  // Severity narrows what's on screen without another round trip, so it costs
  // nothing to keep tailing underneath it.
  const visible = useMemo(
    () =>
      severity === "all"
        ? lines
        : lines.filter((line) => matchesSeverity(line, source?.group, severity)),
    [lines, severity, source],
  );

  // Derived, not stored: "paused" is the only state worth remembering (we
  // stopped trying), everything else follows from whether we're polling.
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

  // The next bigger window, so the banner can offer one click instead of
  // sending the reader off to hunt for the selector.
  const nextLineStep = LINE_OPTIONS.find((n) => n > lineCount) ?? null;

  const searchRef = useRef(null);

  // "/" to filter, Escape to clear — the two shortcuts every log tool has.
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

  const copy = useCallback(
    async (text, message) => {
      try {
        await navigator.clipboard.writeText(text);
        toast.success(message);
      } catch {
        // Denied permission or an insecure context — say so rather than
        // letting a click do nothing.
        toast.error(t("copyFailed"));
      }
    },
    [t],
  );

  const selectSource = useCallback(
    (key) => {
      const params = new URLSearchParams(searchParams);
      params.set("source", key);
      router.replace(`/logs?${params.toString()}`, { scroll: false });
    },
    [router, searchParams],
  );

  return (
    <div className="grid gap-6 lg:h-[calc(100svh-13rem)] lg:min-h-[24rem] lg:grid-cols-[16.5rem_minmax(0,1fr)]">
      <aside className="lg:h-full lg:overflow-y-auto">
        <LogSourceList sources={sources} selected={selected} onSelect={selectSource} />
      </aside>

      <section className="flex h-[calc(100svh-13rem)] min-h-[24rem] flex-col overflow-hidden rounded-xl border bg-card shadow-sm lg:h-full lg:min-h-0">
        <LogToolbar
          label={source?.label ?? t("noSource")}
          shown={visible.length}
          loaded={lines.length}
          // Only worth stating when it isn't what the selector already says:
          // the file came up short of the window we asked for.
          wholeFile={!truncated && lines.length > 0}
          term={term}
          onTermChange={setTerm}
          severity={severity}
          onSeverityChange={setSeverity}
          lines={lineCount}
          onLinesChange={setLineCount}
          follow={follow}
          onFollowChange={changeFollow}
          wrap={wrap}
          onWrapChange={setWrap}
          onReload={() => load()}
          onCopyVisible={() =>
            copy(visible.join("\n"), t("copiedLines", { count: visible.length }))
          }
          downloadUrl={source ? logDownloadUrl(source.key) : undefined}
          busy={busy}
          disabled={disabled}
          searchRef={searchRef}
          tailState={effectiveTail}
          onResume={() => {
            setTailState("idle");
            setFollow(true);
          }}
        />

        {/* With a filter active the window caps the *matches*, so the count
            above is "what we're showing", not "how many exist" — say which. */}
        {truncated && status === "ok" && lines.length > 0 ? (
          // Chrome, not console: styled dark it read as another log line, which
          // is the one thing it must not look like — it's the app talking about
          // the file, not the file. It carries the fix rather than describing it.
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
            {/* No count here: the selector above already states the window,
                and repeating it made the same number appear three times. */}
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
          group={source?.group}
          term={debouncedTerm}
          severity={severity}
          filtered={Boolean(debouncedTerm) || severity !== "all"}
          wrap={wrap}
          status={status}
          following={follow && !debouncedTerm}
          onCopyLine={(text) => copy(text, t("copiedLine"))}
          onAtBottomChange={(v) => {
            atBottom.current = v;
          }}
        />
      </section>
    </div>
  );
}
