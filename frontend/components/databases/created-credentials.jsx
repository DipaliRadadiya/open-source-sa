"use client";

import { useTranslations } from "next-intl";
import { CircleCheck } from "lucide-react";
import { connectionAddress } from "@/lib/databases/connection-parts";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * What was just created, and how to connect to it.
 *
 * This step exists because "Database created ✓" answers a question nobody
 * asked. People create a database in order to point something at it, so the
 * connection string is the actual result — and the moment it is on screen is
 * the one moment the user is definitely looking.
 */
export function CreatedCredentials({ database, open, onOpenChange }) {
  const t = useTranslations("databases");
  const user = database?.users?.[0] ?? null;
  const { host, port } = connectionAddress(user);

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      icon={CircleCheck}
      title={t("created.title", { name: database?.name ?? "" })}
      description={
        user ? t("created.subtitle") : t("created.subtitleNoUser")
      }
      footer={
        <Button type="button" onClick={() => onOpenChange?.(false)}>
          {t("created.done")}
        </Button>
      }
    >
      {/* Same five fields, in the same order, as the connection card on the
          detail page — a database client asks for host and port separately, and
          digging them out of the connection string is not the job of the screen
          that just created them. */}
      {host || port ? (
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("created.host")} value={host} />
          <Field label={t("created.port")} value={port} />
        </div>
      ) : null}

      <Field label={t("created.database")} value={database?.name} />

      {user ? (
        <>
          <Field label={t("created.username")} value={user.username} />
          <Field label={t("created.password")} value={user.password} />
          {/* The one line that replaces all of the above for most people. */}
          <Field
            label={t("created.connectionString")}
            value={user.connection_string}
            wrap
          />
        </>
      ) : (
        // A database nobody can sign in to is not finished, and saying so here
        // is cheaper than letting them discover it from a failing app.
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("created.noUserWarning")}
        </p>
      )}
    </FormModal>
  );
}

function Field({ label, value, wrap = false }) {
  const t = useTranslations("databases.credentials");
  if (!value) return null;

  return (
    <div className="space-y-1.5">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <div className="flex items-start gap-1.5 rounded-lg border bg-muted/40 px-3 py-2">
        <code
          className={`min-w-0 flex-1 font-mono text-xs ${wrap ? "break-all" : "truncate"}`}
        >
          {value}
        </code>
        {/* Six copy buttons in one dialog: unlabelled, a screen reader
            announces "Copy" six times over. */}
        <CopyButton value={value} label={t("copyField", { field: label })} />
      </div>
    </div>
  );
}
