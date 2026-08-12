"use client";

import { useTranslations } from "next-intl";
import { Plug } from "lucide-react";
import { primaryUser, connectionAddress } from "@/lib/databases/connection-parts";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { PhpmyadminButton } from "@/components/databases/phpmyadmin-button";

/**
 * The five values an application needs, at the top of the page.
 *
 * These used to be reachable only by opening the Users tab and finding the
 * right row — three clicks for the thing everyone comes back for, while charset
 * and collation, which nobody watches, sat above the fold. This is a placement
 * change, not new data: the same fields, where the task starts.
 *
 * Rendered only when something can actually connect. A database with no users
 * has no credentials to show, and the Users tab says so properly.
 */
export function ConnectionDetails({ database, canManage = false }) {
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
    // Masked, not hidden behind a reveal: it exists to be copied into a config
    // file, and reading a 24-character password off a screen is nobody's plan.
    { key: "password", value: user.password, mask: true },
  ].filter((field) => field.value);

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex items-center gap-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Plug className="size-3.5" />
          </span>
          <div>
            <h2 className="text-base font-semibold tracking-tight">
              {t("title")}
            </h2>
            <p className="text-sm text-muted-foreground">{t("description")}</p>
          </div>
        </div>

        {/* Labelled, not a bare icon: it sits alone in the header with no value
            beside it to say what it would copy — and on a phone the masked
            preview is too wide to show at all. */}
        {/* Both ways in, together: the connection string is how an
            application reaches this database, phpMyAdmin is how a person does.
            Someone who wants to look at their data starts here — the page
            header was where it was easy to put, not where it is looked for. */}
        <div className="flex flex-wrap items-center gap-2">
          {user.connection_string ? (
            <CopyButton
              value={user.connection_string}
              label={t("copyString")}
              text
            />
          ) : null}
          <PhpmyadminButton database={database} canManage={canManage} />
        </div>
      </div>

      <CardContent className="grid grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 sm:grid-cols-3 lg:grid-cols-5">
        {fields.map((field) => (
          <div key={field.key} className="min-w-0 space-y-0.5">
            <p className="text-xs text-muted-foreground">{t(field.key)}</p>
            <div className="flex items-center gap-0.5">
              <span className="truncate font-mono text-sm">
                {field.mask ? "••••••••" : field.value}
              </span>
              <CopyButton
                value={field.value}
                label={t("copyField", { field: t(field.key) })}
                className="size-6"
              />
            </div>
          </div>
        ))}
      </CardContent>

      {/* One credential is shown, but a database can have several — say so
          rather than let someone conclude the others were lost. */}
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
