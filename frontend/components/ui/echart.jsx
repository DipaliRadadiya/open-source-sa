import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from "react";
import * as echarts from "echarts/core";
import { LineChart, PieChart } from "echarts/charts";
import {
  AriaComponent,
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  MarkPointComponent,
  TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

// Register only what the charts use. Don't switch to the bare echarts import.
echarts.use([
  LineChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  AriaComponent,
  MarkLineComponent,
  MarkPointComponent,
  CanvasRenderer,
]);

const NO_TOKENS = Object.freeze({});

let probe = null;
const SENTINEL = "#010203";

// Colours resolve as lab(), which zrender can't parse, so convert by painting one pixel.
function toSrgb(value) {
  if (!value) return value;

  probe ??= document
    .createElement("canvas")
    .getContext("2d", { willReadFrequently: true });
  if (!probe) return value;

  probe.fillStyle = SENTINEL;
  probe.fillStyle = value;
  if (probe.fillStyle === SENTINEL) return value;

  probe.clearRect(0, 0, 1, 1);
  probe.fillRect(0, 0, 1, 1);
  const [r, g, b, a] = probe.getImageData(0, 0, 1, 1).data;

  return a === 255
    ? `rgb(${r}, ${g}, ${b})`
    : `rgba(${r}, ${g}, ${b}, ${Number((a / 255).toFixed(3))})`;
}

function readTokens(names) {
  const styles = getComputedStyle(document.documentElement);
  const resolved = {};
  for (const name of names) {
    resolved[name] = toSrgb(styles.getPropertyValue(`--${name}`).trim());
  }

  return resolved;
}

export function useChartTokens(names) {
  const key = names.join("|");
  // Keep the snapshot's identity while values are unchanged, or useSyncExternalStore loops.
  const cache = useRef({ key: null, signature: null, value: NO_TOKENS });

  const subscribe = useCallback((notify) => {
    const observer = new MutationObserver(notify);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["class", "style"],
    });

    return () => observer.disconnect();
  }, []);

  const getSnapshot = useCallback(() => {
    const fresh = readTokens(key.split("|"));
    const signature = JSON.stringify(fresh);
    if (cache.current.key !== key || cache.current.signature !== signature) {
      cache.current = { key, signature, value: fresh };
    }

    return cache.current.value;
  }, [key]);

  const getServerSnapshot = useCallback(() => NO_TOKENS, []);

  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}

export function EChart({
  option,
  className,
  height = "h-72",
  loading = false,
  dataTable = null,
}) {
  const hostRef = useRef(null);
  const chartRef = useRef(null);
  const [painted, setPainted] = useState(false);

  useEffect(() => {
    const host = hostRef.current;
    if (!host) return;

    const chart = echarts.init(host, null, { renderer: "canvas" });
    chartRef.current = chart;

    const observer = new ResizeObserver(() => chart.resize());
    observer.observe(host);

    return () => {
      observer.disconnect();
      chart.dispose();
      chartRef.current = null;
    };
  }, []);

  useEffect(() => {
    const chart = chartRef.current;
    if (!chart || !option) return;

    // notMerge so removed series don't linger.
    chart.setOption(option, { notMerge: true });
    setPainted(true);
  }, [option]);

  const ready = painted && !loading;

  return (
    <div className={cn("relative w-full", height, className)} aria-busy={!ready}>
      {!ready ? (
        <Skeleton
          className="absolute inset-0 z-10 h-full w-full rounded-lg"
          aria-hidden="true"
        />
      ) : null}

      <div
        ref={hostRef}
        role="img"
        aria-label={dataTable?.caption}
        className={cn("h-full w-full", !ready && "invisible")}
      />

      {dataTable ? (
        <table className="sr-only">
          <caption>{dataTable.caption}</caption>
          <thead>
            <tr>
              {dataTable.columns.map((column) => (
                <th key={column} scope="col">
                  {column}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {dataTable.rows.map((row, index) => (
              <tr key={index}>
                {row.map((cell, cellIndex) => (
                  <td key={cellIndex}>{cell}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
    </div>
  );
}
