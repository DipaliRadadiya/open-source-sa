import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { ChoiceField } from "@/components/ui/choice-field";
import {
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

/**
 * Username and where it may connect from — identical in Add and Edit, so it
 * lives once. Two copies drifted apart the moment one of them gained a hint.
 *
 * `remoteUsers` defaults true so an older API — which sends no such field —
 * keeps offering the choice rather than hiding a control that works.
 */
export function UserFields({ form, access, lockUsername = false, remoteUsers = true }) {
  const t = useTranslations("databases");

  return (
    <>
      <FormField
        control={form.control}
        name="username"
        render={({ field }) => (
          <FormItem>
            <FormLabel required>{t("create.username")}</FormLabel>
            <FormControl>
              <Input
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                placeholder={t("create.usernamePlaceholder")}
                disabled={lockUsername}
              disabledReason={t("users.nameIsFixed")}
                {...field}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="connection_preference"
        render={({ field }) => (
          <FormItem>
            <FormLabel>{t("create.access")}</FormLabel>
            <FormControl>
              <ChoiceField
                value={field.value}
                onChange={field.onChange}
                options={[
                  {
                    value: "localhost",
                    label: t("access.localhost.label"),
                    hint: t("access.localhost.hint"),
                  },
                  /*
                   * Dropped entirely on an engine whose accounts have no host.
                   *
                   * `SupportsRemoteDatabaseUsers` refuses `remote` and
                   * `anywhere` on PostgreSQL — a role is cluster-wide, and
                   * which addresses reach it is `pg_hba.conf`, which the panel
                   * does not own. The create-database dialog stopped offering
                   * them; these two dialogs never did, so the same choice was
                   * still one click and one 422 away on the same database.
                   *
                   * Read from `supports_remote_users`, never from the engine's
                   * name: the backend publishes the fact precisely so this file
                   * does not have to know which engine PostgreSQL is.
                   */
                  ...(remoteUsers
                    ? [
                        {
                          value: "remote",
                          label: t("access.remote.label"),
                          hint: t("access.remote.hint"),
                        },
                        {
                          // Opens the engine port to every address on the
                          // internet. That belongs at the moment of choosing,
                          // not in a firewall rule discovered later.
                          value: "anywhere",
                          label: t("access.anywhere.label"),
                          hint: t("access.anywhere.hint"),
                          tone: "warning",
                        },
                      ]
                    : []),
                ]}
              />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      {access === "remote" ? (
        <FormField
          control={form.control}
          name="host"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("create.host")}</FormLabel>
              <FormControl>
                <Input
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="203.0.113.10"
                  {...field}
                />
              </FormControl>
              <p className="text-xs text-muted-foreground">
                {t("create.hostHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />
      ) : null}
    </>
  );
}
