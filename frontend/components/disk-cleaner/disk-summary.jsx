"use client";

import { useTranslations } from "next-intl";
import { HardDrive } from "lucide-react";
import { StatCard } from "@/components/ui/stat-card";

/**
 * How full the disk is and how much this page can reclaim. Uses the
 * dashboard's StatCard so thresholds and styling match it. Client-side because
 * the icon component cannot cross the server boundary as a prop.
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
