"use client";

import { useTranslations } from "next-intl";
import { ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { BACKUP_OUTCOME, outcomeOf } from "@/components/backups/status-meta";

/**
 * The status of one backup — icon + word + colour.
 *
 * Same shape as `ServiceStatusBadge` and `WorkerStatusBadge`, deliberately:
 * three features describing state three different ways is how a panel stops
 * feeling like one product. Colour alone is never the signal either — the one
 * state that matters most, `failed`, has to survive being read by someone who
 * cannot separate red from green, so it carries an icon and a word too.
 *
 * `status_title` comes from the API already translated; the local key is the
 * fallback for a status this build has not met.
 */
export function BackupStatusBadge({ backup }) {
  const t = useTranslations("backups.history");
  const meta = outcomeOf(BACKUP_OUTCOME, backup.status);
  const Icon = meta.icon;

  return (
    <Badge variant={meta.variant} className="gap-1.5 font-normal">
      <Icon className={meta.spin ? "size-3 animate-spin" : "size-3"} />
      {backup.status_title ??
        (t.has(`statuses.${backup.status}`) ? t(`statuses.${backup.status}`) : backup.status)}
    </Badge>
  );
}

/**
 * A safety copy — taken automatically just before a restore overwrote the site,
 * and exempt from retention.
 *
 * Its own badge rather than a grey pill among grey pills: this is the row
 * someone hunts for after putting the wrong thing live, and on that day it has
 * to be the one thing on screen that stands out.
 */
export function SafetyBadge() {
  const t = useTranslations("backups.history");

  return (
    <Badge variant="warning" className="gap-1.5 font-normal">
      <ShieldCheck className="size-3" />
      {t("safetyBadge")}
    </Badge>
  );
}
