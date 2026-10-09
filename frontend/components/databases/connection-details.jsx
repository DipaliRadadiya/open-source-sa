"use client";

import { useTranslations } from "next-intl";
import { KeyRound, Plug, Server } from "lucide-react";

import { primaryUser, connectionAddress } from "@/lib/databases/connection-parts";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { CardIcon } from "@/components/ui/card-icon";
import { CopyButton } from "@/components/ui/copy-button";
import { ConnectionString } from "@/components/databases/connection-string";
import { ACCESS_TONE } from "@/components/databases/database-users";

// The string first, then its parts in two groups of three: where to connect, and who as.
export function ConnectionDetails({ database, canManage = false }) {
  const t = useTranslations("databases.credentials");
  const tAccess = useTranslations("databases.access");
  const user = primaryUser(database);
  if (!user) return null;

  const { host, port, protocol } = connectionAddress(user);
  const others = (database.users?.length ?? 0) - 1;
  const access = user.connection_preference ?? "localhost";

  const server = [
    { key: "protocol", value: protocol },
    { key: "host", value: host },
    { key: "port", value: port },
  ];
  const credentials = [
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
  ];

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="flex min-w-48 flex-1 items-center gap-3">
          <CardIcon icon={Plug} />
          <div>
            <h2 className="text-[15px] font-semibold tracking-tight">{t("title")}</h2>
            <p className="text-sm text-muted-foreground">{t("description")}</p>
          </div>
        </div>
        {/* Where this user may connect from: the first thing a remote app needs to know. */}
        <Badge variant={ACCESS_TONE[access] ?? "muted"} className="font-normal">
          {tAccess(`${access}.label`)}
          {access === "remote" && user.host ? ` · ${user.host}` : ""}
        </Badge>
      </div>

      <div className="space-y-5 px-5 py-5">
        <ConnectionString value={user.connection_string} />
        <FieldGroup icon={Server} title={t("server")} fields={server} t={t} />
        <FieldGroup icon={KeyRound} title={t("credentialsTitle")} fields={credentials} t={t} />
      </div>

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

function FieldGroup({ icon: Icon, title, fields, t }) {
  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-1.5 text-sm font-medium">
        <Icon className="size-4 text-muted-foreground" aria-hidden />
        {title}
      </h3>
      <div className="grid divide-y overflow-hidden rounded-xl border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
        {fields.map((field) => (
          <div key={field.key} className="flex min-w-0 items-start justify-between gap-2 px-3.5 py-3">
            <div className="min-w-0">
              <p className="text-xs text-muted-foreground">{t(field.key)}</p>
              {/* Wraps rather than truncating: a cut-off name still looks valid. */}
              {field.withheld ? (
                <p className="text-sm text-muted-foreground">{t("passwordWithheld")}</p>
              ) : (
                <p className="font-mono text-sm break-all">
                  {field.value ? (field.mask ? "••••••••" : field.value) : "—"}
                </p>
              )}
            </div>
            {field.value ? (
              <CopyButton
                value={field.value}
                label={t("copyField", { field: t(field.key) })}
                className="-mt-0.5 -mr-1.5"
              />
            ) : null}
          </div>
        ))}
      </div>
    </section>
  );
}
