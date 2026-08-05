"use client";

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
 */
export function UserFields({ form, access, lockUsername = false }) {
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
                  {
                    value: "remote",
                    label: t("access.remote.label"),
                    hint: t("access.remote.hint"),
                  },
                  {
                    // Opens the engine port to every address on the internet.
                    // That belongs at the moment of choosing, not in a firewall
                    // rule discovered later.
                    value: "anywhere",
                    label: t("access.anywhere.label"),
                    hint: t("access.anywhere.hint"),
                    tone: "warning",
                  },
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
