import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ChevronRight, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";

// No `flex-wrap`: the table's action column should widen, not stack. Cards pass their own layout.
const TABLE_ROW = "flex items-center justify-end gap-1";

export function DatabaseRowActions({
  database,
  onDelete,
  canManage,
  phpmyadminSites,
  className = TABLE_ROW,
}) {
  const t = useTranslations("databases");

  return (
    <div className={className}>
      {/* Spelled out: users, credentials and backups live on the detail page. */}
      <Button asChild variant="outline" size="sm">
        <Link href={`/databases/${database.id}`} prefetch={false}>
          {t("manage")}
          <ChevronRight className="size-3.5" />
        </Link>
      </Button>

      {/* On the row too: opening the database is what most visits are for. */}
      <PhpmyadminButton
        database={database}
        canManage={canManage}
        sites={phpmyadminSites}
        compact
      />

      {canManage && onDelete ? (
        <IconTooltip label={t("delete.action")}>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t("delete.forName", { name: database.name })}
            className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => onDelete(database)}
          >
            <Trash2 className="size-4" />
          </Button>
        </IconTooltip>
      ) : null}
    </div>
  );
}
