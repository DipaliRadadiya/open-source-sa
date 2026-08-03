"use client";

import { useTranslations } from "next-intl";
import { HardDrive } from "lucide-react";
import { StatCard } from "@/components/ui/stat-card";

/**
 * How full the disk is, and how much of that this page can give back.
 *
 * The dashboard's stat card, not a lookalike — same component, so the value
 * size, the icon chip and the 75/90 colour thresholds match the disk figure on
 * the dashboard by construction rather than by my re-reading the numbers.
 *
 * Client-side because the icon is a component, and a component cannot cross the
 * server boundary as a prop.
 *
 * The reclaimable figure rides in the sub-line: it belongs to this page's job,
 * but it is a footnote to the percentage, not a competing headline.
 */
export function DiskSummary({ disk, reclaimableHuman }) {
  const t = useTranslations("diskCleaner");

  return (
    <StatCard
      icon={HardDrive}
      label={t("summary.label")}
      value={t("summary.percentUsed", { percent: Math.round(disk?.percent ?? 0) })}
      hint={t("summary.freeShort", { free: disk?.free_human ?? "—" })}
      percent={disk?.percent ?? 0}
      hasSub
      sub={t("summary.subLine", {
        used: disk?.used_human ?? "—",
        total: disk?.total_human ?? "—",
        reclaimable: reclaimableHuman ?? "0 B",
      })}
    />
  );
}
