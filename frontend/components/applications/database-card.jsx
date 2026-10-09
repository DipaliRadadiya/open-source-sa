import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { Database, TriangleAlert } from "lucide-react";
import { DatabaseCardActions } from "@/components/applications/database-card-actions";
import { Badge } from "@/components/ui/badge";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

// The databases backups, staging, cloning and restoring use, not what the site
// connects to. The empty state warns only for types that declare a database need.
export async function DatabaseCard({
  application,
  databases = [],
  // Server databases attached to no site (what "attach" can offer). Empty means
  // the next step is creating one.
  unattached = [],
  engines = [],
  failed = false,
  needsDatabase = false,
  canSeeDatabases = false,
  className,
}) {
  const t = await getTranslations("applications.databaseCard");

  const missing = databases.length === 0;
  const warn = missing && needsDatabase && !failed;

  return (
    <Card className={className}>
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          {t("title")}
          {failed ? null : warn ? (
            <Badge variant="warning" className="gap-1.5 font-normal">
              <TriangleAlert className="size-3" />
              {t("notBackedUp")}
            </Badge>
          ) : missing ? null : (
            <span className="text-xs font-normal tabular-nums text-muted-foreground">
              {t("count", { count: databases.length })}
            </span>
          )}
        </CardTitle>
        {/* Databases are a server-level permission; a site-level reader may lack it. */}
        {canSeeDatabases ? (
          <CardAction className="row-span-1 flex gap-2 self-center">
            <DatabaseCardActions
              application={application}
              databases={databases}
              unattached={unattached}
              engines={engines}
              warn={warn}
            />
          </CardAction>
        ) : null}
      </CardHeader>

      <CardContent className="p-0">
        {failed ? (
          // Never rendered as "none": a failed check is not an empty site.
          <p className="px-(--card-spacing) text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : missing ? (
          <p
            className={`px-(--card-spacing) text-sm ${warn ? "text-warning" : "text-muted-foreground"}`}
          >
            {warn ? t("missingWarning") : t("missingFine")}
          </p>
        ) : (
          <ul className="-mt-(--card-spacing) divide-y">
            {databases.map((database) => (
              <li key={database.id} className="flex items-center gap-3 px-(--card-spacing) py-3">
                <Database className="size-4 shrink-0 text-muted-foreground" />
                <Link
                  href={`/databases/${database.id}`}
                  prefetch={false}
                  className="min-w-0 flex-1 truncate font-mono text-xs underline-offset-4 hover:underline"
                >
                  {database.name}
                </Link>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {database.size_human}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
