import { useWatch } from "react-hook-form";
import { useTranslations } from "next-intl";
import { REDIRECT_STATUSES } from "@/lib/schemas/domain";
import { Input } from "@/components/ui/input";
import { FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

// Alias or redirect, plus the redirect's target and status. Shared by Add and Edit domain.
export function DomainTypeFields({ form }) {
  const t = useTranslations("applications.domains");
  const type = useWatch({ control: form.control, name: "type" });

  return (
    <>
      <FormField
        control={form.control}
        name="type"
        render={({ field }) => (
          <FormItem>
            <FormLabel hint={t("add.typeHint")}>{t("add.type")}</FormLabel>
            <Select value={field.value} onValueChange={field.onChange}>
              <FormControl>
                <SelectTrigger className="w-full">
                  <SelectValue>
                    <span className="min-w-0 truncate">{t(`type.${field.value}`)}</span>
                  </SelectValue>
                </SelectTrigger>
              </FormControl>
              <SelectContent>
                <SelectItem value="alias">{t("type.alias")}</SelectItem>
                <SelectItem value="redirect">{t("type.redirect")}</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {type === "redirect" ? t("add.redirectHint") : t("add.aliasHint")}
            </p>
            <FormMessage />
          </FormItem>
        )}
      />

      {type === "redirect" ? (
        <>
          <FormField
            control={form.control}
            name="redirect_to"
            render={({ field }) => (
              <FormItem>
                <FormLabel required hint={t("add.redirectToHint")}>{t("add.redirectTo")}</FormLabel>
                <FormControl>
                  <Input placeholder="https://example.com" autoComplete="off" spellCheck={false} {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="redirect_status"
            render={({ field }) => (
              <FormItem>
                <FormLabel hint={t("add.redirectStatusHint")}>{t("add.redirectStatus")}</FormLabel>
                <Select value={String(field.value)} onValueChange={(v) => field.onChange(Number(v))}>
                  <FormControl>
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {REDIRECT_STATUSES.map((code) => (
                      <SelectItem key={code} value={String(code)}>
                        {t(`add.redirectStatusOption.${code}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
        </>
      ) : null}
    </>
  );
}
