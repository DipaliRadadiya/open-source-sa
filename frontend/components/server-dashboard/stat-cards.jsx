"use client";

import { useTranslations, useFormatter } from "next-intl";
import { Cpu, MemoryStick, HardDrive, Activity, ArrowLeftRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { StatCard, pct } from "@/components/ui/stat-card";

export function StatCards({ metrics, stale = false, ratesReady = true }) {
  const t = useTranslations("serverDashboard");
  const format = useFormatter();
  const loading = !metrics;

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

  return (
    // 5 cards: 1 → 2 → 5. A 3-col step would strand a single card on its own row.
    // aria-live=polite: values refresh every 3s, so announce changes without
    // interrupting whatever the user is doing.
    <div
      aria-live="polite"
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
        // A rate needs two samples. Until the second one lands the API returns
        // 0, which would draw an idle machine we have no evidence for — so the
        // card says "not measured yet" instead of a number.
        value={ratesReady ? percentText(cpu?.percent, 1) : "—"}
        percent={ratesReady ? cpu?.percent : null}
        hint={cpu?.cores ? t("cores", { count: cpu.cores }) : ""}
        loading={loading}
      />
      <StatCard
        icon={MemoryStick}
        label={t("memory")}
        value={percentText(memory?.percent)}
        percent={memory?.percent}
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
      {/* Guarded like swap above: when the collector reports no filesystem at
          all, disk_total is 0 and the unguarded card rendered "0%" over an
          empty bar and "0 B of 0 B" — which reads as a healthy empty disk when
          the truth is that nothing was measured. */}
      <StatCard
        icon={HardDrive}
        label={t("disk")}
        value={Number(disk?.total) > 0 ? percentText(disk?.percent) : "—"}
        percent={Number(disk?.total) > 0 ? disk?.percent : null}
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
        // Load is only meaningful against core count: >= cores means saturated.
        percent={
          cores > 0 && Number.isFinite(Number(load?.[15]))
            ? (Number(load[15]) / cores) * 100
            : null
        }
        hint={cores ? t("ofCores", { count: cores }) : ""}
        sub={load ? `${t("loadHint")}: ${decimal(load[5])}` : ""}
        hasSub
        loading={loading}
      />
    </div>
  );
}
