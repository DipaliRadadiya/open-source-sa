import { useTranslations, useFormatter } from "next-intl";
import { cn } from "@/lib/utils";
import { pct, usageStatus } from "@/lib/metrics/usage-level";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { formatBytes } from "@/lib/format/bytes";

// Steel UI (Krishna's prototype, 14 Oct): the level is a square chip and the bar
// takes the level's colour; "off"/"unknown" stay grey.
const STATUS_VARIANT = {
  normal: "success",
  watch: "warning",
  high: "destructive",
  off: "muted",
  unknown: "muted",
};
const BAR_FILL = {
  normal: "bg-success",
  watch: "bg-warning",
  high: "bg-destructive",
};

// One reading per cell: label and level chip on top, the number large, a thin bar,
// then the detail in mono. No "… free" line: "1.9 GB of 5.8 GB" already says it.
// The label wraps rather than truncates; five across clipped most locales once.
function UsageTile({ label, value, ringValue, hint, sub, percent, loading, status = null }) {
  const width = Math.max(0, Math.min(100, Number(percent) || 0));
  return (
    <div className="min-w-0 bg-card p-4 shadow-[inset_-1px_0_0_var(--border),inset_0_-1px_0_var(--border)]">
      <div className="flex min-w-0 flex-wrap items-start justify-between gap-2">
        <p className="font-mono text-xs font-medium tracking-[0.1em] break-words text-muted-foreground uppercase">{label}</p>
        {status && !loading ? (
          <Badge variant={STATUS_VARIANT[status.key] ?? "muted"}>
            <span aria-hidden className="size-1.5 bg-current" />
            {status.label}
          </Badge>
        ) : null}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-[22px] w-14" />
      ) : (
        <p className="mt-2 text-[22px] leading-none font-semibold tracking-tight tabular-nums"><BigValue text={ringValue ?? value} /></p>
      )}
      {/* Segmented, as in the prototype: the track and the fill share one mask, so
          their segments line up. */}
      <div className={cn("mt-3 h-1 bg-muted", SEGMENTS)} aria-hidden>
        {status && BAR_FILL[status.key] && !loading ? (
          <div className={cn("h-full transition-[width] duration-500", BAR_FILL[status.key], SEGMENTS)} style={{ width: `${width}%` }} />
        ) : null}
      </div>
      {loading ? (
        <Skeleton className="mt-2.5 h-3.5 w-24" />
      ) : hint || sub ? (
        <p className="mt-2.5 font-mono text-xs break-words tabular-nums text-muted-foreground">
          {hint ? keepUnits(hint) : null}
          {hint && sub ? " · " : null}
          {sub ? keepUnits(sub) : null}
        </p>
      ) : null}
    </div>
  );
}

const SEGMENTS = "[mask-image:repeating-linear-gradient(90deg,#000_0_6px,transparent_6px_8px)]";

// "2%" / "2 %" (fr, de): the unit at a smaller size beside the number, as in the
// prototype. Anything without a trailing % (load, a dash) prints as is.
function BigValue({ text }) {
  const match = typeof text === "string" ? /^(.*?\d)(\s?%)$/u.exec(text) : null;
  if (!match) return text;
  return (
    <>
      {match[1]}
      <span className="ms-0.5 text-sm font-medium text-muted-foreground">%</span>
    </>
  );
}

// "3.8 GB" wraps as a unit, never as "3.8 / GB".
function keepUnits(value) {
  return typeof value === "string" ? value.replace(/(\d)\s+(?=[A-Za-z%])/g, "$1\u00A0") : value;
}

