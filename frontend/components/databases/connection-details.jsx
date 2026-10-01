"use client";

import { useTranslations } from "next-intl";
import { Plug } from "lucide-react";
import { cn } from "@/lib/utils";
import { primaryUser, connectionAddress } from "@/lib/databases/connection-parts";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";

/**
 * The five values an application needs, at the top of the page. Rendered only
 * when a user exists to connect with; the Users tab covers the empty case.
 */
/**
 * Fields that get two grid tracks: a generated name (~31 chars) needs ~275px,
 * more than one track. Host, port and the masked password fit in one.
 */
const WIDE_FIELDS = new Set(["database", "username"]);

export function ConnectionDetails({ database, canManage = false, phpmyadminSites = null }) {
  const t = useTranslations("databases.credentials");
  const user = primaryUser(database);
  if (!user) return null;

  const { host, port } = connectionAddress(user);
  const others = (database.users?.length ?? 0) - 1;

  const fields = [
    { key: "host", value: host },
    { key: "port", value: port },
    { key: "database", value: database.name },
    { key: "username", value: user.username },
    // Masked, not hidden: it is meant to be copied. Null without `database`
    // manage while `password_known` is true: shown as withheld, not dropped.
    {
      key: "password",
      value: user.password,
      mask: true,
      withheld: !canManage && !user.password && user.password_known !== false,
    },
  ].filter((field) => field.value || field.withheld);

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="flex shrink-0 items-center justify-center text-muted-foreground">
            <Plug className="size-3.5" />
          </span>
          <div>
            <h2 className="text-base font-semibold tracking-tight">
              {t("title")}
            </h2>
            <p className="text-sm text-muted-foreground">{t("description")}</p>
          </div>
        </div>

        {/* The copy button is labelled: it sits alone in the header, and on a
            phone the masked preview is too wide to show. The connection string
            and phpMyAdmin sit together as the two ways in. */}
        <div className="flex flex-wrap items-center gap-2">
          {user.connection_string ? (
            <CopyButton
              value={user.connection_string}
              label={t("copyString")}
              text
            />
          ) : null}
          <PhpmyadminButton
            database={database}
            canManage={canManage}
            sites={phpmyadminSites}
          />
        </div>
      </div>

      {/* Even track counts (2 / 4 / 6) so wide fields span two and rows stay
          whole. At xl, Host + Port + name(2) + Username(2) fill the first row
          and the masked password moves to the second. */}
      <CardContent className="grid grid-cols-2 gap-x-4 gap-y-3.5 px-5 py-4 sm:grid-cols-4 xl:grid-cols-6">
        {fields.map((field) => (
          <div
            key={field.key}
            className={cn(
              "min-w-0",
              // Two tracks at xl (~137px each); between sm and xl two tracks
              // are too narrow, so the field takes the whole row.
              WIDE_FIELDS.has(field.key) && "col-span-2 sm:col-span-4 xl:col-span-2",
            )}
          >
            {/* Label and copy button on one line, value beneath: same cell as
                the create dialog, and the button does not narrow the value. */}
            <div className="flex items-center gap-0.5">
              <p className="min-w-0 truncate text-xs text-muted-foreground">
                {t(field.key)}
              </p>
              {field.value ? (
                <CopyButton
                  value={field.value}
                  label={t("copyField", { field: t(field.key) })}
                  className="size-6"
                />
              ) : null}
            </div>
            {/* Wraps rather than truncating: a cut-off name still looks valid. */}
            {field.withheld ? (
              <p className="text-sm text-muted-foreground">{t("passwordWithheld")}</p>
            ) : (
              <p className="font-mono text-sm break-all">
                {field.mask ? "••••••••" : field.value}
              </p>
            )}
          </div>
        ))}
      </CardContent>

      {/* One credential is shown; say when there are others. */}
      {others > 0 ? (
        <div className="border-t bg-muted/30 px-5 py-3">
          <p className="text-xs leading-relaxed text-muted-foreground">
            {t("otherUsers", { count: others })}
          </p>
        </div>
      ) : null}
    </Card>
  );
}
