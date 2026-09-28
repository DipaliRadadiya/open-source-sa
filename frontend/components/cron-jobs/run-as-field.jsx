import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { OTHER_USER } from "@/lib/schemas/cronjob";
import { Input } from "@/components/ui/input";
import {
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from "@/components/ui/form";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * "Runs as" for both dialogs: a panel system user, or any OS account typed by
 * name. Shared so the create and edit forms can't drift apart.
 */
export function RunAsField({ form, systemUsers = [], systemUsersFailed = false }) {
  const t = useTranslations("cronJobs");
  // useWatch, not form.watch: the latter returns a fresh function each render,
  // which the React Compiler can't memoize (it skips the whole component).
  const runAs = useWatch({ control: form.control, name: "run_as" });

  return (
    <>
      <FormField
        control={form.control}
        name="run_as"
        render={({ field }) => (
          <FormItem>
            <FormLabel required hint={t("form.runAsHint")}>{t("form.runAs")}</FormLabel>
            <Select onValueChange={field.onChange} value={field.value}>
              <FormControl>
                <SelectTrigger className="w-full">
                  <SelectValue placeholder={t("form.runAsPlaceholder")} />
                </SelectTrigger>
              </FormControl>
              <SelectContent position="popper">
                {systemUsers.map((u) => (
                  <SelectItem key={u.id} value={String(u.id)}>
                    {u.username}
                  </SelectItem>
                ))}
                <SelectItem value={OTHER_USER}>{t("form.otherUser")}</SelectItem>
              </SelectContent>
            </Select>
            {/* A picker holding nothing but "Other OS user…" is what you get
                when this list fails — and it needs the `system_user`
                permission, which has nothing to do with cronjob, so a 403 is
                ordinary. Unsaid, it reads as "this server has no accounts"
                and leaves you to guess that a username can be typed. */}
            {systemUsersFailed ? (
              <FormDescription>{t("form.runAsUnavailable")}</FormDescription>
            ) : null}
            <FormMessage />
          </FormItem>
        )}
      />

      {runAs === OTHER_USER ? (
        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required hint={t("form.usernameHint")}>{t("form.username")}</FormLabel>
              <FormControl>
                <Input className="font-mono" autoComplete="off" placeholder="www-data" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      ) : null}
    </>
  );
}
