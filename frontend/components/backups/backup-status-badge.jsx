import { useTranslations } from "next-intl";
import { ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { BACKUP_OUTCOME, outcomeOf } from "@/components/backups/status-meta";

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

export function SafetyBadge() {
  const t = useTranslations("backups.history");

  return (
    <Badge variant="warning" className="gap-1.5 font-normal">
      <ShieldCheck className="size-3" />
      {t("safetyBadge")}
    </Badge>
  );
}
