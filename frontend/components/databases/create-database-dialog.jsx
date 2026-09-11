import { useEffect, useMemo, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, DatabasePlus, Loader2 } from "lucide-react";
import { createDatabaseSchema, reservedNames } from "@/lib/schemas/database";
import { randomUsername } from "@/lib/databases/random";
import { applicationOptions } from "@/lib/backups/database-availability";
import { acceptedEnginesFor, engineAccepted } from "@/lib/databases/engine-acceptance";
import { createDatabase } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
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

/**
 * Create a database, and the credential that makes it usable.
 *
 * The user is created in the SAME step, opt-out rather than a second errand: a
 * database with no user cannot be connected to by anything, so leaving it off
 * by default builds a trap and calls it a choice.
 *
 * Charset and collation are collapsed. They have correct defaults, the API
 * rejects mismatched pairs, and most people creating a database for an app have
 * no reason to think about either.
 */
export function CreateDatabaseDialog({
  engines = [],
  open,
  onOpenChange,
  // The sites this database could belong to, and which of them already have
  // one. Empty means the picker is not offered at all.
  applications = [],
  databaseCounts = null,
  databasesKnown = false,
  // The catalogue, only so the picker can say which sites cannot speak the
  // engine chosen above. Empty means no pairing is blocked.
  siteTypes = [],
  // Opened from a site's own page: the site is already the answer, so the
  // picker is not a question worth asking. Sent all the same.
  applicationId = null,
}) {
  const t = useTranslations("databases");
  const tEngines = useTranslations("databases.engines");
  const router = useRouter();
  const [advanced, setAdvanced] = useState(false);
  // Set on success. The dialog then shows the credential instead of the form —
  // a closed dialog and a toast leaves you hunting for the connection details
  // you just created.
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

  /*
   * The names the server owns, read from the engines it reported rather than
   * from a list compiled into this bundle. Memoised on the engine rows because
   * a new resolver on every render resets the form.
   */
  const schema = useMemo(() => createDatabaseSchema(reservedNames(engines)), [engines]);

  const form = useForm({
    resolver: zodResolver(schema),
    // Not onBlur: tabbing from an empty Database name into Username marked the
    // name invalid before anyone had finished filling the form in. Errors wait
    // for a submit attempt, then clear as each one is fixed.
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults,
  });

  // Generated after mount and refreshed every time the dialog opens: doing it
  // in the defaults would render a different value on the server than in the
  // browser, and would reuse one name for every database created in a session.
  useEffect(() => {
    if (open) form.setValue("username", randomUsername());
  }, [open, form]);

  const values = useWatch({ control: form.control });
  const engine = usable.find((item) => item.engine === values.engine);
  // Defaults true so an older API — which sends no such field — keeps offering
  // the choice rather than hiding a control that works.
  const remoteUsers = engine?.supports_remote_users !== false;
  /*
   * Which pair of words this engine uses for the two selects.
   *
   * PostgreSQL has neither a "character set" nor a "collation": it has an
   * ENCODING and an LC_COLLATE, and its values say so — `UTF8` and `C.UTF-8`,
   * not `utf8mb4` and `utf8mb4_unicode_ci`. Labelling those "Character set"
   * and "Collation" asks someone to match a documented name against a word
   * their database has never used.
   *
   * Keyed on the driver rather than the engine name, like everything else
   * here. The lists themselves come from the API and already differ.
   */
  const charsetWording = engine?.driver === "pgsql" ? "pgsqlWords" : "sqlWords";
  const charsets = engine?.charsets ?? {};
  const charsetNames = Object.keys(charsets);
  // A collation from the wrong charset is a 422, so the second list is always
  // derived from the first rather than offering everything.
  const collations = values.charset ? (charsets[values.charset] ?? []) : [];

  async function onSubmit(submitted) {
    const payload = {
      name: submitted.name,
      engine: submitted.engine,
    };
    // Sent only when a site was chosen. The API takes null for "no site", but
    // omitting the key entirely is the same thing and keeps the request honest
    // about what the form actually asked for.
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
      // Omitted means the API generates one, which is better than anything a
      // person types in a hurry.
      if (submitted.password) payload.create_user.password = submitted.password;
      if (submitted.connection_preference === "remote") {
        payload.create_user.host = submitted.host;
      }
    }

    try {
      const { data } = await createDatabase(payload);
      toast.success(t("create.created", { name: submitted.name }));
      setCreated(data?.database ?? null);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
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

  // Step two: what was made, and how to connect to it.
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
              <FormLabel required>{t("create.name")}</FormLabel>
              <FormControl>
                <Input
                  autoComplete="off"
                  spellCheck={false}
                  className="font-mono"
                  placeholder={t("create.namePlaceholder")}
                  {...field}
                />
              </FormControl>
              {/* Says what IS allowed. "Invalid name" makes people guess. */}
              <p className="text-xs text-muted-foreground">
                {t("create.nameHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />

        {/* One engine is not a choice, and a select with a single option reads
            as a required step. */}
        {usable.length > 1 ? (
          <FormField
            control={form.control}
            name="engine"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("create.engine")}</FormLabel>
                <Select
                  value={field.value}
                  onValueChange={(next) => {
                    field.onChange(next);
                    /*
                     * Clear a preference the new engine cannot honour. Picking
                     * "remote" on MariaDB and then switching to PostgreSQL
                     * left `remote` in the form with no control showing it —
                     * an invisible value the API then refuses. Reset at the
                     * point of change rather than in an effect, which would be
                     * the cascading render the lint rule refuses.
                     */
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

        {/* Which site this database belongs to.
            Optional, and the reason it exists: backups dump exactly the
            databases attached to a site, so one created here with no site is
            absent from every backup — silently, and with nothing on any screen
            that would say so. */}
        {applications.length > 0 && !applicationId ? (
          <FormField
            control={form.control}
            name="application_id"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t("create.application")}</FormLabel>
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
                        // Reads the engine chosen above, so switching engine
                        // re-answers this — the pairing the API refuses
                        // depends on both halves.
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
                {/* The backend asks for this sentence in as many words: someone
                    who links a database expecting the site to start using it
                    has been misled. */}
                <FormDescription>{t("create.applicationHint")}</FormDescription>
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
                <FormLabel>{t("create.withUser")}</FormLabel>
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {t("create.withUserHint")}
                </p>
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
                         * Only where the engine can honour them. A PostgreSQL
                         * role is cluster-wide and carries no host, so the API
                         * refuses `remote` and `anywhere` there — offering
                         * them would collect a 422 after the choice.
                         *
                         * Read from `supports_remote_users` on the engine row,
                         * never from its name: the backend publishes the fact
                         * precisely so this file does not have to know which
                         * engine PostgreSQL is.
                         */
                        ...(remoteUsers
                          ? [
                              {
                                value: "remote",
                                label: t("access.remote.label"),
                                hint: t("access.remote.hint"),
                              },
                              {
                                // Opens the engine port to every address on
                                // the internet. That is a sentence people
                                // should read before choosing it, not discover
                                // in the firewall.
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
                    <FormLabel required>{t("create.host")}</FormLabel>
                    <FormControl>
                      <Input
                        autoComplete="off"
                        spellCheck={false}
                        className="font-mono"
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
        ) : null}

        {/* Correct defaults, an API that rejects bad pairs, and no reason for
            most people to look — so it starts closed. */}
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
                    <FormLabel>{t(`create.${charsetWording}.charset`)}</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next);
                        // The old collation almost certainly belongs to the old
                        // charset, and sending that pair is a 422.
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
                    <FormLabel>{t(`create.${charsetWording}.collation`)}</FormLabel>
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
                    {/* Why it is disabled goes under the control, not inside it.
                        As a placeholder this sentence set the trigger's minimum
                        width and pushed it past the dialog's edge at larger text
                        sizes — and a hint no one can finish reading is not a
                        hint. */}
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
      </FormModal>
    </Form>
  );
}
