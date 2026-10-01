"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";
import { useTranslations } from "next-intl";
import { Loader2, RotateCcw, Unplug } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  PHASE,
  PROBE_TIMEOUT_MS,
  createRestartState,
  isResumable,
  isTakingLonger,
  probeIntervalMs,
  reduceProbe,
  resumeRestartState,
} from "@/lib/server/restart-probe";

const STORAGE_KEY = "sv-oss:restarting-since";

const ServerRestartContext = createContext(null);

export function useServerRestart() {
  const context = useContext(ServerRestartContext);
  if (!context) {
    throw new Error("useServerRestart must be used within a ServerRestartProvider");
  }
  return context;
}

// A dead host and a blocked origin are the same rejected promise here; the
// browser cannot tell them apart, so this screen eventually gives up visibly.
async function probeOnce(url) {
  const controller = new AbortController();
  // Without a timeout, connecting to a host already gone can hang past the poll
  // interval and the down phase is never observed.
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS);

  try {
    const response = await fetch(url, {
      cache: "no-store",
      credentials: "omit",
      signal: controller.signal,
    });
    return response.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}

function readStoredStart() {
  try {
    const raw = window.sessionStorage.getItem(STORAGE_KEY);
    return raw === null ? null : Number(raw);
  } catch {
    return null;
  }
}

function writeStoredStart(startedAt) {
  try {
    window.sessionStorage.setItem(STORAGE_KEY, String(startedAt));
  } catch {
    // A blocked sessionStorage only costs resume-after-reload.
  }
}

function clearStoredStart() {
  try {
    window.sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    // Same.
  }
}

export function ServerRestartProvider({ children }) {
  // `null` is idle; any other value means a restart is being watched.
  const [state, setState] = useState(null);

  const start = useCallback(() => {
    const startedAt = Date.now();
    writeStoredStart(startedAt);
    setState(createRestartState(startedAt));
  }, []);

  // A restart already running when this tab loaded (reload or restored tab);
  // otherwise the panel looks fine while the server is going down.
  useEffect(() => {
    const storedAt = readStoredStart();
    if (storedAt === null) return;

    if (!isResumable(storedAt, Date.now())) {
      clearStoredStart();
      return;
    }

    // Deferred: sessionStorage is only readable after mount. A timeout, not rAF,
    // because frames do not fire in a restored background tab.
    const timer = setTimeout(() => setState(resumeRestartState(storedAt, Date.now())), 0);
    return () => clearTimeout(timer);
  }, []);

  // One probe, then schedule the next from its result; an interval would stack
  // requests when a probe outlives its tick.
  useEffect(() => {
    if (!state) return;
    if (state.phase === PHASE.BACK || state.phase === PHASE.GAVE_UP) return;

    let cancelled = false;

    const timer = setTimeout(async () => {
      const [apiUp, panelUp] = await Promise.all([
        probeOnce(`${process.env.NEXT_PUBLIC_API_URL}/api/health`),
        probeOnce(`${window.location.origin}/api/ping`),
      ]);

      if (cancelled) return;

      setState((previous) =>
        previous ? reduceProbe(previous, { at: Date.now(), apiUp, panelUp }) : previous,
      );
    }, probeIntervalMs(state.elapsedMs));

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [state]);

  useEffect(() => {
    if (state?.phase !== PHASE.BACK) return;

    clearStoredStart();
    // A short beat so the recovery is visible. A hard reload, not router.refresh():
    // the RSC payload came from a server that has since rebooted.
    const timer = setTimeout(() => window.location.reload(), 900);
    return () => clearTimeout(timer);
  }, [state?.phase]);

  useEffect(() => {
    if (state?.phase === PHASE.GAVE_UP) clearStoredStart();
  }, [state?.phase]);

  const dismiss = useCallback(() => {
    clearStoredStart();
    setState(null);
  }, []);

  return (
    <ServerRestartContext.Provider value={{ start }}>
      {children}
      {state ? <RestartCurtain state={state} onDismiss={dismiss} /> : null}
    </ServerRestartContext.Provider>
  );
}

function Elapsed({ startedAt }) {
  const t = useTranslations("serverRestart");
  const [now, setNow] = useState(() => Date.now());

  // Ticks every second, independent of the probe interval, so it never looks frozen.
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  const total = Math.max(0, Math.floor((now - startedAt) / 1000));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;

  return (
    // Padding, not margin: as a margin it reads as part of the sentence above.
    <p className="pt-2 text-sm tabular-nums text-muted-foreground">
      {minutes > 0
        ? t("elapsedMinutes", { minutes, seconds })
        : t("elapsedSeconds", { seconds })}
    </p>
  );
}

function RestartCurtain({ state, onDismiss }) {
  const t = useTranslations("serverRestart");
  const cardRef = useRef(null);

  const gaveUp = state.phase === PHASE.GAVE_UP;
  const done = state.phase === PHASE.BACK;

  // Nothing behind this is usable, so focus belongs here.
  useEffect(() => {
    cardRef.current?.focus();
  }, []);

  let title;
  let body;

  if (gaveUp) {
    title = t("gaveUp.title");
    body = t("gaveUp.body");
  } else if (done) {
    title = t("back.title");
    body = t("back.body");
  } else if (isTakingLonger(state)) {
    title = t("offline.title");
    body = t("takingLonger.body");
  } else if (state.phase === PHASE.COMING_BACK) {
    title = t("comingBack.title");
    body = t("comingBack.body");
  } else if (state.phase === PHASE.OFFLINE) {
    title = t("offline.title");
    body = t("offline.body");
  } else {
    title = t("goingDown.title");
    body = t("goingDown.body");
  }

  const Icon = gaveUp ? Unplug : done ? RotateCcw : Loader2;

  return (
    // Opaque, not a scrim: visible controls would point at a server that is gone.
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-background p-4">
      <div
        ref={cardRef}
        tabIndex={-1}
        className="w-full max-w-md rounded-lg border bg-card p-6 text-card-foreground shadow-sm outline-none"
      >
        <div className="flex min-w-0 items-center gap-3">
          <span
            className={cn(
              "flex size-10 shrink-0 items-center justify-center rounded-full",
              gaveUp ? "bg-destructive/10 text-destructive" : "bg-warning/15 text-warning",
            )}
          >
            <Icon
              className={cn(
                "size-5",
                // Reduced motion drops the spin and keeps the icon.
                !gaveUp && !done && "animate-spin motion-reduce:animate-none",
              )}
            />
          </span>
          <h2 className="text-lg font-semibold">{title}</h2>
        </div>

        {/* Polite, not assertive: each phase change is said once without interrupting. */}
        <div role="status" aria-live="polite" aria-atomic="true" className="pt-3">
          <p className="text-sm text-muted-foreground">{body}</p>
          {!done && !gaveUp ? <Elapsed startedAt={state.startedAt} /> : null}
        </div>

        {gaveUp ? (
          <div className="flex flex-wrap justify-end gap-2 pt-5">
            <Button variant="outline" onClick={onDismiss}>
              {t("dismiss")}
            </Button>
            <Button autoFocus onClick={() => window.location.reload()}>
              {t("reconnect")}
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  );
}
