import Link from "@/components/ui/app-link";
import { useRef } from "react";
import { useTranslations } from "next-intl";
import { ChevronRight, MoreHorizontal, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";
import { AdminerButton } from "@/components/databases/adminer-button";
import { phpmyadminState, userCount } from "@/lib/databases/phpmyadmin-state";
import { adminerState } from "@/lib/databases/adminer-state";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

const TABLE_ROW = "flex items-center justify-end gap-2";

export function DatabaseRowActions({
  database,
  onDelete,
  canManage,
  phpmyadminSites = null,
  adminerSites = null,
  showManage = true,
  menu = false,
  className = TABLE_ROW,
}) {
  const t = useTranslations("databases");
  const openingDialog = useRef(false);
  const stateArgs = { engine: database.engine, driver: database.driver, users: userCount(database) };
  const hasClientActions =
    phpmyadminState({ ...stateArgs, installed: phpmyadminSites === null ? null : phpmyadminSites.length > 0 }) !== "hidden" ||
    adminerState({ ...stateArgs, installed: adminerSites === null ? null : adminerSites.length > 0 }) !== "hidden";

  const clients = (
    <>
      <PhpmyadminButton database={database} canManage={canManage} sites={phpmyadminSites} compact menuItem={menu} />
      <AdminerButton database={database} canManage={canManage} sites={adminerSites} compact menuItem={menu} />
    </>
  );

  return (
    <div className={className}>
      {showManage ? (
        <Button asChild variant="outline" size="sm">
          <Link href={`/databases/${database.id}`} prefetch={false}>
            {t("manage")}
            <ChevronRight className="size-3.5" />
          </Link>
        </Button>
      ) : null}

      {menu ? (
        canManage && (hasClientActions || onDelete) ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label={`${t("actions")}: ${database.name}`}>
                <MoreHorizontal className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
              align="end"
              onCloseAutoFocus={(event) => {
                if (!openingDialog.current) return;
                openingDialog.current = false;
                event.preventDefault();
              }}
            >
              {clients}
              {onDelete ? (
                <>
                  {hasClientActions ? <DropdownMenuSeparator /> : null}
                  <DropdownMenuItem
                    variant="destructive"
                    onSelect={() => {
                      openingDialog.current = true;
                      onDelete(database);
                    }}
                  >
                    <Trash2 className="size-4" />
                    {t("delete.action")}
                  </DropdownMenuItem>
                </>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null
      ) : clients}

      {!menu && canManage && onDelete ? (
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
