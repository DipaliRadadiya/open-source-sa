import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { Database, TriangleAlert } from "lucide-react";
import { DatabaseCardActions } from "@/components/applications/database-card-actions";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * The databases this site's backups will actually contain.
 *
 * Not a list of what the site connects to — the panel cannot know that, and it
 * is spelled out here so nobody reads the card as one. What it shows is the
 * link that backups, staging, cloning and restoring go by.
 *
 * The empty state is a warning ONLY for a site type that declares it needs a
 * database. A static site or a git deploy showing permanent amber is how a
 * warning becomes wallpaper.
 */
export async function DatabaseCard({
  application,
  databases = [],
  // The databases on this server that belong to no site — what "attach" can
  // actually offer. Empty means the only honest next step is creating one.
  unattached = [],
  engines = [],
  failed = false,
  needsDatabase = false,
  canSeeDatabases = false,
  className,
}) {
  const t = await getTranslations("applications.databaseCard");

  const missing = databases.length === 0;
  // Missing AND the type wanted one. The two halves are separate on purpose:
  // a site with no database is only a problem when its kind needs one.
  const warn = missing && needsDatabase && !failed;

  return (
    <Card className={className}>
      <CardHeader className="gap-1.5">
        <div className="min-w-0 space-y-1">
          <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
            <Database className="size-4 text-primary" />
            {t("title")}
          </CardTitle>
          {/* Says what the link means before anything below implies otherwise. */}
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
          // Never rendered as "none": a failed check and an empty site look the
          // same here and mean opposite things.
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
                {/* The name is the way in. Reading it here and then hunting
                    for it in a server-wide list is the detour this card was
                    adding to every visit. */}
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

        {/* Only for someone who could actually act on it. Databases are a
            server-level permission, so a site-level reader may have none. */}
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
