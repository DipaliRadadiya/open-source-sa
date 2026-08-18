"use client";

import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ChevronDown, DatabasePlus, Loader2 } from "lucide-react";
import { createDatabaseSchema } from "@/lib/schemas/database";
import { randomUsername } from "@/lib/databases/random";
import { createDatabase } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { ChoiceField } from "@/components/ui/choice-field";
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
export function CreateDatabaseDialog({ engines = [], open, onOpenChange }) {
  const t = useTranslations("databases");
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
    create_user: true,
    username: "",
    password: "",
    connection_preference: "localhost",
    host: "",
  };

  const form = useForm({
    resolver: zodResolver(createDatabaseSchema),
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
                <Select value={field.value} onValueChange={field.onChange}>
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
                        {
                          value: "remote",
                          label: t("access.remote.label"),
                          hint: t("access.remote.hint"),
                        },
                        {
                          // Opens the engine port to every address on the
                          // internet. That is a sentence people should read
                          // before choosing it, not discover in the firewall.
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
                    <FormLabel>{t("create.charset")}</FormLabel>
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
                    <FormLabel>{t("create.collation")}</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={collations.length === 0}
                        disabledReason={t("create.needsCharset")}
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
                      <FormDescription>{t("create.chooseCharsetFirst")}</FormDescription>
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
