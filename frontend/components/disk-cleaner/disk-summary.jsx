"use client";

import { useTranslations } from "next-intl";
import { HardDrive, Sparkles } from "lucide-react";
import { usageStatus } from "@/lib/metrics/usage-level";
import { Tile } from "@/components/applications/app-status-tiles";

// Client-side because the icon component cannot cross the server boundary as a prop.
// Compact tiles, as on Services: the stacked cards left most of their width empty (Krishna, 13 Oct).
const TONE = { normal: "info", watch: "warning", high: "destructive" };

export function DiskSummary({ disk, reclaimableHuman, reclaimableCount }) {
  const t = useTranslations("diskCleaner");

  return (
    <>
      <Tile
        compact
        icon={HardDrive}
        label={t("summary.label")}
        value={t("summary.percentUsed", { percent: Math.round(disk?.percent ?? 0) })}
        detail={t("summary.usedLine", {
          used: disk?.used_human ?? "—",
          total: disk?.total_human ?? "—",
          free: disk?.free_human ?? "—",
        })}
        tone={TONE[usageStatus(disk?.percent)] ?? "neutral"}
      />
      <Tile
        compact
        icon={Sparkles}
        label={t("summary.reclaimLabel")}
        value={reclaimableHuman ?? "0 B"}
        detail={t("summary.reclaimCount", { count: reclaimableCount })}
        tone={reclaimableCount > 0 ? "success" : "neutral"}
      />
    </>
  );
}
