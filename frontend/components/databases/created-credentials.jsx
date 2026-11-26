import Link from "@/components/ui/app-link";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { CircleCheck, Eye, EyeOff } from "lucide-react";
import { connectionAddress } from "@/lib/databases/connection-parts";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { HoverHint } from "@/components/ui/hover-hint";
import { FormModal } from "@/components/ui/form-modal";
import { ConnectionString } from "@/components/databases/connection-string";

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
    { key: "password", value: user?.password },
  ].filter((field) => field.value);

  const escapedPassword = Boolean(
    user?.password &&
      user?.connection_string &&
      !user.connection_string.includes(user.password),
  );

  return (
    <FormModal
      open={open}
      onOpenChange={onOpenChange}
      className="sm:max-w-xl"
      icon={CircleCheck}
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
          {database?.id && !forUser ? (
            <Button asChild onClick={close}>
              <Link href={`/databases/${database.id}`}>{t("created.open")}</Link>
            </Button>
          ) : null}
        </>
      }
    >
      {user?.connection_string ? (
        <ConnectionString value={user.connection_string} hint={t("created.connectionStringHint")} />
      ) : null}

      {fields.length ? (
        <div className="space-y-2">
          <div className="divide-y overflow-hidden rounded-xl border">
            {fields.map((field) => (
              <div key={field.key} className="flex min-h-11 items-center gap-3 py-1.5 pr-1.5 pl-3.5">
                <span className="w-24 shrink-0 text-xs text-muted-foreground">{t(`created.${field.key}`)}</span>
                {field.key === "password" ? (
                  <MaskedValue value={field.value} />
                ) : (
                  <span className="min-w-0 flex-1 font-mono text-sm break-all">{field.value}</span>
                )}
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
        <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
          {t("created.noUserWarning")}
        </p>
      )}
    </FormModal>
  );
}

function MaskedValue({ value }) {
  const tc = useTranslations("common");
  const [shown, setShown] = useState(false);
  const label = shown ? tc("hidePassword") : tc("showPassword");
  return (
    <>
      <span className="min-w-0 flex-1 font-mono text-sm break-all">{shown ? value : "••••••••••••"}</span>
      <HoverHint content={label}>
        <button
          type="button"
          onClick={() => setShown((s) => !s)}
          aria-label={label}
          aria-pressed={shown}
          className="flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        >
          {shown ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
        </button>
      </HoverHint>
    </>
  );
}
