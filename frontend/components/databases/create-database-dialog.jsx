import { useEffect, useMemo, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, DatabasePlus, Loader2 } from "lucide-react";
import { createDatabaseSchema, reservedNames } from "@/lib/schemas/database";
import { randomUsername } from "@/lib/databases/random";
import { applicationOptions } from "@/lib/backups/database-availability";
import { acceptedEnginesFor, engineAccepted } from "@/lib/databases/engine-acceptance";
import { createDatabase } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { useRestartConfirm } from "@/components/databases/use-restart-confirm";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { ChoiceField } from "@/components/ui/choice-field";
import { Combobox } from "@/components/ui/combobox";
import { FormModal } from "@/components/ui/form-modal";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from "@/components/ui/form";
import { CreatedCredentials } from "@/components/databases/created-credentials";

// The user is created in the same step: a database without one cannot be connected to.
// Charset and collation are collapsed: correct defaults, and the API rejects mismatched pairs.
export function CreateDatabaseDialog({
  engines = [],
  open,
  onOpenChange,
  // Empty hides the site picker.
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
  // Only used to mark sites that cannot use the chosen engine. Empty blocks nothing.
  siteTypes = [],
  // Opened from a site's page: the picker is hidden but the id is still sent.
  applicationId = null,
}) {
  const t = useTranslations("databases");
  const tEngines = useTranslations("databases.engines");
  const restart = useRestartConfirm();
  const { refreshAndWait } = useRefresh();
  const [advanced, setAdvanced] = useState(false);
  // Set on success: the dialog then shows the credential instead of the form.
  const [created, setCreated] = useState(null);

  const usable = engines.filter((engine) => engine.running);
  const defaultEngine = usable[0]?.engine ?? "";

  const defaults = {
    name: "",
    engine: defaultEngine,
    charset: "",
    collation: "",
    application_id: applicationId ? String(applicationId) : "",
    create_user: true,
    username: "",
    password: "",
    connection_preference: "localhost",
    host: "",
  };

  /* Memoised on the engine rows: a new resolver on every render resets the form. */
  const schema = useMemo(() => createDatabaseSchema(reservedNames(engines)), [engines]);

  const form = useForm({
    resolver: zodResolver(schema),
    // Not onBlur: errors would fire while tabbing through an unfinished form.
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults,
  });

  // After mount and on every open: in the defaults it would cause a hydration mismatch.
  useEffect(() => {
    if (open) form.setValue("username", randomUsername());
  }, [open, form]);

  const values = useWatch({ control: form.control });
  const engine = usable.find((item) => item.engine === values.engine);
  // Defaults to true so an older API without the field keeps the choice.
  const remoteUsers = engine?.supports_remote_users !== false;
  /* PostgreSQL uses ENCODING / LC_COLLATE, so the labels follow the driver. */
  const charsetWording = engine?.driver === "pgsql" ? "pgsqlWords" : "sqlWords";
  const charsets = engine?.charsets ?? {};
  const charsetNames = Object.keys(charsets);
  // A wrong-charset collation is a 422. `_0900_` is MySQL 8 only; the API offers it
  // for MariaDB too, where it fails with a 500.
  const collations = (values.charset ? (charsets[values.charset] ?? []) : []).filter(
    (collation) => engine?.engine !== "mariadb" || !/_0900_/.test(collation),
  );

  async function onSubmit(submitted) {
    const payload = {
      name: submitted.name,
      engine: submitted.engine,
    };
    // Sent only when a site was chosen; omitting the key equals null.
    if (submitted.application_id) {
      payload.application_id = Number(submitted.application_id);
    }
    if (submitted.charset) payload.charset = submitted.charset;
    if (submitted.collation) payload.collation = submitted.collation;

    if (submitted.create_user) {
      payload.create_user = {
        username: submitted.username,
        connection_preference: submitted.connection_preference,
      };
      // Omitted means the API generates one.
      if (submitted.password) payload.create_user.password = submitted.password;
      if (submitted.restart_cluster) payload.create_user.restart_cluster = true;
      if (submitted.connection_preference === "remote") {
        payload.create_user.host = submitted.host;
      }
    }

    try {
      const { data } = await createDatabase(payload);
      await refreshAndWait();
      toast.success(t("create.created", { name: submitted.name }));
      setCreated(data?.database ?? null);
    } catch (error) {
      const restartAnswer = restart.ask(error);
      if (restartAnswer && (await restartAnswer)) return onSubmit({ ...submitted, restart_cluster: true });
      if (!restartAnswer) handleValidationError(withUserFieldErrors(error, form), form);
    }
  }

  function handleOpenChange(next) {
    if (!next) {
      form.reset(defaults);
      setAdvanced(false);
      setCreated(null);
    }
    onOpenChange?.(next);
  }

  const isSubmitting = form.formState.isSubmitting;

  if (created) {
    return (
      <CreatedCredentials
        database={created}
        open={open}
        onOpenChange={handleOpenChange}
      />
    );
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={DatabasePlus}
        title={t("create.title")}
        description={t("create.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("create.creating") : t("create.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required hint={t("create.nameHint")}>{t("create.name")}</FormLabel>
              <FormControl>
                <Input
                  autoComplete="off"
                  spellCheck={false}
                  className="font-mono"
                  placeholder={t("create.namePlaceholder")}
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        {/* A single-option select would read as a required step. */}
        {usable.length > 1 ? (
          <FormField
            control={form.control}
            name="engine"
            render={({ field }) => (
              <FormItem>
                <FormLabel required hint={t("create.engineHint")}>{t("create.engine")}</FormLabel>
                <Select
                  value={field.value}
                  onValueChange={(next) => {
                    field.onChange(next);
                    /* Clear a host choice the new engine cannot honour, or the API refuses a value no
                     * control shows. Here, not in an effect, to avoid a cascading render. */
                    const chosen = usable.find((item) => item.engine === next);
                    if (chosen?.supports_remote_users === false) {
                      form.setValue("connection_preference", "localhost");
                      form.setValue("host", "");
                    }
                  }}
                >
                  <FormControl>
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    {usable.map((item) => (
                      <SelectItem key={item.engine} value={item.engine}>
                        {t(`engines.${item.engine}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            )}
          />
        ) : null}

        {/* Backups dump only databases attached to a site; an unattached one is absent from every backup. */}
        {applications.length > 0 && !applicationId ? (
          <FormField
            control={form.control}
            name="application_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel hint={t("create.applicationHint")}>{t("create.application")}</FormLabel>
                <FormControl>
                  <Combobox
                    value={field.value}
                    onChange={field.onChange}
                    placeholder={t("create.applicationNone")}
                    searchPlaceholder={t("create.applicationSearch")}
                    options={[
                      { value: "", label: t("create.applicationNone") },
                      ...applicationOptions(
                        applications,
                        databaseCounts,
                        databasesKnown,
                        t("create.applicationTaken"),
                        // The API refuses some engine/site pairings.
                        (application) =>
                          engineAccepted({ application, siteTypes, engine: values.engine })
                            ? undefined
                            : t("create.applicationEngine", {
                                engines: acceptedEnginesFor({ application, siteTypes })
                                  .map((name) => (tEngines.has(name) ? tEngines(name) : name))
                                  .join(" / "),
                              }),
                      ),
                    ]}
                    className="w-full"
                  />
                </FormControl>
                {/* The backend requires this hint: linking does not make the site use the database. */}
                <FormMessage />
              </FormItem>
            )}
          />
        ) : null}

        <FormField
          control={form.control}
          name="create_user"
          render={({ field }) => (
            <FormItem className="flex flex-row items-start justify-between gap-4 rounded-lg border p-3">
              <div className="space-y-1">
                <FormLabel hint={t("create.withUserHint")}>{t("create.withUser")}</FormLabel>
              </div>
              <FormControl>
                <Switch
                  checked={field.value}
                  onCheckedChange={field.onChange}
                />
              </FormControl>
            </FormItem>
          )}
        />

        {values.create_user ? (
          <>
            <FormField
              control={form.control}
              name="username"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("create.username")}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder="app_user"
                      autoComplete="off"
                      spellCheck={false}
                      className="font-mono"
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
                        /* A PostgreSQL role has no host, so the API refuses `remote` and `anywhere`.
                         * Read `supports_remote_users`, never the engine name. */
                        ...(remoteUsers
                          ? [
                              {
                                value: "remote",
                                label: t("access.remote.label"),
                                hint: t("access.remote.hint"),
                              },
                              {
                                // Opens the engine port to the whole internet, so it carries an explanation.
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

            {values.connection_preference === "remote" ? (
              <FormField
                control={form.control}
                name="host"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel required hint={t("create.hostHint")}>{t("create.host")}</FormLabel>
                    <FormControl>
                      <Input
                        autoComplete="off"
                        spellCheck={false}
                        className="font-mono"
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
        ) : null}

        {/* Collapsed by default: the defaults are correct and bad pairs are rejected. */}
        {charsetNames.length > 0 ? (
          <Collapsible open={advanced} onOpenChange={setAdvanced}>
            <CollapsibleTrigger asChild>
              <button
                type="button"
                className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                <ChevronDown
                  className={`size-4 transition-transform ${advanced ? "rotate-180" : ""}`}
                />
                {t("create.advanced")}
              </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-4 pt-4">
              <FormField
                control={form.control}
                name="charset"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel hint={t(`create.${charsetWording}.charsetHint`)}>
                      {t(`create.${charsetWording}.charset`)}
                    </FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next);
                        // The old collation belongs to the old charset; that pair is a 422.
                        form.setValue("collation", "");
                      }}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full font-mono data-placeholder:font-sans">
                          <SelectValue placeholder={t("create.defaultOption")} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {charsetNames.map((name) => (
                          <SelectItem key={name} value={name} className="font-mono">
                            {name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="collation"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel hint={t(`create.${charsetWording}.collationHint`)}>
                      {t(`create.${charsetWording}.collation`)}
                    </FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={collations.length === 0}
                        disabledReason={t(`create.${charsetWording}.needsCharset`)}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full font-mono data-placeholder:font-sans">
                          <SelectValue placeholder={t("create.defaultOption")} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {collations.map((name) => (
                          <SelectItem key={name} value={name} className="font-mono">
                            {name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {/* Under the control: as a placeholder it widened the trigger past the dialog edge. */}
                    {!values.charset ? (
                      <FormDescription>{t(`create.${charsetWording}.chooseFirst`)}</FormDescription>
                    ) : null}
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CollapsibleContent>
          </Collapsible>
        ) : null}
      {restart.dialog}
      </FormModal>
    </Form>
  );
}

// The first user is sent nested as `create_user`, so its errors come back as
// `create_user.username` / `.host`; set them on the fields directly.
function withUserFieldErrors(error, form) {
  const errors = error?.response?.data?.errors;
  if (!errors) return error;
  const rest = {};
  for (const [key, messages] of Object.entries(errors)) {
    const field = key.startsWith("create_user.") ? key.slice("create_user.".length) : null;
    if (field && form.getValues(field) !== undefined) form.setError(field, { type: "server", message: messages[0] });
    else rest[key] = messages;
  }
  if (!Object.keys(rest).length) return { ...error, response: { ...error.response, data: { ...error.response.data, errors: {} } } };
  return { ...error, response: { ...error.response, data: { ...error.response.data, errors: rest } } };
}
