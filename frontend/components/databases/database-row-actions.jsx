import Link from "next/link";
import { useTranslations } from "next-intl";
import { ChevronRight, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";

/**
 * What you can do to one database, wherever the row is drawn.
 *
 * Shared because it was not: the table had Manage, phpMyAdmin and Delete, while
 * the cards below `lg` had only the first two — the card was never even passed
 * an `onDelete`. Deleting a database was therefore a desktop-only action, and
 * nothing said so; the same account simply lost it by making the window narrow.
 *
 * Every other list in the panel already does this — roles, users, applications,
 * workers, cron jobs, system users and files each have one row-actions
 * component that both views render. Databases was one of the two that did not.
 */
export function DatabaseRowActions({ database, onDelete, canManage, phpmyadminInstalled }) {
  const t = useTranslations("databases");

  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {/* Spelled out, not an icon. Users, credentials and backups all live on
          the detail page, and nothing on this row said so — people could not
          find them without being told the name was clickable. */}
      <Button asChild variant="outline" size="sm">
        <Link href={`/databases/${database.id}`} prefetch={false}>
          {t("manage")}
          <ChevronRight className="size-3.5" />
        </Link>
      </Button>

      {/* On the row, not only on the detail page: opening the database is
          what most visits to this list are for, and making it cost a page
          load first is the difference between a shortcut and a detour. */}
      <PhpmyadminButton
        database={database}
        canManage={canManage}
        installed={phpmyadminInstalled}
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
