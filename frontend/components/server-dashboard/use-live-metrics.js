"use client";

import { useEffect, useRef, useState } from "react";
import { getLiveMetrics } from "@/lib/api/server-metrics";

const POLL_MS = 3000;
const SLOW_POLL_MS = 15000;
// Back off only once the failures look like an outage, not a blip.
const FAILURES_BEFORE_BACKOFF = 3;
const MAX_POINTS = 40;

/**
 * Polls GET /server/metrics/live and keeps a short rolling window of network
 * samples for the streaming chart. Pauses while the tab is hidden and aborts
 * the in-flight request on unmount so a backgrounded tab costs nothing.
 * Timestamps stay raw — formatting is the caller's job, via next-intl.
 */
export function useLiveMetrics(initial = null) {
  const [metrics, setMetrics] = useState(initial);
  const [netSeries, setNetSeries] = useState([]);
  const [failed, setFailed] = useState(false);
  // `cpu.percent`, `network` and `disk_io` are rates measured against the
  // PREVIOUS poll, so the first sample after any gap comes back as 0 — there is
  // nothing to measure against yet. That 0 is "not measured", not "idle", and
  // rendering it as a number would draw a quiet server that isn't there.
  const [ratesReady, setRatesReady] = useState(false);
  const [updatedAt, setUpdatedAt] = useState(null);
  const controllerRef = useRef(null);

  useEffect(() => {
    let timer;
    let active = true;
    let failures = 0;
    let intervalMs = POLL_MS;
    // Every gap needs a fresh baseline: mount, a hidden tab coming back, and a
    // recovered outage all leave the server with nothing to compare against.
    let needsBaseline = true;

    function schedule() {
      clearInterval(timer);
      timer = setInterval(tick, intervalMs);
    }

    // A sustained outage drops the poll to SLOW_POLL_MS instead of hammering a
    // dead endpoint every 3s for as long as the tab stays open.
    function applyBackoff(next) {
      const target = next >= FAILURES_BEFORE_BACKOFF ? SLOW_POLL_MS : POLL_MS;
      if (target !== intervalMs) {
        intervalMs = target;
        schedule();
      }
    }

    async function tick() {
      // A hidden tab stops polling, so the next sample spans the whole time it
      // was away — that reading is not a rate for the interval anyone watched.
      if (document.hidden) {
        needsBaseline = true;
        return;
      }
      controllerRef.current?.abort();
      const controller = new AbortController();
      controllerRef.current = controller;
      try {
        const data = await getLiveMetrics(controller.signal);
        if (!active || !data) return;
        setMetrics(data);
        setFailed(false);
        setUpdatedAt(new Date());
        failures = 0;
        applyBackoff(failures);

        if (needsBaseline) {
          // This sample only establishes the baseline. Absolute readings
          // (memory, disk, load) are real and shown; the rates are not, and the
          // chart gets no point rather than a false zero.
          needsBaseline = false;
          setRatesReady(false);
          return;
        }

        setRatesReady(true);
        setNetSeries((prev) =>
          [
            ...prev,
            {
              t: Date.now(),
              in: Number(data.network?.in ?? 0),
              out: Number(data.network?.out ?? 0),
            },
          ].slice(-MAX_POINTS),
        );
      } catch (error) {
        if (active && error?.name !== "CanceledError" && error?.code !== "ERR_CANCELED") {
          setFailed(true);
          failures += 1;
          // The next success is measuring across the outage, not across a tick.
          needsBaseline = true;
          setRatesReady(false);
          applyBackoff(failures);
        }
      }
    }

    tick();
    schedule();
    document.addEventListener("visibilitychange", tick);

    return () => {
      active = false;
      clearInterval(timer);
      document.removeEventListener("visibilitychange", tick);
      controllerRef.current?.abort();
    };
  }, []);

  return { metrics, netSeries, failed, updatedAt, ratesReady };
}
