"use client";

import { useEffect, useMemo, useRef, useState, useTransition } from "react";
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
  Info,
  Loader2,
  RefreshCw,
  Sparkles,
  TriangleAlert,
  UserPlus,
  Wand2,
} from "lucide-react";
import { toast } from "sonner";
import {
  createApplicationSchema,
  isValidApplicationDomain,
  portCheckResponseSchema,
  suggestApplicationDomain,
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
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { cn } from "@/lib/utils";
import { ChoiceField } from "@/components/ui/choice-field";
import { initialDomainMode, ipToLabel, temporaryDomain } from "@/lib/applications/temporary-domain";
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
import { timezoneOptions } from "@/lib/settings/timezone-options";
import { preselectOption, preselectVersion } from "@/lib/runtime/preselect-version";
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


function SectionHeading({ number, title, description, headingId }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-semibold text-muted-foreground">
        {number}
      </span>
      <div className="space-y-0.5">
        <h2 id={headingId} className="text-base font-semibold tracking-tight">{title}</h2>
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

function hasConfigValue(config, value) {
  if (config.type === "toggle") return value !== undefined && value !== null;
  return String(value ?? "").trim() !== "";
}

function isSensitiveConfig(config) {
  return (
    config.type === "password" ||
    /(?:password|passwd|secret|token|private[_-]?key|access[_-]?key|api[_-]?key|credential)/i.test(
      config.name,
    )
  );
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
          // Carried through, or `runtimeDefault` below finds nothing and falls
          // back to the first entry — which is the NEWEST version, not the
          // server's default. On a box defaulting to Node 24 that preselected
          // an end-of-life Node 25 for every new site.
          is_default: version.is_default,
        }));
    const seen = new Set();
    return raw.filter((option) => {
      const key = String(option.value);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }, [config.options, runtimeVersions]);
  const runtimeDefault = isRuntime ? preselectOption(options) : undefined;
  // Timezones: flatten the grouped API response into a flat option list.
  const timezoneChoices = useMemo(
    () => (isTimezone ? timezoneOptions(timezones) : []),
    [isTimezone, timezones],
  );
  const isChooser =
    options.length > 0 || isRuntime || isChoice || isTimezone;
  // Long enumerations (countries ~250, timezones ~400, languages) get a
  // searchable Combobox per the house rule; short lists stay a plain Select.
  const useCombobox = (isTimezone ? timezoneChoices.length : options.length) > 10;
  const label = fieldLabel(config);
  const placeholder =
    config.placeholder ?? t("form.fieldPlaceholder", { field: label });

  return (
    <FormField
      control={form.control}
      name={config.name}
      defaultValue={runtimeDefault}
      render={({ field }) => (
        <FormItem
          data-field-name={config.name}
          className={cn("min-w-0 self-start", isTextarea && "@2xl:col-span-2")}
        >
          {/* Every label row in this form is exactly h-7, whether or not it
              carries an action — that fixed height is what keeps the two
              inputs in a grid row starting at the same Y. The label truncates
              and the action never shrinks, so the row cannot overflow even if
              the column is narrower than the container query expected. */}
          <div className="flex min-h-7 items-center justify-between gap-2">
            <FormLabel className="min-w-0" required={config.required}>
              {label}
            </FormLabel>
            {isPassword && (config.generate || field.value) ? (
              <div className="flex shrink-0 items-center gap-2">
                {/* Fields the schema marks generatable (WordPress admin
                    password, DB passwords) get a one-click strong value,
                    revealed so it can be copied before it is submitted. */}
                {config.generate ? (
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
                {/* A generated password is shown once and never again — a copy
                    control beside it means it can be saved without
                    hand-selecting the field. */}
                {field.value ? (
                  <CopyButton value={String(field.value)} className="size-6" />
                ) : null}
              </div>
            ) : null}
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
          ) : isTimezone && timezoneChoices.length ? (
            useCombobox ? (
              <FormControl>
                <Combobox
                  options={timezoneChoices}
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
                  {timezoneChoices.map((option) => (
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
                  disabledReason={t("form.noOptions")}
                />
              </FormControl>
            ) : (
              <Select
                onValueChange={field.onChange}
                value={field.value ? String(field.value) : ""}
                disabled={!options.length}
                disabledReason={t("form.noOptions")}
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
  initialType = "",
  initialName = "",
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
  // Both refresh actions show the same one-word label; only their accessible
  // names differ, so the shared string comes from `common`.
  const tCommon = useTranslations("common");
  const { name: brand } = useBranding();
  const router = useRouter();
  const [accountsRefreshing, startAccountsRefresh] = useTransition();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  const [repositoriesState, setRepositoriesState] = useState("idle");
  const [branchesState, setBranchesState] = useState("idle");
  // Which site type the declared defaults were last applied for, so a change of
  // type can be told apart from the first render.
  const lastType = useRef(null);
  // Bumped to re-ask the provider for the same account's repositories. A token
  // added in the other tab does not change `git_account_id`, so without this the
  // fetch effect has no reason to run again and the picker stays stale.
  const [repositoriesNonce, setRepositoriesNonce] = useState(0);
  const [systemUserDialogOpen, setSystemUserDialogOpen] = useState(false);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [focusRequest, setFocusRequest] = useState(null);
  // Bumped to ask for a scroll; the effect runs after the reveal has committed.
  const [scrollRequest, setScrollRequest] = useState(0);
  const formRef = useRef(null);
  const [createdSystemUsers, setCreatedSystemUsers] = useState([]);
  const form = useForm({
    resolver: zodResolver(createApplicationSchema),
    mode: "onBlur",
    reValidateMode: "onChange",
    defaultValues: {
      // Seeded from the URL when something sent you here for a particular
      // type — the databases page's "Install phpMyAdmin", for one. Empty
      // otherwise, which is every other way in.
      site_type: initialType,
      name: initialName,
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
  const repositoryUrl = useWatch({
    control: form.control,
    name: "repository_url",
  });
  const isDirty = form.formState.isDirty && !submitted;
  useWatchUnsaved("application-create", isDirty);

  /**
   * Somewhere to put a site that has no domain yet.
   *
   * Offered only when the server reported an address — the wildcard-DNS host
   * needs one to point at, and an option that cannot produce a domain is worse
   * than no option. `own` stays the default, so anyone who has a domain sees
   * exactly what they saw before.
   */
  const canUseTemporary = Boolean(ipToLabel(serverIp));
  const [domainMode, setDomainMode] = useState(() =>
    initialDomainMode({ prefilledName: initialName, serverIp }),
  );
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
      !COMMON_FIELD_NAMES.has(config.name),
  );
  const visibleFields = typeFields
    .filter(
      (config) =>
        (config.depends_on !== "rendering_type" || renderingType === "ssr") &&
        (config.depends_on !== "node_rendering" ||
          ["ssr", "csr"].includes(renderingType)),
    )
    // GitSiteType::rules() requires `start_command` exactly when rendering_type
    // is "ssr", and the filter above means that is the only time the field is on
    // screen — so whenever it renders it is required, unconditionally. The field
    // schema does not say so, which left the one field an SSR site cannot start
    // without unmarked and, worse, un-gated: an empty value passed the form and
    // came back as a 422. Keyed on the name rather than `depends_on`, because
    // `app_port` shares that dependency and is genuinely optional.
    .map((config) =>
      config.name === "start_command" ? { ...config, required: true } : config,
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
  // A deploy script makes the build command dead weight, so the last thing
  // read before pressing Create must not list it as set and ready.
  const hasDeployScript = String(values?.deploy_script ?? "").trim() !== "";
  const configurationSummaryItems = visibleFields
    .filter(
      (config) =>
        !(config.name === "build_command" && hasDeployScript) &&
        (config.required || hasConfigValue(config, values?.[config.name])),
    )
    .map((config) => {
      const value = values?.[config.name];
      const ready = !config.required || hasConfigValue(config, value);
      return {
        key: `configuration-${config.name}`,
        target: config.name,
        label: fieldLabel(config),
        // Passwords, tokens and keys are presence-only in a review. Rendering
        // their actual value in a sticky card leaks it to shoulder-surfers and
        // screen recordings.
        value: isSensitiveConfig(config)
          ? t("readiness.configured")
          : config.type === "toggle"
            ? toggleValue(value)
              ? t("form.toggleOn")
              : t("form.toggleOff")
            : ready
              ? summariseValue(String(value), t)
              : "—",
        ready,
      };
    });
  const missingGitTarget = !isGit
    ? null
    : gitSource === "account"
      ? !gitAccountId
        ? "git_account_id"
        : !repository
          ? "repository"
          : !branch
            ? "branch"
            : null
      : !repositoryUrl?.trim()
        ? "repository_url"
        : !branch?.trim()
          ? "branch"
          : null;
  const readinessItems = [
    {
      key: "type",
      target: "site_type",
      label: t("chooseType"),
      value: selected?.title ?? t("form.chooseTypeHint"),
      ready: Boolean(selected),
    },
    {
      key: "name",
      target: "name",
      label: t("name"),
      value: name || "—",
      ready: Boolean(name?.trim()),
    },
    {
      key: "domain",
      target: "domain",
      label: t("domain"),
      value: domain || "—",
      ready: isValidApplicationDomain(domain),
    },
    {
      key: "user",
      target: "system_user_id",
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
            target: missingGitTarget ?? "git_account_id",
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
                : [repositoryUrl, branch].filter(Boolean).join(" · ") || "—",
            ready: !missingGitTarget,
          },
        ]
      : []),
    ...configurationSummaryItems,
  ];
  const missingReadinessItems = readinessItems.filter((item) => !item.ready);
  const submitReason = !selected
    ? t("form.submitNeedsType")
    : missingReadinessItems.length
      ? t("form.submitMissing", { count: missingReadinessItems.length })
      : null;
  const suggestedDomain = form.formState.touchedFields.domain
    ? suggestApplicationDomain(domain)
    : null;

  function handleGitAccountChange(value) {
    form.setValue("git_account_id", value);
    form.setValue("repository", "");
    form.setValue("branch", "");
    setRepositories([]);
    setBranches([]);
    setRepositoriesState(value ? "loading" : "idle");
    setBranchesState("idle");
  }

  /**
   * Pick up an account connected since this form was opened.
   *
   * "Connect Git" opens the integrations page in a new tab, so this form is
   * still mounted when the user comes back — and the accounts list arrived as a
   * server prop, which nothing client-side can re-read. `router.refresh()`
   * re-runs the server component; it is a soft refresh, so everything already
   * typed into the form survives.
   */
  function refreshGitAccounts() {
    startAccountsRefresh(() => router.refresh());
  }

  /**
   * Re-ask the provider for the selected account's repositories.
   *
   * Separate from the accounts refresh on purpose: these are two different
   * lists, fetched from two different places, and a label that says "Refresh"
   * beside a field should refresh that field.
   */
  function refreshRepositories() {
    if (gitAccountId) setRepositoriesState("loading");
    setRepositoriesNonce((nonce) => nonce + 1);
  }

  function handleRepositoryChange(value) {
    form.setValue("repository", value);
    form.setValue("branch", "");
    setBranches([]);
    setBranchesState(value ? "loading" : "idle");
  }

  function focusReadinessItem(name) {
    if (advancedFieldNames.has(name)) setAdvancedOpen(true);
    setFocusRequest(name);
  }

  function handleCancel() {
    if (isDirty) setConfirmLeave(true);
    else router.push("/applications");
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
      const version = preselectVersion(phpVersions, phpDefaultVersion);
      if (version)
        form.setValue(phpField.name, version, {
          shouldDirty: true,
          shouldValidate: true,
        });
    }
    if (nodeField && !form.getValues(nodeField.name)) {
      const version = preselectVersion(nodeVersions, nodeDefaultVersion);
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
    //
    // On a TYPE CHANGE the defaults are re-applied, which they were not before:
    // the loop only filled empty fields, so picking Craft (web_root "/web") and
    // then switching to a type that serves from "/public" kept "/web" and
    // submitted it. The site provisioned pointing at a directory that does not
    // exist and 404'd while looking correctly configured. Every field the two
    // types share had the same problem; web_root is only the one that fails
    // silently rather than loudly.
    //
    // A value the user typed is never overwritten — `shouldDirty: false` below
    // is what makes that distinction possible, so a prefilled value stays clean
    // and an edited one does not.
    const typeChanged = lastType.current !== null && lastType.current !== selected.name;
    lastType.current = selected.name;

    for (const field of selected.fields ?? []) {
      if (
        COMMON_FIELD_NAMES.has(field.name) ||
        field.type === "password" ||
        field.source === "php_versions" ||
        field.source === "node_versions" ||
        field.default == null ||
        field.default === ""
      )
        continue;
      const filled = Boolean(form.getValues(field.name));
      const edited = form.getFieldState(field.name).isDirty;
      // Fill when empty; re-default when the type changed and this value came
      // from the old type rather than from the person filling the form.
      if (filled && !(typeChanged && !edited)) continue;
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
  }, [form, gitAccountId, gitSource, isGit, repositoriesNonce]);

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

  useEffect(() => {
    if (!focusRequest) return;
    const frame = requestAnimationFrame(() => {
      const fields = formRef.current?.querySelectorAll("[data-field-name]") ?? [];
      const container = [...fields].find(
        (item) => item.dataset.fieldName === focusRequest,
      );
      container?.scrollIntoView({ behavior: "smooth", block: "center" });
      const control = container?.querySelector(
        '[data-slot="form-control"], input, textarea, button:not([disabled])',
      );
      control?.focus({ preventScroll: true });
      setFocusRequest(null);
    });
    return () => cancelAnimationFrame(frame);
  }, [focusRequest, advancedOpen]);

  async function onSubmit(values) {
    const missingFields = visibleFields.filter(
      (config) => config.required && !String(values[config.name] ?? "").trim(),
    );
    const missingGitFields = !isGit
      ? []
      : gitSource === "account"
        ? [
            { name: "git_account_id", label: t("gitAccount") },
            { name: "repository", label: t("repository") },
            { name: "branch", label: t("branch") },
          ].filter((field) => !String(values[field.name] ?? "").trim())
        : [
            { name: "repository_url", label: t("publicRepository") },
            { name: "branch", label: t("branch") },
          ].filter((field) => !String(values[field.name] ?? "").trim());
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
      setSubmitted(true);
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
          {/* @container, not a viewport breakpoint. How much room these fields
              actually have depends on the sidebar, the summary panel beside
              them and the reader.'s zoom — never on the window width. At 120%
              zoom this column is ~450px at any window size, so a viewport rule
              kept promising two columns that could not fit. The threshold is in
              rem, so it grows with the text it has to hold. */}
          <div className="@container min-w-0 space-y-6">
            <section
              className="space-y-3 rounded-xl border bg-card p-4 shadow-sm sm:p-5"
              aria-labelledby="application-type-heading"
            >
              <SectionHeading
                number="1"
                title={t("guided.stageType")}
                description={t("guided.typeHint")}
                headingId="application-type-heading"
              />
              <FormField
                control={form.control}
                name="site_type"
                render={({ field }) => (
                  <FormItem data-field-name="site_type" className="min-w-0">
                    {/* min-w-0 on both: this is a grid item, and a grid item
                        keeps min-width:auto, so it grows to its content's
                        min-content width instead of its track. The trigger
                        carries a long tagline, which pushed the whole page into
                        horizontal scroll on a phone — the truncate inside never
                        got a chance because nothing above it was constrained. */}
                    <div className="min-w-0">
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
                headingId="application-details-heading"
              />
              <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem data-field-name="name" className="min-w-0">
                      {/* Empty right side, same h-7 as Domain, which carries a
                          control: two cells side by side only line up if their
                          heads are the same height. */}
                      <div className="flex min-h-7 items-center">
                        <FormLabel className="min-w-0" required>
                          {t("name")}
                        </FormLabel>
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
                    // min-w-0: a grid item keeps min-width:auto, so without this
                    // its contents set the column's floor and the widest of them
                    // hangs over the card's edge at larger text sizes.
                    <FormItem data-field-name="domain" className="min-w-0">
                      <div className="flex min-h-7 items-center justify-between gap-2">
                        <FormLabel className="min-w-0" required>
                          {t("domain")}
                        </FormLabel>
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
                          autoCapitalize="none"
                          spellCheck={false}
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
                        {suggestedDomain ? (
                          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span>{t("form.domainSuggestion")}</span>
                            <button
                              type="button"
                              onClick={() =>
                                form.setValue("domain", suggestedDomain, {
                                  shouldDirty: true,
                                  shouldTouch: true,
                                  shouldValidate: true,
                                })
                              }
                              className="font-medium text-primary hover:underline"
                            >
                              {t("form.useDomain", { domain: suggestedDomain })}
                            </button>
                          </span>
                        ) : temporary ? (
                          t("form.temporaryDomainHint")
                        ) : (
                          t("form.domainHint")
                        )}
                      </FormDescription>

                      {!temporary &&
                      serverIp &&
                      isValidApplicationDomain(domain) ? (
                        <div className="flex items-center gap-2 rounded-lg bg-muted/50 p-2.5 text-xs text-muted-foreground">
                          <Info className="size-3.5 shrink-0" aria-hidden />
                          <p className="flex flex-wrap items-center gap-1.5">
                            <span>{t("form.dnsNote")}</span>
                            <code className="rounded bg-background px-1.5 py-0.5 font-mono text-foreground">
                              {serverIp}
                            </code>
                            <CopyButton value={serverIp} className="size-6" />
                          </p>
                        </div>
                      ) : null}

                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="system_user_id"
                  render={({ field }) => (
                    <FormItem
                      data-field-name="system_user_id"
                      className="min-w-0 @2xl:col-span-2"
                    >
                      <div className="flex min-h-7 items-center justify-between gap-2">
                        <FormLabel className="min-w-0" required>
                          {t("systemUser")}
                        </FormLabel>
                        {/* Shows whether or not users already exist: wanting a
                            dedicated user for a new site is the normal case,
                            not a recovery from an empty list. */}
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
                          ariaLabel={t("systemUser")}
                          disabled={availableSystemUsers.length === 0}
                          disabledReason={t("form.needsSystemUser")}
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
                headingId="application-configure-heading"
              />
              {selected ? (
                <div className="space-y-5">
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
                        <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                          <FormField
                            control={form.control}
                            name="git_account_id"
                            render={({ field }) => (
                              <FormItem data-field-name="git_account_id" className="min-w-0">
                                {/* Same min-h-7 label row as Repository beside
                                    it, so both comboboxes share one baseline. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0">
                                    {t("gitAccount")}
                                  </FormLabel>
                                  {/* "Connect Git" below opens another tab; this
                                      is how the account added there gets here
                                      without reloading the form. */}
                                  <button
                                    type="button"
                                    onClick={refreshGitAccounts}
                                    disabled={accountsRefreshing}
                                    aria-label={t("form.gitAccountsRefresh")}
                                    className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline disabled:pointer-events-none disabled:opacity-50"
                                  >
                                    <RefreshCw
                                      className={cn(
                                        "size-3",
                                        accountsRefreshing && "animate-spin",
                                      )}
                                    />
                                    {tCommon("refresh")}
                                  </button>
                                </div>
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
                                    disabledReason={t("form.needsGitAccount")}
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
                              <FormItem data-field-name="repository" className="min-w-0">
                                {/* Action on the label row, matching the System
                                    user field — keeping it out of the control row
                                    leaves every input's right edge aligned with
                                    the Branch field below. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0">
                                    {t("repository")}
                                  </FormLabel>
                                  {/* Both actions read "Refresh"; the accessible
                                      name is what tells them apart. */}
                                  <button
                                    type="button"
                                    onClick={refreshRepositories}
                                    disabled={
                                      !gitAccountId ||
                                      repositoriesState === "loading"
                                    }
                                    aria-label={t("form.repositoriesRefresh")}
                                    className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline disabled:pointer-events-none disabled:opacity-50"
                                  >
                                    <RefreshCw
                                      className={cn(
                                        "size-3",
                                        repositoriesState === "loading" &&
                                          "animate-spin",
                                      )}
                                    />
                                    {tCommon("refresh")}
                                  </button>
                                </div>
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
                              <FormItem
                                data-field-name="branch"
                                className="min-w-0 @2xl:col-span-2"
                              >
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
                        <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                          <FormField
                            control={form.control}
                            name="repository_url"
                            render={({ field }) => (
                              <FormItem data-field-name="repository_url" className="min-w-0">
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
                              <FormItem data-field-name="branch" className="min-w-0">
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
                    <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
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
                      <CollapsibleContent className="grid grid-cols-1 items-start gap-4 pt-4 @2xl:grid-cols-2">
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
                  className="rounded-lg border border-dashed bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground"
                >
                  {t("form.chooseTypeHint")}
                </p>
              )}
            </section>

            {selected ? (
              <div className="lg:hidden">
                <CreateReadinessPanel
                  items={readinessItems}
                  onSelectItem={focusReadinessItem}
                />
              </div>
            ) : null}

            <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
              <p className="text-sm text-muted-foreground">
                {selected ? t("guided.reviewHint", { brand }) : t("form.chooseTypeHint")}
              </p>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleCancel}
                  disabled={form.formState.isSubmitting}
                >
                  {t("cancel")}
                </Button>
                <ReasonTooltip reason={submitReason}>
                  <Button
                    type="submit"
                    disabled={Boolean(submitReason) || form.formState.isSubmitting}
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
          {/* Clears the shell's sticky chrome, whatever it currently is — a
              fixed offset slid this panel under the breadcrumb as soon as a
              banner appeared above the header. */}
          <aside className="hidden lg:sticky lg:top-[calc(var(--app-chrome,7rem)_+_1.5rem)] lg:block">
            {selected ? (
              <CreateReadinessPanel
                items={readinessItems}
                onSelectItem={focusReadinessItem}
              />
            ) : null}
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
      <ConfirmDialog
        open={confirmLeave}
        onOpenChange={setConfirmLeave}
        icon={TriangleAlert}
        tone="warning"
        confirmVariant="destructive"
        title={tCommon("unsavedTitle")}
        description={tCommon("unsavedDescription")}
        cancelLabel={tCommon("unsavedStay")}
        confirmLabel={tCommon("unsavedLeave")}
        onConfirm={() => {
          setSubmitted(true);
          router.push("/applications");
        }}
      />
    </Form>
  );
}
