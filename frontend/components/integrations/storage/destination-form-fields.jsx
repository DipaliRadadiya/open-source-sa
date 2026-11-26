import { useTranslations } from "next-intl";
import { TriangleAlert } from "lucide-react";
import {
  NUMBER,
  PRESETS,
  SECRET,
  TEXTAREA,
  TOGGLE,
  fieldsFor,
  isRequired,
  presetFor,
  providerForPreset,
  warningFor,
} from "@/lib/storage/providers";
import { Input } from "@/components/ui/input";
import { Caution } from "@/components/ui/caution";
import { localStorageSettings } from "@/lib/storage/local-settings";
import { PasswordInput } from "@/components/ui/password-input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";

export function DestinationFormFields({
  existing = false,
  form,
  preset,
  onPresetChange,
  disabled,
  // Never render credentials on edit: the API treats them as a rotation.
  hideSecrets = false,
  localSettings = null,
}) {
  const t = useTranslations("storage.form");
  const provider = providerForPreset(preset);
  const endpointHint = presetFor(preset)?.endpointHint ?? "";
  const warning = warningFor(preset);
  const fields = fieldsFor(provider).filter(
    (f) => !hideSecrets || (f.kind !== SECRET && f.kind !== TEXTAREA),
  );

  return (
    <>
      {onPresetChange ? (
        <FormItem>
          <FormLabel required hint={t("providerHint")}>{t("provider")}</FormLabel>
          <Select value={preset} onValueChange={onPresetChange} disabled={disabled}>
            <FormControl>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {PRESETS.filter((p) => !p.legacy).map((p) => (
                <SelectItem key={p.value} value={p.value}>
                  {t(`providers.${p.value}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FormItem>
      ) : null}

      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem>
            <FormLabel required hint={t("nameHint")}>{t("name")}</FormLabel>
            <FormControl>
              <Input placeholder={t("namePlaceholder")} disabled={disabled} {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      {warning ? (
        <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs leading-relaxed">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-warning" />
          <p>{t(warning)}</p>
        </div>
      ) : null}

      {fields.map((definition) => (
        <ConfigField
          key={definition.name}
          definition={definition}
          form={form}
          preset={preset}
          disabled={disabled}
          endpointHint={endpointHint}
          localSettings={localSettings}
          existing={existing}
          t={t}
        />
      ))}

      {provider === "local" ? null : (
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
            <p className="text-xs text-muted-foreground">
              {provider === "s3" ? t("prefixHint") : t("prefixHintRemote")}
            </p>
            {existing ? (
              <Caution size="md" className="mt-1">
                <p className="leading-relaxed">{t("prefixChangeWarning")}</p>
              </Caution>
            ) : null}
            <FormMessage />
          </FormItem>
        )}
      />
      )}
    </>
  );
}

function ConfigField({ definition, form, preset, disabled, endpointHint, existing = false, localSettings = null, t }) {
  const { name, kind, mono, placeholder, warnWhenOff, hintsEndpoint, changeWarning } = definition;
  const sharedS3Rule = existing && providerForPreset(preset) === "s3" && (name === "endpoint" || name === "region");
  const required = !sharedS3Rule && isRequired(definition, preset);
  const local = name === "path" ? localStorageSettings(localSettings) : null;
  const help = local
    ? local.allowed_roots.length ? t("help.path", { roots: local.allowed_roots.join(", ") }) : t("help.noLocalRoots")
    : t.has(`help.${name}`) ? t(`help.${name}`) : null;
  const hint = local ? t("placeholders.path", { path: local.default_path }) : t.has(`placeholders.${name}`) ? t(`placeholders.${name}`) : placeholder;

  return (
    <FormField
      control={form.control}
      name={`config.${name}`}
      render={({ field }) => {
        if (kind === TOGGLE) {
          const on = field.value !== false;

          return (
            <FormItem className="flex flex-row items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel>{t(`fields.${name}`)}</FormLabel>
                {help ? <p className="text-xs text-muted-foreground">{help}</p> : null}
                {warnWhenOff && !on ? (
                  <p className="flex items-start gap-1.5 text-xs text-warning">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {t(warnWhenOff)}
                  </p>
                ) : null}
              </div>
              <FormControl>
                <Switch checked={on} onCheckedChange={field.onChange} disabled={disabled} />
              </FormControl>
            </FormItem>
          );
        }

        return (
          <FormItem>
            <FormLabel required={required}>{t(`fields.${name}`)}</FormLabel>
            <FormControl>
              {kind === TEXTAREA ? (
                <Textarea
                  rows={4}
                  placeholder={hint}
                  className="font-mono text-xs"
                  autoComplete="off"
                  spellCheck={false}
                  disabled={disabled}
                  {...field}
                />
              ) : kind === SECRET ? (
                <PasswordInput
                  autoComplete="off"
                  spellCheck={false}
                  placeholder={hint}
                  disabled={disabled}
                  className={mono ? "font-mono" : undefined}
                  {...field}
                />
              ) : (
                <Input
                  type={kind === NUMBER ? "number" : "text"}
                  placeholder={
                    hintsEndpoint ? endpointHint || t("endpointPlaceholder") : hint
                  }
                  className={mono ? "font-mono" : undefined}
                  autoComplete="off"
                  spellCheck={false}
                  disabled={disabled}
                  {...field}
                />
              )}
            </FormControl>
            {hintsEndpoint ? (
              <p className="text-xs text-muted-foreground">
                {endpointHint ? t("endpointExample", { example: endpointHint }) : t("endpointAws")}
              </p>
            ) : help ? (
              <p className="text-xs text-muted-foreground">{help}</p>
            ) : null}
            {existing && changeWarning ? (
              <Caution size="md" className="mt-1">
                <p className="leading-relaxed">{t(changeWarning)}</p>
              </Caution>
            ) : null}
            <FormMessage />
          </FormItem>
        );
      }}
    />
  );
}
