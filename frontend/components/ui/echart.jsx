import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from "react";
import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import {
  AriaComponent,
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

/**
 * Only what the panel's charts actually draw is registered.
 *
 * `import * as echarts from "echarts"` pulls in every chart type the library
 * has — sankey, treemap, map, gauge, the lot — and none of it is tree-shaken,
 * because the convenience entry point registers them all as a side effect.
 * Registering by hand is the whole reason ECharts can be smaller than what it
 * replaces rather than larger. Add to this list when a chart needs it; never
 * switch to the bare `echarts` import to save a line.
 */
echarts.use([
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  AriaComponent,
  CanvasRenderer,
]);

/**
 * Resolve design tokens to the values the canvas needs.
 *
 * ECharts takes colours as JavaScript strings, but the house rule is that
 * tokens live in CSS and are never duplicated as literals in JS. So the values
 * are read back out of the cascade rather than restated here: one source of
 * truth in globals.css, and a theme switch cannot leave a chart behind.
 *
 * This replaces a worse workaround. The old Recharts version of the query
 * chart hard-coded three hsl() literals with a comment explaining that its SVG
 * strokes would not resolve the CSS variables in production builds — so the
 * chart's palette had already drifted out of the design system and could not
 * follow the dark theme at all.
 */
const NO_TOKENS = Object.freeze({});

function readTokens(names) {
  const styles = getComputedStyle(document.documentElement);
  const resolved = {};
  for (const name of names) {
    resolved[name] = styles.getPropertyValue(`--${name}`).trim();
  }

  return resolved;
}

export function useChartTokens(names) {
  const key = names.join("|");
  // The snapshot must keep its identity while the values are unchanged, or
  // useSyncExternalStore re-renders forever: getComputedStyle hands back a
  // fresh object every call.
  const cache = useRef({ key: null, signature: null, value: NO_TOKENS });

  // The theme toggle swaps a class on <html>. Nothing re-renders this
  // component when it does, so without watching for it a chart keeps the
  // palette it was born with while the page around it changes.
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

  // Nothing to read on the server: there is no cascade to resolve against.
  const getServerSnapshot = useCallback(() => NO_TOKENS, []);

  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}

/**
 * A chart canvas that owns its ECharts instance.
 *
 * Deliberately not `echarts-for-react`: this is ~60 lines of lifecycle, and
 * owning it means the registration list above stays under our control rather
 * than a wrapper's. Same ownership model as everything else in components/ui.
 *
 * `dataTable` is not optional in spirit. A canvas is a single opaque element
 * to a screen reader — the SVG it replaces at least exposed its points — so
 * every chart supplies the same numbers as a visually hidden table. ECharts'
 * own `aria` block is enabled alongside it for the generated description.
 */
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

    // The host is sized by CSS, so its first measured size can be zero if the
    // card is still laying out. Resizing on observation covers both that and
    // every later container change, which `window.resize` alone would miss.
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

    // notMerge: a series removed from the option must disappear rather than
    // linger from the previous draw.
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
