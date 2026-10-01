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

/**
 * The fields that describe *where* the data goes, shared by create and edit.
 *
 * Every provider's inputs come from one declaration (`lib/storage/providers`)
 * rendered by one loop. The provider picker is a real field: it is stored and
 * decides what the rest of the form asks for.
 */
export function DestinationFormFields({
  // True when editing, where changing the folder affects existing archives.
  existing = false,
  form,
  preset,
  onPresetChange,
  disabled,
  // Editing never renders credentials: the API treats their presence as
  // "rotate this", so they would overwrite the stored secret. Rotation has its
  // own dialog; not drawing the inputs is the guarantee.
  hideSecrets = false,
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
              {/* Legacy providers stay resolvable for destinations that already
                  use them, but are not offered for new ones. */}
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

      {/*
        * Provider constraints, stated before credentials are entered. Google
        * Drive: a service account has no quota, so personal-Drive folders are
        * refused. pCloud: its WebDAV is documented as unreliable for large
        * files.
        */}
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
          existing={existing}
          t={t}
        />
      ))}

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
            {/*
              * The folder is the S3 disk root (or appended to the FTP/SFTP root)
              * and stored keys are relative to it, so changing it strands every
              * existing archive. Only shown when editing.
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

/**
 * One config input, drawn according to its declared kind. Labels and help text
 * are looked up by field name, so there is no per-provider branch here.
 */
function ConfigField({ definition, form, preset, disabled, endpointHint, existing = false, t }) {
  const { name, kind, mono, placeholder, warnWhenOff, hintsEndpoint } = definition;
  // An existing S3 destination is edited without knowing which service it is,
  // so endpoint and region follow the shared rule in
  // `editStorageDestinationSchema` rather than any one preset's.
  const sharedS3Rule = existing && providerForPreset(preset) === "s3" && (name === "endpoint" || name === "region");
  const required = !sharedS3Rule && isRequired(definition, preset);
  const help = t.has(`help.${name}`) ? t(`help.${name}`) : null;
  // A translated placeholder when one exists, else the definition's literal
  // (numeric port defaults).
  const hint = t.has(`placeholders.${name}`) ? t(`placeholders.${name}`) : placeholder;

  return (
    <FormField
      control={form.control}
      name={`config.${name}`}
      render={({ field }) => {
        if (kind === TOGGLE) {
          // `field.value` can be undefined on first paint, before reset applies
          // the declared default.
          const on = field.value !== false;

          return (
            <FormItem className="flex flex-row items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel>{t(`fields.${name}`)}</FormLabel>
                {help ? <p className="text-xs text-muted-foreground">{help}</p> : null}
                {/*
                  * Shown only when off, naming what travels unencrypted.
                  */}
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
            <FormMessage />
          </FormItem>
        );
      }}
    />
  );
}
