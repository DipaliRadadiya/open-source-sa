import { useTranslations } from "next-intl";
import { ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { BACKUP_OUTCOME, outcomeOf } from "@/components/backups/status-meta";

/**
 * The status of one backup: icon + word + colour, never colour alone. Same
 * shape as `ServiceStatusBadge` and `WorkerStatusBadge`. `status_title` comes
 * translated from the API; the local key is the fallback for unknown statuses.
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
 * A safety copy, taken automatically before a restore overwrote the site and
 * exempt from retention. Its own badge so it stands out.
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
