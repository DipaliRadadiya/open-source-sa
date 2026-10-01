import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { applicationById } from "@/lib/backups/database-availability";
import { ChevronRight } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CardList, CardListItem } from "@/components/data-table/card-list";
import { DatabaseRowActions } from "@/components/databases/database-row-actions";
import { userCount } from "@/lib/databases/phpmyadmin-state";

/**
 * The same databases as cards, for screens too narrow for the table. The name
 * leads, then the states worth noticing (no users, never exported, no site),
 * then plain figures. Engine shows only when the server has more than one.
 */
export function DatabasesCards({
  databases = [],
  canManage = false,
  showEngine = false,
  engineName,
  lastBackup = {},
  backupsUnknown = false,
  phpmyadminSites = null,
  onDelete,
  // The site each database belongs to; it decides what gets backed up.
  applications = [],
  onAttach = null,
}) {
  const t = useTranslations("databases");

  return (
    <CardList>
      {databases.map((database) => {
        const backup = lastBackup?.[database.id];
        // NOT `?? 0`: a missing count means unknown, not "no users".
        const users = userCount(database);
        // Attached to a site this reader cannot see is not "not linked": an
        // attach there would be refused.
        const ownedBy = applicationById(applications, database.application_id);
        const orphanUnknown =
          !ownedBy && database.application_id !== null && database.application_id !== undefined;

        return (
          <CardListItem key={database.id}>
            <div className="min-w-0">
              <Link
                href={`/databases/${database.id}`}
                className="group flex items-center gap-1.5 font-mono text-sm font-medium text-primary underline-offset-4 hover:underline"
              >
                {/* Truncates: generated names are long, and the full name is
                    on the page this opens. */}
                <span className="truncate">{database.name}</span>
                <ChevronRight className="size-3.5 shrink-0 opacity-60" />
              </Link>

              {showEngine ? (
                <p className="mt-1 text-xs text-muted-foreground">{engineName(database.engine)}</p>
              ) : null}

              {/* The three states, before the numbers. */}
              {users === 0 || (!backupsUnknown && !backup) || (!ownedBy && !orphanUnknown) ? (
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {/* Same badge and fix button as the table. */}
                  {!ownedBy && !orphanUnknown ? (
                    onAttach ? (
                      <button
                        type="button"
                        onClick={() => onAttach(database)}
                        className="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        aria-label={`${t("columns.notLinked")}. ${t("columns.attachFor", { name: database.name })}`}
                      >
                        <Badge variant="warning" className="cursor-pointer font-normal underline-offset-2 hover:underline">
                          {t("columns.notLinked")}
                        </Badge>
                      </button>
                    ) : (
                      <Badge variant="warning" className="font-normal">
                        {t("columns.notLinked")}
                      </Badge>
                    )
                  ) : null}
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
                {ownedBy || orphanUnknown ? (
                  <div className="flex justify-between gap-3">
                    <dt className="text-muted-foreground">{t("columns.application")}</dt>
                    <dd className="min-w-0 truncate">
                      {ownedBy ? (
                        <Link
                          href={`/applications/${ownedBy.id}`}
                          prefetch={false}
                          className="underline-offset-4 hover:underline"
                        >
                          {ownedBy.name}
                        </Link>
                      ) : (
                        <span className="text-muted-foreground">{t("columns.applicationUnknown")}</span>
                      )}
                    </dd>
                  </div>
                ) : null}
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

            {/* mt-auto keeps the action row at the bottom of stretched cards. */}
            <div className="mt-auto border-t pt-3">
              {/* Cards wrap: three controls do not fit on one line at 390px. */}
              <DatabaseRowActions
                className="flex flex-wrap items-center justify-end gap-2"
                database={database}
                onDelete={onDelete}
                canManage={canManage}
                phpmyadminSites={phpmyadminSites}
              />
            </div>
          </CardListItem>
        );
      })}
    </CardList>
  );
}
