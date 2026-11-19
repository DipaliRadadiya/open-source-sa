"use client";

import { useFormatter, useTranslations } from "next-intl";
import { formatBytes, localizeSizeText } from "@/lib/format/bytes";
import { HardDrive, Sparkles } from "lucide-react";
import { usageStatus } from "@/lib/metrics/usage-level";
import { Tile } from "@/components/applications/app-status-tiles";
import { reclaimableFor } from "@/lib/disk-cleaner/keep-days";
import { useKeepChoice } from "@/components/disk-cleaner/keep-context";

// Client-side because the icon component cannot cross the server boundary as a prop.
// Compact tiles, as on Services: the stacked cards left most of their width empty (Krishna, 13 Oct).
const TONE = { normal: "info", watch: "warning", high: "destructive" };

export function DiskSummary({ disk, categories }) {
  const t = useTranslations("diskCleaner");
  const format = useFormatter();
  // Priced for the Clean panel's current choice, so the tile and the list never disagree.
  const [keep] = useKeepChoice();
  const { bytes, count: reclaimableCount } = reclaimableFor(categories, keep);
  const reclaimableHuman = formatBytes(bytes || 0, format);

  return (
    <>
      <Tile
        compact
        icon={HardDrive}
        label={t("summary.label")}
        value={t("summary.percentUsed", { percent: Math.round(disk?.percent ?? 0) })}
        detail={t("summary.usedLine", {
          used: localizeSizeText(disk?.used_human, format) ?? "—",
          total: localizeSizeText(disk?.total_human, format) ?? "—",
          free: localizeSizeText(disk?.free_human, format) ?? "—",
        })}
        tone={TONE[usageStatus(disk?.percent)] ?? "neutral"}
      />
      <Tile
        compact
        icon={Sparkles}
        label={t("summary.reclaimLabel")}
        value={reclaimableHuman}
        detail={t("summary.reclaimCount", { count: reclaimableCount })}
        tone={reclaimableCount > 0 ? "success" : "neutral"}
      />
    </>
  );
}

