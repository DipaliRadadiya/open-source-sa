"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import {
  ArrowRight,
  CheckCircle2,
  ChevronDown,
  CircleAlert,
  Loader2,
  Sparkles,
  TriangleAlert,
  UserPlus,
  Wand2,
} from "lucide-react";
import { toast } from "sonner";
import {
  createApplicationSchema,
  portCheckResponseSchema,
} from "@/lib/schemas/application";
import {
  branchesResponseSchema,
  repositoriesResponseSchema,
} from "@/lib/schemas/git";
import {
  createApplication,
  checkApplicationPort,
  getBranches,
  getRepositories,
} from "@/lib/api/applications";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { useBranding } from "@/components/branding-provider";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { PasswordInput } from "@/components/ui/password-input";
import { CopyButton } from "@/components/ui/copy-button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils";
import { ChoiceField } from "@/components/ui/choice-field";
import { ipToLabel, temporaryDomain } from "@/lib/applications/temporary-domain";
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
import { Combobox } from "@/components/ui/combobox";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";
import { Switch } from "@/components/ui/switch";
import { SiteTypePicker } from "@/components/applications/site-type-picker";
import { CreateReadinessPanel } from "@/components/applications/create-readiness-panel";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";

const COMMON_FIELD_NAMES = new Set([
  "site_type",
  "name",
  "domain",
  "system_user_id",
  "git_source",
  "git_account_id",
  "repository",
  "repository_url",
  "branch",
]);

// Declared by the API for WordPress, but not a decision to put in front of
// anyone creating a site: by its own help text it does nothing unless the
// server runs OpenLiteSpeed, and a plugin choice belongs in the site, not in
// the form that creates it. Dropped rather than defaulted, so nothing is sent
// and the backend applies its own `false`.
const HIDDEN_FIELD_NAMES = new Set(["install_litespeed_cache_plugin"]);

// The API is meant to send a display-ready `label`, but for some one-click app
// fields it returns the untranslated key itself (`application.fields.shop_name`)
// when the backend has no translation for it. Never show a raw key to a user —
// humanise the field name instead (shop_name -> "Shop name"). A real label with
// spaces is left untouched.
function fieldLabel(config) {
  const label = config.label;
  const looksLikeKey = !label || /^[a-z0-9_]+(\.[a-z0-9_]+)+$/i.test(label);
  if (!looksLikeKey) return label;
  const source = config.name || label.split(".").pop() || "";
  const words = source.replace(/[._]+/g, " ").trim();
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : label;
}

/**
 * A toggle's value as a real boolean.
 *
 * The backend declares defaults as JSON, so a toggle can arrive as `false`, as
 * the string `"false"`, or as `0`. Plain `Boolean()` is wrong for two of those —
 * `Boolean("false")` is `true`, which drew the switch ON while the field still
 * held a string the API rejects with "must be true or false".
 */
function toggleValue(value) {
  if (typeof value === "string") return !["", "0", "false"].includes(value.trim().toLowerCase());
  return Boolean(value);
}


function SectionHeading({ number, title, description }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-semibold text-muted-foreground">
        {number}
      </span>
      <div className="space-y-0.5">
        <h2 className="text-base font-semibold tracking-tight">{title}</h2>
        <p className="text-sm leading-5 text-muted-foreground">{description}</p>
      </div>
    </div>
  );
}

function PickerStatus({ state, messages }) {
  if (state === "loading")
    return <FormDescription>{messages.loading}</FormDescription>;
  if (state === "empty")
    return <FormDescription>{messages.empty}</FormDescription>;
  if (state === "error")
    return (
      <FormDescription className="text-destructive">
        {messages.error}
      </FormDescription>
    );
  return null;
}

// The app_port is the one field a user can get wrong in a way that only shows up
// when provisioning fails. The API answers three ways — free, a registered name
// (a warning, not a block), or taken — so we ask as they type instead of after.
function PortField({ field, config, placeholder }) {
  const t = useTranslations("applications");
  const [check, setCheck] = useState(null); // { state, message, suggested }

  useEffect(() => {
    const raw = String(field.value ?? "").trim();
    const port = Number(raw);
    const valid =
      Boolean(raw) && Number.isInteger(port) && port >= 1024 && port <= 65535;
    let cancelled = false;
    // Every state write lives in the deferred callback, never synchronously in
    // the effect body (react-hooks/set-state-in-effect).
    const id = setTimeout(
      () => {
        if (cancelled) return;
        if (!valid) {
          setCheck(null);
          return;
        }
        setCheck({ state: "checking" });
        checkApplicationPort(port)
          .then(({ data }) => {
            if (cancelled) return;
            const parsed = portCheckResponseSchema.safeParse(data);
            if (!parsed.success) return setCheck(null);
            const r = parsed.data.port_check;
            setCheck({
              state: r.available ? (r.reason ? "warn" : "free") : "taken",
              message:
                r.message ??
                (r.available ? t("form.portFree", { port }) : null),
              suggested: r.suggested_port ?? null,
            });
          })
          .catch(() => {
            if (!cancelled) setCheck(null);
          });
      },
      valid ? 500 : 0,
    );
    return () => {
      cancelled = true;
      clearTimeout(id);
    };
  }, [field.value, t]);

  return (
    <>
      <FormControl>
        <Input
          type="number"
          inputMode="numeric"
          placeholder={placeholder}
          {...field}
          value={field.value ?? ""}
        />
      </FormControl>
      {check?.state === "checking" ? (
        <FormDescription className="flex items-center gap-1.5">
          <Loader2 className="size-3 animate-spin" />
          {t("form.portChecking")}
        </FormDescription>
      ) : check?.state === "free" ? (
        <FormDescription className="flex items-center gap-1.5 text-success">
          <CheckCircle2 className="size-3.5" />
          {check.message}
        </FormDescription>
      ) : check?.state === "warn" ? (
        <FormDescription className="flex items-start gap-1.5 text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {check.message}
        </FormDescription>
      ) : check?.state === "taken" ? (
        <FormDescription className="flex flex-wrap items-center gap-x-2 gap-y-1 text-destructive">
          <span className="flex items-start gap-1.5">
            <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
            {check.message}
          </span>
          {check.suggested ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="h-6 px-2 text-xs"
              onClick={() => field.onChange(String(check.suggested))}
            >
              {t("form.portUseSuggested", { port: check.suggested })}
            </Button>
          ) : null}
        </FormDescription>
      ) : config.help ? (
        <FormDescription>{config.help}</FormDescription>
      ) : null}
    </>
  );
}

