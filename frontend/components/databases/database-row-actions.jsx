import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ChevronRight, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";
import { AdminerButton } from "@/components/databases/adminer-button";

// No `flex-wrap`: the table's action column should widen, not stack. Cards pass their own layout.
// gap-2: at gap-1 Manage, phpMyAdmin and Adminer ran together (Krishna, 15 Oct).
const TABLE_ROW = "flex items-center justify-end gap-2";

export function DatabaseRowActions({
  database,
  onDelete,
  canManage,
  phpmyadminSites,
  adminerSites = null,
  // The table drops Manage: its name cell already links to the same page, and with two
  // database clients the row no longer fitted (Krishna, 15 Oct).
  showManage = true,
  className = TABLE_ROW,
}) {
  const t = useTranslations("databases");

  return (
    <div className={className}>
      {/* Spelled out: users, credentials and backups live on the detail page. */}
      {showManage ? (
        <Button asChild variant="outline" size="sm">
          <Link href={`/databases/${database.id}`} prefetch={false}>
            {t("manage")}
            <ChevronRight className="size-3.5" />
          </Link>
        </Button>
      ) : null}

      {/* On the row too: opening the database is what most visits are for. */}
      <PhpmyadminButton
        database={database}
        canManage={canManage}
        sites={phpmyadminSites}
        compact
      />
      <AdminerButton
        database={database}
        canManage={canManage}
        sites={adminerSites}
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
