import Link from "@/components/ui/app-link";
import { getTranslations } from "next-intl/server";
import { Database, TriangleAlert } from "lucide-react";
import { DatabaseCardActions } from "@/components/applications/database-card-actions";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

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
      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Database className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          <CardDescription>{t("description")}</CardDescription>
        </div>

        {failed ? null : warn ? (
          <Badge variant="warning" className="w-fit gap-1.5 font-normal">
            <TriangleAlert className="size-3" />
            {t("notBackedUp")}
          </Badge>
        ) : missing ? null : (
          <Badge variant="secondary" className="w-fit gap-1.5 font-normal">
            {t("count", { count: databases.length })}
          </Badge>
        )}
      </CardHeader>

      <CardContent className="flex flex-1 flex-col p-0">
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
          <ul className="divide-y border-t">
            {databases.map((database) => (
              <li key={database.id} className="flex items-center gap-3 px-6 py-3">
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

        {/* Databases are a server-level permission; a site-level reader may lack it. */}
        {canSeeDatabases ? (
          <div className="px-(--card-spacing) pt-(--card-spacing)">
            <DatabaseCardActions
              application={application}
              databases={databases}
              unattached={unattached}
              engines={engines}
              warn={warn}
            />
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}
