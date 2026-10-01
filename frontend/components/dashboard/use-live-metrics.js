import { useEffect, useRef, useState } from "react";
import { getLiveMetrics } from "@/lib/api/server-metrics";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 3000;
const SLOW_POLL_MS = 15000;
// Back off only once the failures look like an outage, not a blip.
const FAILURES_BEFORE_BACKOFF = 3;
// 100 points x 3s = a five-minute window, long enough to read a trend.
const MAX_POINTS = 100;

/**
 * Polls GET /server/metrics/live and keeps one rolling window that feeds every
 * chart on the dashboard. Pauses while the tab is hidden and aborts the
 * in-flight request on unmount. Timestamps stay raw; callers format them.
 */
export function useLiveMetrics(initial = null) {
  const [metrics, setMetrics] = useState(initial);
  const [series, setSeries] = useState([]);
  const [failed, setFailed] = useState(false);
  // The API's own reason the poll is failing (e.g. "Metrics collector is not running.").
  const [reason, setReason] = useState(null);
  // `cpu.percent`, `network` and `disk_io` are rates against the PREVIOUS poll,
  // so the first sample after any gap is 0, meaning "not measured", not "idle".
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
      // A hidden tab stops polling; the next sample spans the absence, so it
      // needs a new baseline.
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
        setReason(null);
        setUpdatedAt(new Date());
        failures = 0;
        applyBackoff(failures);

        if (needsBaseline) {
          // Baseline sample: absolute readings (memory, disk, load) are shown;
          // rates are not, so the chart gets no point rather than a false zero.
          needsBaseline = false;
          setRatesReady(false);
          // A valueless point makes the line break across the gap instead of
          // drawing a straight segment over time nobody observed.
          setSeries((prev) =>
            prev.length ? [...prev, { t: Date.now() }].slice(-MAX_POINTS) : prev,
          );
          return;
        }

        setRatesReady(true);
        setSeries((prev) =>
          [
            ...prev,
            {
              t: Date.now(),
              net_in: Number(data.network?.in ?? 0),
              net_out: Number(data.network?.out ?? 0),
              disk_read: Number(data.disk_io?.read ?? 0),
              disk_write: Number(data.disk_io?.write ?? 0),
              cpu: Number(data.cpu?.percent ?? 0),
              memory: Number(data.memory?.percent ?? 0),
              // Only real when the collector reports a filesystem at all;
              // a 0 % here means "unknown", not "empty disk".
              disk: data.disk?.total ? Number(data.disk.percent ?? 0) : null,
              load_5: Number(data.load?.[5] ?? 0),
              load_15: Number(data.load?.[15] ?? 0),
            },
          ].slice(-MAX_POINTS),
        );
      } catch (error) {
        if (active && error?.name !== "CanceledError" && error?.code !== "ERR_CANCELED") {
          setFailed(true);
          setReason(apiMessage(error, null));
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

  return { metrics, series, failed, reason, updatedAt, ratesReady };
}
