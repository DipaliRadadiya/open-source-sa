"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info } from "lucide-react";
import { readApplicationLog } from "@/lib/api/application-logs";
import { LINE_OPTIONS } from "@/lib/schemas/log";
import { matchesSeverity } from "@/lib/logs/severity";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { LogToolbar } from "@/components/logs/log-toolbar";
import { LogViewer } from "@/components/logs/log-viewer";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 3000;
// Access logs are a firehose on a busy site — open them paused; error and the
// app's own output are the ones you usually want tailing.
const AUTO_FOLLOW_KEYS = new Set(["error", "application", "application_error"]);
const TAIL_FAILURES_BEFORE_PAUSE = 3;

// Access lines carry an HTTP status (color by 2xx/3xx/4xx/5xx); everything else
// is tinted by level word.
const groupFor = (key) => (key === "access" ? "web" : "system");

export function ApplicationLogsPanel({
  appId,
  sources,
  selected,
  initial,
  initialLines,
}) {
  const t = useTranslations("logs");
  const tApp = useTranslations("applications.logs");
  const router = useRouter();
  const searchParams = useSearchParams();

  const source = sources.find((s) => s.key === selected) ?? null;
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
  const [lineCount, setLineCount] = useState(initialLines);
  const [term, setTerm] = useState("");
  const [debouncedTerm, setDebouncedTerm] = useState("");
  const [severity, setSeverity] = useState("all");
  const [wrap, setWrap] = useState(false);
  const [follow, setFollow] = useState(AUTO_FOLLOW_KEYS.has(selected));
  const [busy, setBusy] = useState(false);
  const [tailState, setTailState] = useState("idle");

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
        setStatus("ok");
        return true;
      } catch (error) {
        if (error?.code === "ERR_CANCELED") return false;
        const code = error?.response?.status;
        if (code === 403) setStatus("locked");
        else if (code === 404) setStatus("missing");
        else if (!silent) toast.error(apiMessage(error, t("loadFailed")));
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
      if (!active) return;
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
      const params = new URLSearchParams(searchParams);
      params.set("source", key);
      router.replace(`/applications/${appId}/logs?${params.toString()}`, {
        scroll: false,
      });
    },
    [router, searchParams, appId],
  );

  // Reverse proxy hint: only on the proxy logs (access/error) of a process site.
  const showProxyHint =
    hasAppOutput && (selected === "access" || selected === "error");

  return (
    <Tabs
      value={selected ?? undefined}
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

      <section className="flex h-[calc(100svh-16rem)] min-h-[24rem] flex-col overflow-hidden rounded-xl border bg-card shadow-sm">
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
          onReload={() => load()}
          onCopyVisible={() =>
            copy(
              visible.join("\n"),
              t("copiedLines", { count: visible.length }),
            )
          }
          showDownload={false}
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
          wrap={wrap}
          status={status}
          following={follow}
          onCopyLine={(text) => copy(text, t("copiedLine"))}
        />
      </section>
    </Tabs>
  );
}
