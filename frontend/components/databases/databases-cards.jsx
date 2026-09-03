import Link from "next/link";
import { useTranslations } from "next-intl";
import { ChevronRight } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { DatabaseRowActions } from "@/components/databases/database-row-actions";
import { userCount } from "@/lib/databases/phpmyadmin-state";

/**
 * The same databases as cards, for screens too narrow for seven columns.
 *
 * Measured before writing: at 390px the table is 1115px wide inside a 356px
 * scroller, so two thirds of every row sits off the right edge. It scrolls, and
 * the fade says so, but "Manage" is three swipes away and the whole point of
 * the list is choosing which database to open.
 *
 * Re-ranked for a narrow card rather than transposed. The name leads because it
 * is what you are choosing between. Then the two facts that are actually states
 * rather than numbers — no users means nothing can connect, never exported
 * means nothing to restore — because those are the ones worth noticing on a
 * phone. Size and created follow as plain figures, and the age of the last
 * export goes with them.
 *
 * Engine is shown on the same condition as the table's column: only when the
 * server has more than one, so it is a distinction rather than a label repeated
 * down the page.
 */
export function DatabasesCards({
  databases = [],
  canManage = false,
  showEngine = false,
  engineName,
  lastBackup = {},
  backupsUnknown = false,
  phpmyadminInstalled = null,
  onDelete,
}) {
  const t = useTranslations("databases");

  return (
    <CardList>
      {databases.map((database) => {
        const backup = lastBackup?.[database.id];
        // NOT `?? 0`: a missing count means we did not count, and rendering
        // the "no users" warning for it states a fact we do not have.
        const users = userCount(database);

        return (
          <CardListItem key={database.id}>
            <div className="min-w-0">
              <Link
                href={`/databases/${database.id}`}
                className="group flex items-center gap-1.5 font-mono text-sm font-medium text-primary underline-offset-4 hover:underline"
              >
                {/* Truncates rather than wrapping: generated names are long and
                    a three-line heading pushes every fact below the fold. The
                    full name is the first thing on the page it opens. */}
                <span className="truncate">{database.name}</span>
                <ChevronRight className="size-3.5 shrink-0 opacity-60" />
              </Link>

              {showEngine ? (
                <p className="mt-1 text-xs text-muted-foreground">{engineName(database.engine)}</p>
              ) : null}

              {/* The two states, before the two numbers. */}
              {users === 0 || (!backupsUnknown && !backup) ? (
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {users === 0 ? (
                    <Badge variant="warning" className="font-normal">
                      {t("columns.noUsers")}
                    </Badge>
                  ) : null}
                  {!backupsUnknown && !backup ? (
                    <Badge variant="warning" className="font-normal">
                      {t("columns.neverExported")}
                    </Badge>
                  ) : null}
                </div>
              ) : null}

              <dl className="mt-2 space-y-1 text-xs">
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">{t("columns.size")}</dt>
                  <dd className="tabular-nums">{database.size_human ?? "—"}</dd>
                </div>
                {users > 0 ? (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">{t("columns.users")}</dt>
                    <dd className="tabular-nums">{users}</dd>
                  </div>
                ) : null}
                {backup ? (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">{t("columns.lastExport")}</dt>
                    <dd className="truncate text-muted-foreground">
                      {backup.finished_at_human ?? backup.created_at_human ?? "—"}
                    </dd>
                  </div>
                ) : null}
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">{t("columns.created")}</dt>
                  <dd className="truncate text-muted-foreground">
                    {database.created_at_human ?? "—"}
                  </dd>
                </div>
              </dl>
            </div>

            {/* mt-auto: cards in a row stretch to the tallest, and an action row
                floating mid-card reads as unfinished. */}
            <div className="mt-auto border-t pt-3">
              {/* The card is the narrow case: three controls on one line do not
                  fit at 390px, so here wrapping is the point. */}
              <DatabaseRowActions
                className="flex flex-wrap items-center justify-end gap-2"
                database={database}
                onDelete={onDelete}
                canManage={canManage}
                phpmyadminInstalled={phpmyadminInstalled}
              />
            </div>
          </CardListItem>
        );
      })}
    </CardList>
  );
}
