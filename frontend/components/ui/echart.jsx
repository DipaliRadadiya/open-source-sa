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

// Register only what the charts use. Never switch to the bare `echarts` import.
echarts.use([
  LineChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  AriaComponent,
  // Capacity lines on the load chart. ECharts silently drops config for
  // unregistered components, so a missing entry fails with no warning.
  MarkLineComponent,
  // The peak marker on the database query chart.
  MarkPointComponent,
  CanvasRenderer,
]);

// Tokens are read from the CSS cascade, so globals.css stays the source and themes apply.
const NO_TOKENS = Object.freeze({});

/** One shared 1x1 context for every token on the page. */
let probe = null;
const SENTINEL = "#010203";

// Tokens resolve as `lab()`, which zrender cannot parse; convert by painting one pixel.
function toSrgb(value) {
  if (!value) return value;

  probe ??= document
    .createElement("canvas")
    .getContext("2d", { willReadFrequently: true });
  if (!probe) return value;

  probe.fillStyle = SENTINEL;
  probe.fillStyle = value;
  // An invalid colour is ignored, so a surviving sentinel means "not a colour".
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
  // The snapshot must keep its identity while values are unchanged, or
  // useSyncExternalStore re-renders forever.
  const cache = useRef({ key: null, signature: null, value: NO_TOKENS });

  // The theme toggle swaps a class on <html> without re-rendering this component.
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

// Always pass `dataTable`: a canvas is opaque to screen readers.
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

    // Covers a zero first size during layout and container changes window.resize misses.
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

    // notMerge: removed series must not linger from the previous draw.
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