export function StatCards({ metrics, stale = false, ratesReady = true }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const loading = !metrics;
  // The API's *_human strings are English-formatted ("1,024 MB" reads as about 1 MB in German).
  const size = (value, human) => formatBytes(value, format) ?? human;

  // Same thresholds that colour the bar. `fallback` covers a real state with no
  // percentage (no swap: Off; unreadable disk: Unknown).
  const statusFor = (percent, fallback = null) => {
    const key = usageStatus(percent) ?? fallback;
    return key ? { key, label: t(`status.${key}`) } : null;
  };

  // Locale-aware numbers: hi/es use different grouping and decimal marks.
  const percentText = (value, decimals = 0) =>
    format.number(pct(value) / 100, {
      style: "percent",
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  const decimal = (value) =>
    Number.isFinite(Number(value))
      ? format.number(Number(value), {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        })
      : "—";

  const cpu = metrics?.cpu;
  const memory = metrics?.memory;
  const swap = metrics?.swap;
  const disk = metrics?.disk;
  const load = metrics?.load;
  const cores = Number(cpu?.cores) || 0;
  // Load is only meaningful against core count: >= cores means saturated. Held
  // in one place so the bar and the level word cannot be computed differently.
  const loadPercent =
    cores > 0 && Number.isFinite(Number(load?.[15]))
      ? (Number(load[15]) / cores) * 100
      : null;

  return (
    // 1 → 2 → 3 → 5. NOT a live region: values change every 3s.
    <div
      aria-busy={loading}
      className={cn(
        // Cells share hairlines: each draws its right and bottom edge inside itself, and
        // the -1px margins tuck the outermost ones under the card's clipped edge.
        "-mr-px -mb-px grid transition-opacity sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5",
        // Polling is failing: the numbers are last-known, not current.
        stale && "opacity-60",
      )}
    >
      <UsageTile
        label={t("cpu")}
        // A rate needs two samples and the API returns 0 until then; a dash means "not applicable".
        value={ratesReady ? percentText(cpu?.percent, 1) : t("measuring")}
        // The ring cannot hold "Measuring…"; the hint says it instead.
        ringValue={ratesReady ? percentText(cpu?.percent, 0) : "—"}
        percent={ratesReady ? cpu?.percent : null}
        // No level word until the second sample lands.
        status={ratesReady ? statusFor(cpu?.percent) : null}
        hint={ratesReady ? (cpu?.cores ? t("cores", { count: cpu.cores }) : "") : t("measuring")}
        loading={loading}
      />
      <UsageTile
        label={t("memory")}
        value={percentText(memory?.percent)}
        percent={memory?.percent}
        status={statusFor(memory?.percent)}
        hint={
          memory?.total_human
            ? t("usedOf", { used: size(memory.used, memory.used_human), total: size(memory.total, memory.total_human) })
            : ""
        }
        loading={loading}
      />
      <UsageTile
        label={t("swap")}
        value={
          Number(swap?.total) > 0 ? percentText(swap?.percent) : "—"
        }
        percent={Number(swap?.total) > 0 ? swap?.percent : null}
        // "Off" is a fact (many servers run without swap), not a missing reading.
        status={Number(swap?.total) > 0 ? statusFor(swap?.percent) : statusFor(null, "off")}
        hint={
          Number(swap?.total) > 0 && swap?.used_human && swap?.total_human
            ? t("usedOf", { used: size(swap.used, swap.used_human), total: size(swap.total, swap.total_human) })
            : t("swapOff")
        }
        loading={loading}
      />
      {/* Guarded like swap: with no filesystem reported, disk_total is 0 and
          "0%" would look like a healthy empty disk. */}
      <UsageTile
        label={t("disk")}
        value={Number(disk?.total) > 0 ? percentText(disk?.percent) : "—"}
        percent={Number(disk?.total) > 0 ? disk?.percent : null}
        // Unknown, NOT "Off": a disk reporting 0 total was not measured.
        status={Number(disk?.total) > 0 ? statusFor(disk?.percent) : statusFor(null, "unknown")}
        hint={
          Number(disk?.total) > 0 && disk?.total_human
            ? t("usedOf", { used: size(disk.used, disk.used_human), total: size(disk.total, disk.total_human) })
            : t("diskUnknown")
        }
        loading={loading}
      />
      <UsageTile
        label={t("load")}
        // The headline deliberately favours the stable 15-minute average over
        // the noisier 1-minute figure.
        value={decimal(load?.[15])}
        percent={loadPercent}
        status={statusFor(loadPercent)}
        hint={cores ? t("ofCores", { count: cores }) : ""}
        sub={load ? `${t("loadHint")}: ${decimal(load[5])}` : ""}
        loading={loading}
      />
    </div>
  );
}
