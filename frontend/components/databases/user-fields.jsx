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
 * Username and connection host, shared by the Add and Edit dialogs.
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
            <FormLabel hint={t("create.accessHint")}>{t("create.access")}</FormLabel>
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
                   * Omitted where accounts have no host: the API refuses
                   * `remote` and `anywhere` on PostgreSQL (roles are
                   * cluster-wide; access is pg_hba.conf). Read from
                   * `supports_remote_users`, never from the engine name.
                   */
                  ...(remoteUsers
                    ? [
                        {
                          value: "remote",
                          label: t("access.remote.label"),
                          hint: t("access.remote.hint"),
                        },
                        {
                          // Opens the engine port to the whole internet, so it
                          // is explained at the moment of choosing.
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
              <FormLabel required hint={t("create.hostHint")}>{t("create.host")}</FormLabel>
              <FormControl>
                <Input
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  placeholder="203.0.113.10"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      ) : null}
    </>
  );
}
