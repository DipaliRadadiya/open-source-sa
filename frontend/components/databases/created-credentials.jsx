import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CircleCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import { connectionAddress } from "@/lib/databases/connection-parts";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";

/**
 * What was just created, and how to connect to it.
 *
 * Not a one-time secret reveal: `DatabaseUserResource` returns the password on
 * every read and the database page shows all values. The connection string
 * leads; the parts follow for clients that ask for host and port separately.
 */
// `forUser`: shown after adding a user on the database's own page.
export function CreatedCredentials({ database, open, onOpenChange, forUser = false }) {
  const t = useTranslations("databases");
  const user = database?.users?.[0] ?? null;
  const { host, port } = connectionAddress(user);
  const close = () => onOpenChange?.(false);

  const fields = [
    { key: "host", value: host },
    { key: "port", value: port },
    { key: "database", value: database?.name },
    { key: "username", value: user?.username },
    // Shown in full (masked only at rest): the connection string above already
    // prints it in clear.
    { key: "password", value: user?.password },
  ].filter((field) => field.value);

  // Whether the string carries an escaped password (`+` as `%2B`), which needs
  // a note. Compared on the two real strings rather than re-deriving PHP's
  // rawurlencode, so it fires exactly when they differ.
  const escapedPassword = Boolean(
    user?.password &&
      user?.connection_string &&
      !user.connection_string.includes(user.password),
  );

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      // At max-w-lg the connection string broke mid-address.
      className="sm:max-w-xl"
      icon={CircleCheck}
      // Success tone: this reports a finished action.
      iconTone="success"
      title={
        forUser
          ? t("created.userTitle", { username: user?.username ?? "" })
          : t("created.title", { name: database?.name ?? "" })
      }
      description={
        forUser ? t("created.userSubtitle") : user ? t("created.subtitle") : t("created.subtitleNoUser")
      }
      footer={
        <>
          <Button type="button" variant="outline" onClick={close}>
            {t("created.done")}
          </Button>
          {/* The database page is where these values live from now on. */}
          {database?.id && !forUser ? (
            <Button asChild onClick={close}>
              <Link href={`/databases/${database.id}`}>{t("created.open")}</Link>
            </Button>
          ) : null}
        </>
      }
    >
      {/* One bordered block with a divider, matching the detail page's
          connection card. */}
      {user?.connection_string || fields.length ? (
        <div className="overflow-hidden rounded-lg border">
          {user?.connection_string ? (
            <div className="space-y-2 border-b bg-muted/40 px-4 py-3.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">
                  {t("created.connectionString")}
                </p>
                {/* Labelled and out of the value's line, which needs the width. */}
                <CopyButton
                  value={user.connection_string}
                  label={t("created.copyString")}
                  text
                />
              </div>
              <code className="block font-mono text-xs leading-relaxed break-all">
                {user.connection_string}
              </code>
              <p className="text-xs text-muted-foreground">
                {t("created.connectionStringHint")}
              </p>
            </div>
          ) : null}

          {fields.length ? (
            /* Two columns, not the detail card's three: at this dialog's width
               three columns wrap the password, the one value read character
               by character. */
            <div className="grid grid-cols-2 gap-x-4 gap-y-3.5 px-4 py-3.5">
              {fields.map((field) => (
                // The name takes both tracks, as on the detail page's card.
                <div
                  key={field.key}
                  className={cn(
                    "min-w-0",
                    field.key === "database" && "col-span-2",
                  )}
                >
                  {/* Copy button beside the label, not the value, so it does
                      not narrow wrapped values. */}
                  <div className="flex items-center gap-0.5">
                    <p className="min-w-0 truncate text-xs text-muted-foreground">
                      {t(`created.${field.key}`)}
                    </p>
                    <CopyButton
                      value={field.value}
                      label={t("credentials.copyField", {
                        field: t(`created.${field.key}`),
                      })}
                      className="size-6"
                    />
                  </div>
                  {/* Wraps rather than truncating: a cut-off name still looks valid. */}
                  <p className="font-mono text-sm break-all">{field.value}</p>
                </div>
              ))}
              {/* A footnote across the block, not inside the narrow password cell. */}
              {escapedPassword ? (
                <p className="col-span-2 text-xs leading-relaxed text-muted-foreground">
                  {t("created.passwordEscaped")}
                </p>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {user ? null : (
        // A database nobody can sign in to is not finished.
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("created.noUserWarning")}
        </p>
      )}
    </FormModal>
  );
}
