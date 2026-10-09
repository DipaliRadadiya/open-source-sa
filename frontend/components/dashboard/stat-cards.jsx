import { useTranslations, useFormatter } from "next-intl";
import { cn } from "@/lib/utils";
import { pct, usageStatus } from "@/lib/metrics/usage-level";
import { UsageRing } from "@/components/ui/usage-ring";
import { Skeleton } from "@/components/ui/skeleton";
import { formatBytes } from "@/lib/format/bytes";

const STATUS_TEXT = {
  normal: "text-success",
  watch: "text-warning",
  high: "text-destructive",
  off: "text-muted-foreground",
  unknown: "text-muted-foreground",
};

// One reading: the ring carries the number, the words say whether it matters.
// No "… free" line: "1.9 GB of 5.8 GB" already says it, and five across has no room.
// The label wraps rather than truncates; five across clipped most locales once.
function UsageTile({ label, value, ringValue, hint, sub, percent, loading, status = null }) {
  return (
    <div className="@container/tile min-w-0 rounded-xl bg-muted/40 p-3 ring-1 ring-border/70">
      <div className="flex min-w-0 items-center gap-3 @max-[11rem]/tile:flex-col @max-[11rem]/tile:items-start">
        <UsageRing percent={loading ? null : percent} label={label}>
          {loading ? <Skeleton className="h-3.5 w-8" /> : (ringValue ?? value)}
        </UsageRing>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium break-words">{label}</p>
          {loading ? (
            <Skeleton className="mt-1.5 h-3.5 w-24" />
          ) : (
            <>
              {hint ? <p className="text-xs break-words tabular-nums text-muted-foreground">{keepUnits(hint)}</p> : null}
              {status || sub ? (
                <p className="mt-1 text-xs break-words tabular-nums">
                  {status ? <span className={cn("font-medium", STATUS_TEXT[status.key])}>{status.label}</span> : null}
                  {status && sub ? <span className="text-muted-foreground"> · </span> : null}
                  {sub ? <span className="text-muted-foreground">{keepUnits(sub)}</span> : null}
                </p>
              ) : null}
            </>
          )}
        </div>
      </div>
    </div>
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
        "grid gap-3 transition-opacity sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5",
        // Polling is failing: the numbers are last-known, not current.
        stale && "opacity-60",
      )}
    >
      <UsageTile
        label={t("cpu")}
        // A rate needs two samples and the API returns 0 until then; a dash means "not applicable".
        value={ratesReady ? percentText(cpu?.percent, 1) : t("measuring")}
        // The ring cannot hold "Measuring…"; the hint says it instead.
        ringValue={ratesReady ? percentText(cpu?.percent, 0) : "…"}
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
