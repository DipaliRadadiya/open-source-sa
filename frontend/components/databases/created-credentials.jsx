import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { CircleCheck } from "lucide-react";
import { connectionAddress } from "@/lib/databases/connection-parts";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";
import { ConnectionString } from "@/components/databases/connection-string";

// Not a one-time secret: `DatabaseUserResource` returns the password on every read.
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
    // In full here: this is the moment it is handed over. The string above masks it.
    { key: "password", value: user?.password },
  ].filter((field) => field.value);

  // Compared on the two real strings rather than re-deriving PHP's rawurlencode.
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
      {/* The detail page's look: the coloured string, then one value per line, each with
          its copy button (Krishna, 8 Oct: the two-column grid read as loose and uneven). */}
      {user?.connection_string ? (
        <ConnectionString value={user.connection_string} hint={t("created.connectionStringHint")} />
      ) : null}

      {fields.length ? (
        <div className="space-y-2">
          <div className="divide-y overflow-hidden rounded-xl border">
            {fields.map((field) => (
              <div key={field.key} className="flex min-h-11 items-center gap-3 py-1.5 pr-1.5 pl-3.5">
                <span className="w-24 shrink-0 text-xs text-muted-foreground">{t(`created.${field.key}`)}</span>
                {/* Wraps rather than truncating: a cut-off name still looks valid. */}
                <span className="min-w-0 flex-1 font-mono text-sm break-all">{field.value}</span>
                <CopyButton
                  value={field.value}
                  label={t("credentials.copyField", { field: t(`created.${field.key}`) })}
                />
              </div>
            ))}
          </div>
          {escapedPassword ? (
            <p className="text-xs leading-relaxed text-muted-foreground">{t("created.passwordEscaped")}</p>
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
