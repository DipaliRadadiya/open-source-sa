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
 * The fields that describe *where* the data goes — shared by create and edit,
 * because they are the same question in both.
 *
 * Every provider's inputs come from one declaration (`lib/storage/providers`)
 * and are rendered by one loop. The alternative — a form per provider, or one
 * union form with everything nullable — produces a screen that asks for a
 * bucket and a hostname at the same time and leaves the user to work out which
 * half applies to them.
 *
 * The provider picker is now a REAL field: it is submitted, the backend stores
 * it, and it decides what the rest of this form asks for. It used to be a
 * client-only hint that changed an example line, because the API had no
 * provider concept and inferred one from the endpoint hostname.
 */
export function DestinationFormFields({
  // True on a destination that already exists, where changing the folder has
  // consequences for archives already in it.
  existing = false,
  form,
  preset,
  onPresetChange,
  disabled,
  // Editing never touches credentials: the API reads the *presence* of one as
  // "rotate this", so a form that rendered them would post whatever was in its
  // state and overwrite the stored secret. Rotation is its own dialog, and
  // this is enforced by not drawing the inputs rather than by remembering not
  // to submit them.
  hideSecrets = false,
}) {
  const t = useTranslations("storage.form");
  const provider = providerForPreset(preset);
  const endpointHint = presetFor(preset)?.endpointHint ?? "";
  const fields = fieldsFor(provider).filter(
    (f) => !hideSecrets || (f.kind !== SECRET && f.kind !== TEXTAREA),
  );

  return (
    <>
      {onPresetChange ? (
        <FormItem>
          <FormLabel required>{t("provider")}</FormLabel>
          <Select value={preset} onValueChange={onPresetChange} disabled={disabled}>
            <FormControl>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
            </FormControl>
            <SelectContent>
              {PRESETS.map((p) => (
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

      {fields.map((definition) => (
        <ConfigField
          key={definition.name}
          definition={definition}
          form={form}
          preset={preset}
          disabled={disabled}
          endpointHint={endpointHint}
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
              * On S3 the folder is the disk's ROOT and a backup's stored key is
              * relative to it, so changing it does not move anything — it
              * repoints the panel at a different place and every archive
              * already written stops being found. On FTP/SFTP it is appended
              * to the connection root, with the same consequence. Said where
              * the change is made, because afterwards the only symptom is a
              * download that reports the file missing.
              *
              * Only when editing: on a destination that does not exist yet
              * there is nothing to strand, and a warning there is just noise.
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
 * One config input, drawn according to its declared kind.
 *
 * Help text and labels are looked up by field name, so adding a provider field
 * means adding a dictionary key — there is no per-provider branch here to
 * forget to update.
 */
function ConfigField({ definition, form, preset, disabled, endpointHint, t }) {
  const { name, kind, mono, placeholder, warnWhenOff, hintsEndpoint } = definition;
  const required = isRequired(definition, preset);
  const help = t.has(`help.${name}`) ? t(`help.${name}`) : null;
  // A translated placeholder when the field has one, falling back to the
  // literal on the definition (the port defaults, which are numbers and the
  // same in every language).
  const hint = t.has(`placeholders.${name}`) ? t(`placeholders.${name}`) : placeholder;

  return (
    <FormField
      control={form.control}
      name={`config.${name}`}
      render={({ field }) => {
        if (kind === TOGGLE) {
          // `field.value` can be undefined on first paint; the declaration's
          // default is applied when the form is reset, so coercing here only
          // guards the gap rather than deciding policy.
          const on = field.value !== false;

          return (
            <FormItem className="flex flex-row items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel>{t(`fields.${name}`)}</FormLabel>
                {help ? <p className="text-xs text-muted-foreground">{help}</p> : null}
                {/*
                  * Shown only when the toggle is off, and worded as a
                  * consequence rather than a setting: "TLS is off" tells
                  * somebody nothing they did not just do, while naming what
                  * travels unencrypted is the reason to reconsider.
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
