import { useTranslations, useFormatter } from "next-intl";
import { Cpu, MemoryStick, HardDrive, Activity, ArrowLeftRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { pct, usageStatus } from "@/lib/metrics/usage-level";
import { StatCard } from "@/components/ui/stat-card";

export function StatCards({ metrics, stale = false, ratesReady = true }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const loading = !metrics;

  /*
   * The level word, from the same thresholds that colour the bar. `fallback`
   * covers cards with a real state but no percentage (no swap: Off; unreadable
   * disk: Unknown).
   */
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
    // 5 cards: 1 → 2 → 5. A 3-col step would strand a single card on its own row.
    // Deliberately NOT a live region: values change every 3s; connection
    // changes are announced once by the status above.
    <div
      aria-busy={loading}
      className={cn(
        "grid gap-4 transition-opacity sm:grid-cols-2 xl:grid-cols-5",
        // Polling is failing: the numbers are last-known, not current.
        stale && "opacity-60",
      )}
    >
      <StatCard
        icon={Cpu}
        label={t("cpu")}
        /*
         * A rate needs two samples; until then the API returns 0, so show
         * "Measuring…" (a dash means "not applicable" on the other cards).
         */
        value={ratesReady ? percentText(cpu?.percent, 1) : t("measuring")}
        percent={ratesReady ? cpu?.percent : null}
        // No level word until the second sample lands.
        status={ratesReady ? statusFor(cpu?.percent) : null}
        hint={cpu?.cores ? t("cores", { count: cpu.cores }) : ""}
        loading={loading}
      />
      <StatCard
        icon={MemoryStick}
        label={t("memory")}
        value={percentText(memory?.percent)}
        percent={memory?.percent}
        status={statusFor(memory?.percent)}
        hint={
          memory?.total_human
            ? t("usedOf", { used: memory.used_human, total: memory.total_human })
            : ""
        }
        sub={memory?.free_human ? t("free", { free: memory.free_human }) : ""}
        hasSub
        loading={loading}
      />
      <StatCard
        icon={ArrowLeftRight}
        label={t("swap")}
        value={
          Number(swap?.total) > 0 ? percentText(swap?.percent) : "—"
        }
        percent={Number(swap?.total) > 0 ? swap?.percent : null}
        // "Off" is a fact (many servers run without swap), not a missing reading.
        status={Number(swap?.total) > 0 ? statusFor(swap?.percent) : statusFor(null, "off")}
        hint={
          Number(swap?.total) > 0 && swap?.used_human && swap?.total_human
            ? t("usedOf", { used: swap.used_human, total: swap.total_human })
            : t("swapOff")
        }
        sub={
          Number(swap?.total) > 0 && swap?.free_human
            ? t("free", { free: swap.free_human })
            : ""
        }
        hasSub
        loading={loading}
      />
      {/* Guarded like swap: with no filesystem reported, disk_total is 0 and
          "0%" would look like a healthy empty disk. */}
      <StatCard
        icon={HardDrive}
        label={t("disk")}
        value={Number(disk?.total) > 0 ? percentText(disk?.percent) : "—"}
        percent={Number(disk?.total) > 0 ? disk?.percent : null}
        // Unknown, NOT "Off": a disk reporting 0 total was not measured.
        status={Number(disk?.total) > 0 ? statusFor(disk?.percent) : statusFor(null, "unknown")}
        hint={
          Number(disk?.total) > 0 && disk?.total_human
            ? t("usedOf", { used: disk.used_human, total: disk.total_human })
            : t("diskUnknown")
        }
        sub={
          Number(disk?.total) > 0 && disk?.free_human
            ? t("free", { free: disk.free_human })
            : ""
        }
        hasSub
        loading={loading}
      />
      <StatCard
        icon={Activity}
        label={t("load")}
        // The headline deliberately favours the stable 15-minute average over
        // the noisier 1-minute figure.
        value={decimal(load?.[15])}
        percent={loadPercent}
        status={statusFor(loadPercent)}
        hint={cores ? t("ofCores", { count: cores }) : ""}
        sub={load ? `${t("loadHint")}: ${decimal(load[5])}` : ""}
        hasSub
        loading={loading}
      />
    </div>
  );
}
