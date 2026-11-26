import { useEffect, useRef, useState } from "react";
import { getLiveMetrics } from "@/lib/api/server-metrics";
import { apiMessage } from "@/lib/api/error-message";

const POLL_MS = 3000;
const SLOW_POLL_MS = 15000;
const FAILURES_BEFORE_BACKOFF = 3;
const MAX_POINTS = 100;

export function useLiveMetrics(initial = null) {
  const [metrics, setMetrics] = useState(initial);
  const [series, setSeries] = useState([]);
  const [failed, setFailed] = useState(false);
  const [reason, setReason] = useState(null);
  const [ratesReady, setRatesReady] = useState(false);
  const [updatedAt, setUpdatedAt] = useState(null);
  const controllerRef = useRef(null);

  useEffect(() => {
    let timer;
    let active = true;
    let failures = 0;
    let intervalMs = POLL_MS;
    let needsBaseline = true;

    function schedule() {
      clearInterval(timer);
      timer = setInterval(tick, intervalMs);
    }

    function applyBackoff(next) {
      const target = next >= FAILURES_BEFORE_BACKOFF ? SLOW_POLL_MS : POLL_MS;
      if (target !== intervalMs) {
        intervalMs = target;
        schedule();
      }
    }

    async function tick() {
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
          needsBaseline = false;
          setRatesReady(false);
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
