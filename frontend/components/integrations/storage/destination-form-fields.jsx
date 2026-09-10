import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import { STORAGE_PROVIDERS } from "@/lib/schemas/storage";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";

/**
 * The fields that describe *where* the data goes — shared by create and edit,
 * because they are the same question in both and had no business being typed
 * out twice.
 *
 * The provider select is a hint mechanism, not a stored value: the backend has
 * no provider concept, it takes any S3-compatible endpoint. Choosing one only
 * changes the example shown under the endpoint field, because every real
 * endpoint contains something only the account owner knows (an account id, a
 * region) — pre-filling a template would just hand back a value that fails
 * validation.
 *
 * `required` says which of endpoint and region this particular destination
 * needs; it comes from lib/storage/requirements and differs between create and
 * edit, so the asterisk is a claim about this form rather than decoration.
 */
export function DestinationFormFields({
  // True on a destination that already exists, where changing the folder has
  // consequences for archives already in it.
  existing = false,
  form,
  provider,
  onProviderChange,
  disabled,
  required = {},
}) {
  const t = useTranslations("storage.form");
  const hint = STORAGE_PROVIDERS.find((p) => p.value === provider)?.endpointHint ?? "";

  return (
    <>
      {onProviderChange ? (
        <FormItem>
          <FormLabel>{t("provider")}</FormLabel>
          <Select value={provider} onValueChange={onProviderChange} disabled={disabled}>
            <FormControl>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {STORAGE_PROVIDERS.map((p) => (
                <SelectItem key={p.value} value={p.value}>
                  {t(`providers.${p.value}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">{t("providerHint")}</p>
        </FormItem>
      ) : null}

      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem>
            <FormLabel required>{t("name")}</FormLabel>
            <FormControl>
              <Input placeholder={t("namePlaceholder")} disabled={disabled} {...field} />
            </FormControl>
            <p className="text-xs text-muted-foreground">{t("nameHint")}</p>
            <FormMessage />
          </FormItem>
        )}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          control={form.control}
          name="bucket"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("bucket")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("bucketPlaceholder")}
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  disabled={disabled}
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="region"
          render={({ field }) => (
            <FormItem>
              <FormLabel required={Boolean(required.region)}>{t("region")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("regionPlaceholder")}
                  className="font-mono"
                  autoComplete="off"
                  spellCheck={false}
                  disabled={disabled}
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
      </div>

      <FormField
        control={form.control}
        name="endpoint"
        render={({ field }) => (
          <FormItem>
            <FormLabel required={Boolean(required.endpoint)}>{t("endpoint")}</FormLabel>
            <FormControl>
              <Input
                placeholder={hint || t("endpointPlaceholder")}
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                {...field}
              />
            </FormControl>
            <p className="text-xs text-muted-foreground">
              {hint ? t("endpointExample", { example: hint }) : t("endpointAws")}
            </p>
            <FormMessage />
          </FormItem>
        )}
      />

      <FormField
        control={form.control}
        name="prefix"
        render={({ field }) => (
          <FormItem>
            <FormLabel>{t("prefix")}</FormLabel>
            <FormControl>
              <Input
                placeholder={t("prefixPlaceholder")}
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                {...field}
              />
            </FormControl>
            <p className="text-xs text-muted-foreground">{t("prefixHint")}</p>
            {/*
              * The folder is the disk's ROOT, and a backup's stored key is
              * relative to it (`DestinationDisk`: 'root' => $destination->prefix).
              * So changing it does not move anything — it repoints the panel at
              * a different place, and every archive already written stops being
              * found. Said where the change is made, because afterwards the only
              * symptom is a download that reports the file missing.
              *
              * Only when editing: on a destination that does not exist yet there
              * is nothing to strand, and a warning there is just noise.
              */}
            {existing ? (
              <p className="flex items-start gap-1.5 text-xs text-warning">
                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                {t("prefixChangeWarning")}
              </p>
            ) : null}
            <FormMessage />
          </FormItem>
        )}
      />
    </>
  );
}
