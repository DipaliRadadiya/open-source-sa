"use client";

import { useTranslations } from "next-intl";
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
  // Priced for the Clean panel's current choice, so the tile and the list never disagree.
  const [keep] = useKeepChoice();
  const { bytes, count: reclaimableCount } = reclaimableFor(categories, keep);
  const reclaimableHuman = humanBytes(bytes);

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
        value={reclaimableHuman}
        detail={t("summary.reclaimCount", { count: reclaimableCount })}
        tone={reclaimableCount > 0 ? "success" : "neutral"}
      />
    </>
  );
}

// Only this total is formatted locally: the API never sends the sum.
function humanBytes(bytes) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}