// The start command is executed directly, not through a shell — the backend
// refuses package managers and shell syntax with a 422. Say so as they type.
function startCommandProblem(value) {
  const v = String(value ?? "").trim();
  if (!v) return null;
  if (/[&|;]|\$\(|[<>]/.test(v)) return "shell";
  if (/^(npm|yarn|pnpm|bun|npx)\b/.test(v)) return "packageManager";
  return null;
}

/**
 * One line for the review panel.
 *
 * Multi-line values (a deploy script) get their first line plus a count of what
 * follows — collapsing them into a single run of text looks like the newlines
 * were eaten, which is exactly the bug this field used to have.
 */
function summariseValue(value, t) {
  const lines = value.split("\n").filter((line) => line.trim());
  if (lines.length <= 1) return value;
  return `${lines[0]} ${t("form.moreLines", { count: lines.length - 1 })}`;
}

function ConfigField({
  config,
  form,
  accounts,
  phpVersions,
  phpVersionsFailed,
  nodeVersions,
  nodeVersionsFailed,
  timezones,
}) {
  const t = useTranslations("applications");
  const isAccount = config.source === "git_accounts";
  // Memoised because the `[]` branch is a fresh array every render, which would
  // re-run everything downstream that depends on it.
  const runtimeVersions = useMemo(
    () =>
      config.source === "php_versions"
        ? phpVersions
        : config.source === "node_versions"
          ? nodeVersions
          : [],
    [config.source, phpVersions, nodeVersions],
  );
  const runtimeFailed =
    config.source === "php_versions"
      ? phpVersionsFailed
      : config.source === "node_versions"
        ? nodeVersionsFailed
        : false;
  const isRuntime =
    config.source === "php_versions" || config.source === "node_versions";
  const isTimezone =
    config.source === "timezones" ||
    config.name === "timezone" ||
    config.name === "site_timezone" ||
    config.label?.toLowerCase().includes("time zone");
  const isPassword = config.type === "password";
  const isPort = config.name === "app_port";
  const isStartCommand = config.name === "start_command";
  const isToggle = config.type === "toggle";
  /**
   * Declared by the API for anything multi-line.
   *
   * Without this branch the field fell through to a single-line `<input>`, and
   * a shell script pasted into one loses every newline — silently, so the site
   * deploys with one mangled line.
   *
   * It also takes the full row of the two-column grid: a script in half the
   * width soft-wraps every real command, so half the lines you read are not
   * lines you wrote.
   */
  const isTextarea = config.type === "textarea";
  // `GitDeployer::script()` runs the deploy script when there is one and falls
  // back to build_command otherwise. Both fields sit in the same Advanced
  // section, so filling both is easy and the loser goes quiet — the API's own
  // hint says so, but it lives under the OTHER field, which nobody re-reads.
  const deployScript = useWatch({ control: form.control, name: "deploy_script" });
  const supersededByDeployScript =
    config.name === "build_command" && String(deployScript ?? "").trim() !== "";
  // A field the backend declares as a choice — render a chooser even before its
  // options arrive, so it never silently degrades to a free-text box.
  const isChoice = ["select", "enum", "dropdown"].includes(config.type);
  const [reveal, setReveal] = useState(false);
  // Unique by value, always. Two options sharing a value make Radix's trigger
  // render BOTH items' text — "8.4" twice reads as "8.48.4" — and they collide
  // on the React key as well. Cheap to guarantee here rather than trusting
  // every caller and every API list to be clean.
  const options = useMemo(() => {
    const raw = config.options?.length
      ? config.options
      : runtimeVersions.map((version) => ({
          value: version.version,
          label: version.version,
        }));
    const seen = new Set();
    return raw.filter((option) => {
      const key = String(option.value);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }, [config.options, runtimeVersions]);
  const runtimeDefault = isRuntime
    ? (options.find((option) => option.is_default)?.value ?? options[0]?.value)
    : undefined;
  // Timezones: flatten the grouped API response into a flat option list.
  const timezoneOptions = useMemo(() => {
    if (!isTimezone || !timezones?.length) return [];
    return timezones.flatMap((group) =>
      group.zones.map((zone) => ({
        value: zone.value,
        label: zone.offset ? `${zone.label} (${zone.offset})` : zone.label,
      })),
    );
  }, [isTimezone, timezones]);
  const isChooser =
    options.length > 0 || isRuntime || isChoice || isTimezone;
  // Long enumerations (countries ~250, timezones ~400, languages) get a
  // searchable Combobox per the house rule; short lists stay a plain Select.
  const useCombobox = (isTimezone ? timezoneOptions.length : options.length) > 10;
  const label = fieldLabel(config);
  const placeholder =
    config.placeholder ?? t("form.fieldPlaceholder", { field: label });

  return (
    <FormField
      control={form.control}
      name={config.name}
      defaultValue={runtimeDefault}
      render={({ field }) => (
        <FormItem className={cn("min-w-0 self-start", isTextarea && "md:col-span-2")}>
          {/* Fixed row height so the input below starts at the same Y whether or not
        the label carries Generate/copy actions — otherwise a field with them
        sits lower than its neighbour in the two-column grid. */}
          <div className="flex min-h-6 items-center justify-between gap-2">
            {/* Truncate rather than wrap: a wrapped label is taller and drops the
          input below its neighbour in the two-column grid on narrow widths. */}
            <FormLabel className="min-w-0 truncate" required={config.required}>
              {label}
            </FormLabel>
            <div className="flex shrink-0 items-center gap-2">
              {/* A generated password is shown once and never again — a copy control
            beside it means it can be saved without hand-selecting the field. */}
              {isPassword && field.value ? (
                <CopyButton value={String(field.value)} className="size-6" />
              ) : null}
              {/* Fields the schema marks generatable (WordPress admin password, DB
            passwords) get a one-click strong value, revealed so it can be
            copied before it is submitted. */}
              {isPassword && config.generate ? (
                <button
                  type="button"
                  onClick={() => {
                    form.setValue(config.name, generatePassword(), {
                      shouldDirty: true,
                      shouldValidate: true,
                    });
                    setReveal(true);
                  }}
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  <Wand2 className="size-3" />
                  {t("form.generate")}
                </button>
              ) : null}
            </div>
          </div>
          {isAccount ? (
            <Select
              onValueChange={field.onChange}
              value={field.value ? String(field.value) : ""}
            >
              <FormControl>
                <SelectTrigger className="w-full">
                  <SelectValue placeholder={t("gitAccountPlaceholder")} />
                </SelectTrigger>
              </FormControl>
              <SelectContent className="max-h-64">
                {accounts.map((account) => (
                  <SelectItem key={account.id} value={String(account.id)}>
                    {account.label} · {account.provider_title}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : isTimezone && timezoneOptions.length ? (
            useCombobox ? (
              <FormControl>
                <Combobox
                  options={timezoneOptions}
                  value={field.value ? String(field.value) : ""}
                  onChange={field.onChange}
                  placeholder={t("form.fieldSelectPlaceholder", {
                    field: label,
                  })}
                />
              </FormControl>
            ) : (
              <Select
                onValueChange={field.onChange}
                value={field.value ? String(field.value) : ""}
              >
                <FormControl>
                  <SelectTrigger className="w-full">
                    <SelectValue
                      placeholder={t("form.fieldSelectPlaceholder", {
                        field: label,
                      })}
                    />
                  </SelectTrigger>
                </FormControl>
                <SelectContent className="max-h-64">
                  {timezoneOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )
          ) : isChooser ? (
            useCombobox ? (
              <FormControl>
                <Combobox
                  options={options}
                  value={field.value ? String(field.value) : ""}
                  onChange={field.onChange}
                  placeholder={t("form.fieldSelectPlaceholder", {
                    field: label,
                  })}
                  disabled={!options.length}
                />
              </FormControl>
            ) : (
              <Select
                onValueChange={field.onChange}
                value={field.value ? String(field.value) : ""}
                disabled={!options.length}
              >
                <FormControl>
                  <SelectTrigger className="w-full">
                    <SelectValue
                      placeholder={
                        options.length
                          ? t("form.fieldSelectPlaceholder", { field: label })
                          : t("form.noOptions")
                      }
                    />
                  </SelectTrigger>
                </FormControl>
                <SelectContent className="max-h-64">
                  {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )
          ) : isToggle ? (
            <FormControl>
              <div className="flex items-center gap-2">
                <Switch
                  checked={toggleValue(field.value)}
                  onCheckedChange={(checked) => field.onChange(checked)}
                  id={config.name}
                />
                <FormDescription className="!mt-0">
                  {toggleValue(field.value) ? t("form.toggleOn") : t("form.toggleOff")}
                </FormDescription>
              </div>
            </FormControl>
          ) : isPort ? (
            <PortField
              field={field}
              config={config}
              placeholder={placeholder}
            />
          ) : isPassword ? (
            <FormControl>
              <PasswordInput
                placeholder={placeholder}
                show={reveal}
                onShowChange={setReveal}
                {...field}
                value={field.value ?? ""}
              />
            </FormControl>
          ) : isTextarea ? (
            <FormControl>
              <Textarea
                rows={6}
                spellCheck={false}
                placeholder={placeholder}
                // Mono: every textarea field the API declares today is a
                // command or a script, where alignment and a literal space
                // carry meaning.
                className="font-mono text-xs"
                {...field}
                value={field.value ?? ""}
              />
            </FormControl>
          ) : (
            <FormControl>
              <Input
                type={config.type === "number" ? "number" : "text"}
                placeholder={placeholder}
                {...field}
                value={field.value ?? ""}
              />
            </FormControl>
          )}
          {isPort ? null : runtimeFailed ? (
            <FormDescription className="text-destructive">
              {t("loadFailed")}
            </FormDescription>
          ) : isStartCommand && startCommandProblem(field.value) ? (
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t(`form.startCommand.${startCommandProblem(field.value)}`)}
            </FormDescription>
          ) : supersededByDeployScript ? (
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.buildCommandSuperseded")}
            </FormDescription>
          ) : config.help ? (
            <FormDescription>{config.help}</FormDescription>
          ) : null}
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

export function CreateApplicationForm({
  siteTypes = [],
  systemUsers = [],
  systemUsersFailed = false,
  canCreateSystemUser = false,
  gitAccounts = [],
  gitAccountsFailed = false,
  phpVersions = [],
  phpDefaultVersion = null,
  phpVersionsFailed = false,
  nodeVersions = [],
  nodeDefaultVersion = null,
  nodeVersionsFailed = false,
  serverIp = null,
  temporaryDomainSuffixes = [],
  timezones = [],
}) {
  const t = useTranslations("applications");
  const { name: brand } = useBranding();
  const router = useRouter();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  const [repositoriesState, setRepositoriesState] = useState("idle");
  const [branchesState, setBranchesState] = useState("idle");
  const [systemUserDialogOpen, setSystemUserDialogOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  // Bumped to ask for a scroll; the effect runs after the reveal has committed.
  const [scrollRequest, setScrollRequest] = useState(0);
  const formRef = useRef(null);
  const [createdSystemUsers, setCreatedSystemUsers] = useState([]);
  const form = useForm({
    resolver: zodResolver(createApplicationSchema),
    mode: "onBlur",
    reValidateMode: "onChange",
    defaultValues: {
      site_type: "",
      name: "",
      domain: "",
      system_user_id: "",
      git_account_id: "",
      repository: "",
      branch: "",
    },
  });
  const values = useWatch({ control: form.control });
  const selectedName = useWatch({ control: form.control, name: "site_type" });
  const gitAccountId = useWatch({
    control: form.control,
    name: "git_account_id",
  });
  const repository = useWatch({ control: form.control, name: "repository" });
  const renderingType = useWatch({
    control: form.control,
    name: "rendering_type",
  });
  const packageManager = useWatch({
    control: form.control,
    name: "package_manager",
  });
  const name = useWatch({ control: form.control, name: "name" });
  const domain = useWatch({ control: form.control, name: "domain" });

  /**
   * Somewhere to put a site that has no domain yet.
   *
   * Offered only when the server reported an address — the wildcard-DNS host
   * needs one to point at, and an option that cannot produce a domain is worse
   * than no option. `own` stays the default, so anyone who has a domain sees
   * exactly what they saw before.
   */
  const canUseTemporary = Boolean(ipToLabel(serverIp));
  const [domainMode, setDomainMode] = useState("own");
  const temporary = domainMode === "temporary";
  const generated = temporary
    ? temporaryDomain(name, serverIp, { suffixes: temporaryDomainSuffixes })
    : null;

  // The generated value IS the field: written through so validation, the
  // summary panel and the submitted payload all read one source.
  useEffect(() => {
    if (!temporary) return;
    const next = generated ?? "";
    if (form.getValues("domain") !== next) {
      form.setValue("domain", next, { shouldValidate: Boolean(next) });
    }
  }, [temporary, generated, form]);
  const systemUserId = useWatch({
    control: form.control,
    name: "system_user_id",
  });
  const branch = useWatch({ control: form.control, name: "branch" });
  const phpVersion = useWatch({ control: form.control, name: "php_version" });
  const nodeVersion = useWatch({ control: form.control, name: "node_version" });
  const selected = useMemo(
    () => siteTypes.find((type) => type.name === selectedName),
    [siteTypes, selectedName],
  );
  const isGit = selected?.method === "git" || selected?.name === "git";
  const typeFields = (selected?.fields ?? []).filter(
    (config) =>
      !COMMON_FIELD_NAMES.has(config.name) && !HIDDEN_FIELD_NAMES.has(config.name),
  );
  const visibleFields = typeFields.filter(
    (config) =>
      (config.depends_on !== "rendering_type" || renderingType === "ssr") &&
      (config.depends_on !== "node_rendering" ||
        ["ssr", "csr"].includes(renderingType)),
  );
  const standardFields = visibleFields.filter((config) => !config.advanced);
  const advancedFields = visibleFields.filter((config) => config.advanced);
  const advancedFieldNames = new Set(advancedFields.map((config) => config.name));
  const advancedErrorCount = advancedFields.filter(
    (config) => form.formState.errors[config.name],
  ).length;
  const availableSystemUsers = [
    ...systemUsers,
    ...createdSystemUsers.filter(
      (created) => !systemUsers.some((user) => user.id === created.id),
    ),
  ];
  const runtimeSummaryItems = visibleFields
    .filter(
      (config) =>
        config.source === "php_versions" || config.source === "node_versions",
    )
    .map((config) => {
      const versions =
        config.source === "php_versions" ? phpVersions : nodeVersions;
      const value =
        values?.[config.name] ??
        form.getValues(config.name) ??
        versions[0]?.version;
      return value
        ? {
            key: `runtime-${config.name}`,
            label: fieldLabel(config),
            value: String(value),
            ready: true,
          }
        : null;
    })
    .filter(Boolean);
  // A deploy script makes the build command dead weight, so the last thing
  // read before pressing Create must not list it as set and ready.
  const hasDeployScript = String(values?.deploy_script ?? "").trim() !== "";
  const advancedSummaryItems = advancedFields
    .filter(
      (config) =>
        !(config.name === "build_command" && hasDeployScript) &&
        (config.type === "toggle" || String(values?.[config.name] ?? "").trim()),
    )
    .map((config) => ({
      key: `advanced-${config.name}`,
      label: fieldLabel(config),
      // A raw `false` under a switch labelled "Enabled" is a contradiction —
      // the review reads back the same words the control shows.
      value:
        config.type === "toggle"
          ? toggleValue(values?.[config.name])
            ? t("form.toggleOn")
            : t("form.toggleOff")
          : // A script joined onto one line reads as though the newlines were
            // lost. Say how many lines there are instead of pretending.
            summariseValue(String(values[config.name]), t),
      ready: true,
    }));
  const readinessItems = [
    {
      key: "type",
      label: t("chooseType"),
      value: selected?.title ?? t("form.chooseTypeHint"),
      ready: Boolean(selected),
    },
    {
      key: "name",
      label: t("name"),
      value: name || "—",
      ready: Boolean(name?.trim()),
    },
    {
      key: "domain",
      label: t("domain"),
      value: domain || "—",
      ready: Boolean(domain?.trim()),
    },
    {
      key: "user",
      label: t("systemUser"),
      value:
        availableSystemUsers.find(
          (user) => String(user.id) === String(systemUserId),
        )?.username ?? "—",
      ready: Boolean(systemUserId),
    },
    ...(isGit
      ? [
          {
            key: "source",
            label: t("sourceLabel"),
            value:
              gitSource === "account"
                ? [
                    gitAccounts.find(
                      (account) => String(account.id) === String(gitAccountId),
                    )?.label,
                    repository,
                    branch,
                  ]
                    .filter(Boolean)
                    .join(" · ") || "—"
                : [form.getValues("repository_url"), branch]
                    .filter(Boolean)
                    .join(" · ") || "—",
            ready:
              gitSource === "account"
                ? Boolean(gitAccountId && repository && branch)
                : Boolean(form.getValues("repository_url") && branch),
          },
        ]
      : []),
    ...runtimeSummaryItems,
    ...advancedSummaryItems,
  ];

  function handleGitAccountChange(value) {
    form.setValue("git_account_id", value);
    form.setValue("repository", "");
    form.setValue("branch", "");
    setRepositories([]);
    setBranches([]);
    setRepositoriesState(value ? "loading" : "idle");
    setBranchesState("idle");
  }

  function handleRepositoryChange(value) {
    form.setValue("repository", value);
    form.setValue("branch", "");
    setBranches([]);
    setBranchesState(value ? "loading" : "idle");
  }

  useEffect(() => {
    if (!selected) return;
    const phpField = selected.fields?.find(
      (field) => field.source === "php_versions",
    );
    const nodeField = selected.fields?.find(
      (field) => field.source === "node_versions",
    );
    if (phpField && !form.getValues(phpField.name)) {
      const candidates = [
        phpDefaultVersion,
        phpVersions.find((item) => item.is_default)?.version,
        phpVersions[0]?.version,
      ];
      const version = candidates.find(
        (candidate) =>
          candidate && phpVersions.some((item) => item.version === candidate),
      );
      if (version)
        form.setValue(phpField.name, version, {
          shouldDirty: true,
          shouldValidate: true,
        });
    }
    if (nodeField && !form.getValues(nodeField.name)) {
      const candidates = [
        nodeDefaultVersion,
        nodeVersions.find((item) => item.is_default)?.version,
        nodeVersions[0]?.version,
      ];
      const version = candidates.find(
        (candidate) =>
          candidate && nodeVersions.some((item) => item.version === candidate),
      );
      if (version)
        form.setValue(nodeField.name, version, {
          shouldDirty: true,
          shouldValidate: true,
        });
    }
    // Pre-fill declared defaults (web_root "/web", admin_username "admin", …) so
    // a required field that has a default isn't shown empty with a "Defaults to
    // …" hint the user then has to retype. Passwords and the runtime selects are
    // handled elsewhere; common fields are separate inputs.
    for (const field of selected.fields ?? []) {
      if (
        COMMON_FIELD_NAMES.has(field.name) ||
        HIDDEN_FIELD_NAMES.has(field.name) ||
        field.type === "password" ||
        field.source === "php_versions" ||
        field.source === "node_versions" ||
        field.default == null ||
        field.default === "" ||
        form.getValues(field.name)
      )
        continue;
      // Keep the declared type. Stringifying a toggle's default turned `false`
      // into `"false"` — a value the switch reads as ON and the API rejects.
      const value =
        field.type === "toggle"
          ? toggleValue(field.default)
          : field.type === "number"
            ? Number(field.default)
            : String(field.default);
      form.setValue(field.name, value, {
        shouldDirty: false,
        shouldValidate: true,
      });
    }
  }, [
    form,
    nodeDefaultVersion,
    nodeVersions,
    phpDefaultVersion,
    phpVersions,
    selected,
  ]);

  // A starting point, not a policy: switching package manager fills in the
  // matching install+build commands, but only while build_command is still
  // untouched — the moment the user edits it themselves, their text wins and
  // changing the dropdown again must not clobber it out from under them.
  useEffect(() => {
    if (!packageManager || form.getValues("build_command")) return;
    const field = selected?.fields?.find(
      (item) => item.name === "package_manager",
    );
    const template = field?.build_templates?.[packageManager];
    if (template) {
      form.setValue("build_command", template, {
        shouldDirty: true,
        shouldValidate: true,
      });
    }
  }, [form, packageManager, selected]);

  useEffect(() => {
    let cancelled = false;
    if (!isGit || gitSource !== "account" || !gitAccountId) return undefined;

    getRepositories(gitAccountId, { per_page: 100 })
      .then(({ data }) => {
        const parsed = repositoriesResponseSchema.safeParse(data);
        if (!parsed.success) throw new Error("Invalid repository response");
        if (cancelled) return;
        setRepositories(parsed.data.repositories);
        setRepositoriesState(
          parsed.data.repositories.length ? "ready" : "empty",
        );
      })
      .catch(() => {
        if (!cancelled) setRepositoriesState("error");
      });

    return () => {
      cancelled = true;
    };
  }, [form, gitAccountId, gitSource, isGit]);

  useEffect(() => {
    let cancelled = false;
    if (!isGit || gitSource !== "account" || !gitAccountId || !repository)
      return undefined;

    getBranches(gitAccountId, repository)
      .then(({ data }) => {
        const parsed = branchesResponseSchema.safeParse(data);
        if (!parsed.success) throw new Error("Invalid branch response");
        if (cancelled) return;
        setBranches(parsed.data.branches);
        setBranchesState(parsed.data.branches.length ? "ready" : "empty");
        const defaultBranch = repositories.find(
          (item) => item.full_name === repository,
        )?.default_branch;
        if (
          defaultBranch &&
          parsed.data.branches.some((item) => item.name === defaultBranch)
        )
          form.setValue("branch", defaultBranch);
      })
      .catch(() => {
        if (!cancelled) setBranchesState("error");
      });

    return () => {
      cancelled = true;
    };
  }, [form, gitAccountId, gitSource, isGit, repository, repositories]);

  /**
   * Bring a failed submit into view — including when it failed inside the
   * collapsed Advanced section.
   *
   * A rejected `table_prefix` under a closed disclosure is the worst possible
   * feedback: the button appears to do nothing and there is nothing on screen
   * to read. So open the section first, and only scroll once React has
   * actually mounted those fields (Radix unmounts collapsed content, so
   * scrolling in the same tick finds nothing).
   */
  function revealErrors(names = []) {
    if (names.some((name) => advancedFieldNames.has(name))) setAdvancedOpen(true);
    setScrollRequest((count) => count + 1);
  }

  function onInvalidSubmit(errors) {
    revealErrors(Object.keys(errors ?? {}));
  }

  useEffect(() => {
    if (!scrollRequest) return;
    scrollToFirstError(formRef.current);
  }, [scrollRequest, advancedOpen]);

  async function onSubmit(values) {
    const missingFields = visibleFields.filter(
      (config) => config.required && !String(values[config.name] ?? "").trim(),
    );
    const missingGitFields =
      isGit && gitSource === "account"
        ? [
            { name: "git_account_id", label: t("gitAccount") },
            { name: "repository", label: t("repository") },
          ].filter((field) => !String(values[field.name] ?? "").trim())
        : isGit && !String(values.repository_url ?? "").trim()
          ? [{ name: "repository_url", label: t("publicRepository") }]
          : [];
    if (missingFields.length || missingGitFields.length) {
      [...missingFields, ...missingGitFields].forEach((field) =>
        form.setError(field.name, {
          type: "manual",
          message: t("form.requiredField", { field: field.label }),
        }),
      );
      revealErrors([...missingFields, ...missingGitFields].map((field) => field.name));
      return;
    }
    const payload = {
      site_type: values.site_type,
      name: values.name.trim(),
      domain: values.domain.trim(),
      system_user_id: Number(values.system_user_id),
    };
    // Every field the chosen type declares is validated at the TOP LEVEL on
    // create — the backend generates the rules from that same schema, so a
    // WordPress admin_email or a Node-RED admin_username is a top-level key.
    // `settings` is only a merge bag on the UPDATE endpoint; nesting create
    // fields there made required ones read as missing ("field is required").
    //
    // Iterate VISIBLE fields, not every declared field: a start_command typed
    // while rendering_type was "ssr" must not be sent once it's switched to
    // "php" — the field is hidden and would create a unit nothing routes to.
    for (const config of visibleFields) {
      const value = values[config.name];
      // A toggle always goes, including when off: the backend validates it as
      // "true or false", so omitting it reads as missing, and sending the
      // string "false" is a 422.
      if (config.type === "toggle") {
        payload[config.name] = toggleValue(value);
        continue;
      }
      if (value === undefined || value === "") continue;
      payload[config.name] = config.type === "number" ? Number(value) : value;
    }
    if (isGit) {
      payload.git_source = gitSource;
      if (gitSource === "account") {
        payload.git_account_id = Number(values.git_account_id);
        payload.repository = values.repository;
      } else payload.repository_url = values.repository_url?.trim();
      if (values.branch?.trim()) payload.branch = values.branch.trim();
    }

    try {
      const { data } = await createApplication(payload);
      toast.success(t("created"));
      router.push(
        data?.application?.id
          ? `/applications/${data.application.id}`
          : "/applications",
      );
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
      // The backend rejects fields too, and its errors landed silently: nothing
      // scrolled, and an advanced field's message stayed behind the disclosure.
      revealErrors(Object.keys(error.response?.data?.errors ?? {}));
    }
  }

  return (
    <Form {...form}>
      <form
        ref={formRef}
        onSubmit={(event) =>
          form.handleSubmit(onSubmit, onInvalidSubmit)(event)
        }
        noValidate
        className="mx-auto max-w-6xl"
      >
        <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="min-w-0 space-y-6">
            <section
              className="space-y-3 rounded-xl border bg-card p-4 shadow-sm sm:p-5"
              aria-labelledby="application-type-heading"
            >
              <SectionHeading
                number="1"
                title={t("guided.stageType")}
                description={t("guided.typeHint")}
              />
              <FormField
                control={form.control}
                name="site_type"
                render={({ field }) => (
                  <FormItem className="min-w-0">
                    {/* min-w-0 on both: this is a grid item, and a grid item
                        keeps min-width:auto, so it grows to its content's
                        min-content width instead of its track. The trigger
                        carries a long tagline, which pushed the whole page into
                        horizontal scroll on a phone — the truncate inside never
                        got a chance because nothing above it was constrained. */}
                    <div id="application-type-heading" className="min-w-0">
                      <SiteTypePicker
                        types={siteTypes}
                        value={field.value}
                        onChange={field.onChange}
                      />
                    </div>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </section>

            <section
              className="space-y-4 rounded-xl border bg-card p-4 shadow-sm sm:p-5"
              aria-labelledby="application-details-heading"
            >
              <SectionHeading
                number="2"
                title={t("form.detailsTitle")}
                description={t("form.detailsHint")}
              />
              <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      {/* Same fixed-height label row as Domain's, which
                          carries a control in it. Two cells side by side only
                          line up if their heads are the same height. */}
                      <div className="flex h-7 items-center">
                        <FormLabel required>{t("name")}</FormLabel>
                      </div>
                      <FormControl>
                        <Input
                          autoComplete="off"
                          placeholder={t("form.namePlaceholder")}
                          {...field}
                        />
                      </FormControl>
                      {/* Answers the question the two fields raise together —
                          why a name AND a domain — and gives this cell the
                          same height as Domain's, which has a hint of its
                          own. Balance alone would not justify the line; the
                          answer does. */}
                      <FormDescription>{t("form.nameHint")}</FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="domain"
                  render={({ field }) => (
                    <FormItem>
                      {/* On the label row, not above the input.
                          Stacked options gave this cell a taller head than
                          Name's, so the two inputs no longer lined up and the
                          left column ended in dead space. A segmented control
                          rides in the label's own line: both cells keep the
                          shape "one label row, one input", and the grid stays
                          level. */}
                      <div className="flex h-7 items-center justify-between gap-2">
                        <FormLabel required>{t("domain")}</FormLabel>
                        {canUseTemporary ? (
                          <div
                            role="group"
                            aria-label={t("form.domainMode.label")}
                            className="flex shrink-0 items-center gap-0.5 rounded-md border p-0.5"
                          >
                            {["own", "temporary"].map((mode) => (
                              <button
                                key={mode}
                                type="button"
                                aria-pressed={domainMode === mode}
                                onClick={() => setDomainMode(mode)}
                                className={cn(
                                  "rounded px-2 py-0.5 text-xs transition-colors",
                                  domainMode === mode
                                    ? "bg-muted font-medium text-foreground"
                                    : "text-muted-foreground hover:text-foreground",
                                )}
                              >
                                {t(`form.domainMode.${mode}`)}
                              </button>
                            ))}
                          </div>
                        ) : null}
                      </div>

                      <FormControl>
                        <Input
                          inputMode="url"
                          autoComplete="url"
                          placeholder={t("form.domainPlaceholder")}
                          {...field}
                          // Read-only, not disabled: a disabled field is
                          // skipped by the keyboard and reads as broken, and
                          // this value is real — it is just not yours to type.
                          readOnly={temporary}
                          className={cn(temporary && "bg-muted/50 font-mono")}
                          value={temporary ? (generated ?? "") : field.value}
                          onChange={(event) => {
                            if (temporary) return;
                            field.onChange(event);
                            if (!form.getValues("name")?.trim()) {
                              const label = event.target.value
                                .trim()
                                .split(".")[0];
                              if (label) form.setValue("name", label);
                            }
                          }}
                        />
                      </FormControl>

                      <FormDescription>
                        {temporary
                          ? t("form.temporaryDomainHint")
                          : t("form.domainHint")}
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="system_user_id"
                  render={({ field }) => (
                    <FormItem className="md:col-span-2">
                      {/* Create sits on the label row and stays there whether or
                          not users already exist. Wanting a dedicated user for
                          a new site is the normal case, not a recovery from an
                          empty list — hiding it behind "you have none" meant
                          leaving the form to go and make one. */}
                      <div className="flex min-h-6 items-center justify-between gap-2">
                        <FormLabel required>{t("systemUser")}</FormLabel>
                        {canCreateSystemUser ? (
                          <button
                            type="button"
                            onClick={() => setSystemUserDialogOpen(true)}
                            className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline"
                          >
                            <UserPlus className="size-3" />
                            {t("form.createSystemUser")}
                          </button>
                        ) : null}
                      </div>
                      <FormControl>
                        <Combobox
                          options={availableSystemUsers.map((user) => ({
                            value: String(user.id),
                            label: user.username,
                          }))}
                          value={
                            field.value === undefined ? "" : String(field.value)
                          }
                          onChange={field.onChange}
                          placeholder={t("systemUserPlaceholder")}
                          disabled={availableSystemUsers.length === 0}
                        />
                      </FormControl>
                      {availableSystemUsers.length === 0 ? (
                        <FormDescription
                          className={
                            systemUsersFailed || !canCreateSystemUser
                              ? "text-destructive"
                              : undefined
                          }
                        >
                          {systemUsersFailed
                            ? t("form.systemUsersUnavailable")
                            : canCreateSystemUser
                              ? t("form.noSystemUsers")
                              : t("form.noSystemUserCreatePermission")}
                        </FormDescription>
                      ) : null}
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>
            </section>

            <section
              className="space-y-4 rounded-xl border bg-card p-4 shadow-sm sm:p-5"
              aria-labelledby="application-configure-heading"
            >
              <SectionHeading
                number="3"
                title={t("guided.stageConfigure")}
                description={
                  selected
                    ? t("guided.configureHint")
                    : t("form.chooseTypeHint")
                }
              />
              {selected ? (
                <div className="space-y-5" id="application-configure-heading">
                  {isGit ? (
                    <div className="space-y-4 border-b pb-5">
                      <div>
                        <p className="font-medium">{t("sourceLabel")}</p>
                        <p className="mt-1 text-sm leading-5 text-muted-foreground">
                          {t("form.repositoryHint")}
                        </p>
                      </div>
                      <ChoiceField
                        value={gitSource}
                        onChange={setGitSource}
                        options={[
                          { value: "account", label: t("useAccount") },
                          { value: "public_url", label: t("usePublicUrl") },
                        ]}
                      />
                      {gitSource === "account" ? (
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                          <FormField
                            control={form.control}
                            name="git_account_id"
                            render={({ field }) => (
                              <FormItem>
                                <FormLabel>{t("gitAccount")}</FormLabel>
                                <FormControl>
                                  <Combobox
                                    options={gitAccounts.map((account) => ({
                                      value: String(account.id),
                                      label: account.label,
                                      hint: account.provider_title,
                                    }))}
                                    value={
                                      field.value === undefined
                                        ? ""
                                        : String(field.value)
                                    }
                                    onChange={handleGitAccountChange}
                                    placeholder={t("gitAccountPlaceholder")}
                                    disabled={!gitAccounts.length}
                                  />
                                </FormControl>
                                {gitAccountsFailed ? (
                                  <FormDescription className="text-destructive">
                                    {t("loadFailed")}
                                  </FormDescription>
                                ) : !gitAccounts.length && !gitAccountsFailed ? (
                                  <span className="text-sm">
                                    <Link
                                      href="/integrations/git"
                                      target="_blank"
                                      rel="noreferrer"
                                      className="text-primary hover:underline"
                                    >
                                      {t("connectGit")}
                                    </Link>
                                  </span>
                                ) : null}
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                          <FormField
                            control={form.control}
                            name="repository"
                            render={({ field }) => (
                              <FormItem>
                                <FormLabel>{t("repository")}</FormLabel>
                                <ReasonTooltip
                                  reason={
                                    !gitAccountId
                                      ? t("form.repositoryNeedsAccount")
                                      : null
                                  }
                                  className="block w-full"
                                >
                                  <FormControl>
                                    <Combobox
                                      options={repositories.map((item) => ({
                                        value: item.full_name,
                                        label: item.full_name,
                                      }))}
                                      value={field.value ?? ""}
                                      onChange={handleRepositoryChange}
                                      placeholder={t("repositoryPlaceholder")}
                                      searchPlaceholder={t(
                                        "form.repositorySearch",
                                      )}
                                      disabled={
                                        !gitAccountId ||
                                        repositoriesState !== "ready"
                                      }
                                    />
                                  </FormControl>
                                </ReasonTooltip>
                                <PickerStatus
                                  state={repositoriesState}
                                  messages={{
                                    loading: t("form.repositoriesLoading"),
                                    empty: t("form.repositoriesEmpty"),
                                    error: t("form.repositoriesFailed"),
                                  }}
                                />
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                          <FormField
                            control={form.control}
                            name="branch"
                            render={({ field }) => (
                              <FormItem className="md:col-span-2">
                                <FormLabel>{t("branch")}</FormLabel>
                                <ReasonTooltip
                                  reason={
                                    !repository
                                      ? t("form.branchNeedsRepository")
                                      : null
                                  }
                                  className="block w-full"
                                >
                                  <FormControl>
                                    <Combobox
                                      options={branches.map((item) => ({
                                        value: item.name,
                                        label: item.name,
                                      }))}
                                      value={field.value ?? ""}
                                      onChange={field.onChange}
                                      placeholder={t("branchPlaceholder")}
                                      searchPlaceholder={t("form.branchSearch")}
                                      disabled={
                                        !repository || branchesState !== "ready"
                                      }
                                    />
                                  </FormControl>
                                </ReasonTooltip>
                                <PickerStatus
                                  state={branchesState}
                                  messages={{
                                    loading: t("form.branchesLoading"),
                                    empty: t("form.branchesEmpty"),
                                    error: t("form.branchesFailed"),
                                  }}
                                />
                                {branchesState === "ready" ? (
                                  <FormDescription>
                                    {t("form.branchHint")}
                                  </FormDescription>
                                ) : null}
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                        </div>
                      ) : (
                        <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                          <FormField
                            control={form.control}
                            name="repository_url"
                            render={({ field }) => (
                              <FormItem>
                                <FormLabel>{t("publicRepository")}</FormLabel>
                                <FormControl>
                                  <Input
                                    type="url"
                                    placeholder="https://github.com/owner/repository.git"
                                    {...field}
                                  />
                                </FormControl>
                                <FormDescription>
                                  {t("publicRepositoryHint")}
                                </FormDescription>
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                          <FormField
                            control={form.control}
                            name="branch"
                            render={({ field }) => (
                              <FormItem>
                                <FormLabel>{t("branch")}</FormLabel>
                                <FormControl>
                                  <Input
                                    placeholder={t("branchPlaceholder")}
                                    {...field}
                                  />
                                </FormControl>
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                        </div>
                      )}
                    </div>
                  ) : null}
                  {standardFields.length ? (
                    <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                      {standardFields.map((config) => (
                        <ConfigField
                          key={config.name}
                          config={config}
                          form={form}
                          accounts={gitAccounts}
                          phpVersions={phpVersions}
                          phpVersionsFailed={phpVersionsFailed}
                          nodeVersions={nodeVersions}
                          nodeVersionsFailed={nodeVersionsFailed}
                          timezones={timezones}
                        />
                      ))}
                    </div>
                  ) : null}
                  {advancedFields.length ? (
                    <Collapsible
                      className="border-t pt-4"
                      open={advancedOpen}
                      onOpenChange={setAdvancedOpen}
                    >
                      <CollapsibleTrigger asChild>
                        <Button
                          type="button"
                          variant="ghost"
                          className="w-full justify-between rounded-lg px-3 hover:bg-muted/60 data-[state=open]:bg-muted/60"
                        >
                          <span className="flex items-center gap-2">
                            <Sparkles className="size-4 text-primary" />
                            {t("advanced")}
                            {/* Says so on the closed row as well: the section
                                reopens on submit, but nothing should be able to
                                hide a rejected field behind a tidy summary. */}
                            {advancedErrorCount ? (
                              <Badge variant="destructive" className="font-normal">
                                {t("form.advancedErrors", {
                                  count: advancedErrorCount,
                                })}
                              </Badge>
                            ) : null}
                          </span>
                          <ChevronDown className="size-4" />
                        </Button>
                      </CollapsibleTrigger>
                      <CollapsibleContent className="grid grid-cols-1 items-start gap-4 pt-4 md:grid-cols-2">
                        {advancedFields.map((config) => (
                          <ConfigField
                            key={config.name}
                            config={config}
                            form={form}
                            accounts={gitAccounts}
                            phpVersions={phpVersions}
                            phpVersionsFailed={phpVersionsFailed}
                            nodeVersions={nodeVersions}
                            nodeVersionsFailed={nodeVersionsFailed}
                            timezones={timezones}
                          />
                        ))}
                      </CollapsibleContent>
                    </Collapsible>
                  ) : null}
                </div>
              ) : (
                <p
                  id="application-configure-heading"
                  className="rounded-lg border border-dashed bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground"
                >
                  {t("form.chooseTypeHint")}
                </p>
              )}
            </section>

            <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-muted-foreground">
                {selected ? t("guided.reviewHint", { brand }) : t("form.chooseTypeHint")}
              </p>
              <div className="flex gap-2">
                <Button type="button" variant="outline" asChild>
                  <Link href="/applications">{t("cancel")}</Link>
                </Button>
                <ReasonTooltip
                  reason={!selected ? t("form.submitNeedsType") : null}
                >
                  <Button
                    type="submit"
                    disabled={!selected || form.formState.isSubmitting}
                  >
                    {form.formState.isSubmitting ? (
                      <Loader2 className="size-4 animate-spin" />
                    ) : (
                      <ArrowRight className="size-4" />
                    )}
                    {form.formState.isSubmitting
                      ? t("creating")
                      : t("createAction")}
                  </Button>
                </ReasonTooltip>
              </div>
            </div>
          </div>
          <aside className="lg:sticky lg:top-20">
            {selected ? <CreateReadinessPanel items={readinessItems} /> : null}
          </aside>
        </div>
      </form>
      <CreateSystemUserDialog
        open={systemUserDialogOpen}
        onOpenChange={setSystemUserDialogOpen}
        onCreated={(user) => {
          if (!user?.id) return;
          setCreatedSystemUsers((current) => [...current, user]);
          form.setValue("system_user_id", String(user.id), {
            shouldDirty: true,
            shouldValidate: true,
          });
        }}
      />
    </Form>
  );
}
