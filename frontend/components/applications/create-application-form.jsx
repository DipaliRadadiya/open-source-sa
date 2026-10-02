"use client";

import { useEffect, useMemo, useRef, useState, useTransition } from "react";
import Link from "@/components/ui/app-link";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import {
  ArrowRight,
  Check,
  CheckCircle2,
  ChevronDown,
  CircleAlert,
  ExternalLink,
  GitBranch,
  Globe,
  Info,
  LayoutGrid,
  Loader2,
  RefreshCw,
  SlidersHorizontal,
  Sparkles,
  TriangleAlert,
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
import { apiMessage } from "@/lib/api/error-message";
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
import { normalizeRepositoryUrl } from "@/lib/applications/repository-url";
import { TITLE_FIELDS, siteTitleFrom } from "@/lib/applications/site-title";
import { declaredDefault, toggleValue } from "@/lib/applications/field-default";
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
import { rangeLabel, versionsInRange, versionWithin } from "@/lib/runtime/version-range";
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
import { RequiredServices } from "@/components/applications/required-services";
import { RuntimeRefresh } from "@/components/applications/runtime-refresh";
import {
  orphanFieldNames,
  sharedFieldNames,
} from "@/lib/applications/form-reset";
import { CreateReadinessPanel } from "@/components/applications/create-readiness-panel";
import { createSystemUser, deleteSystemUser } from "@/lib/api/system-users";
import { fallbackSystemUsername, suggestSystemUsername } from "@/lib/applications/system-username";

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

// Joomla's install fails silently on a prefix not starting with a letter and ending with "_".
const FIELD_PATTERNS = {
  joomla: {
    table_prefix: { pattern: /^[A-Za-z][A-Za-z0-9]*_$/, message: "form.tablePrefixInvalid" },
  },
};

// The API sometimes sends a raw key as the label; humanise the field name instead.
function fieldLabel(config) {
  const label = config.label;
  const looksLikeKey = !label || /^[a-z0-9_]+(\.[a-z0-9_]+)+$/i.test(label);
  if (!looksLikeKey) return label;
  const source = config.name || label.split(".").pop() || "";
  const words = source.replace(/[._]+/g, " ").trim();
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : label;
}

// Progress comes from the Create button's checklist, so the two cannot disagree.
function SectionHeading({ icon: Icon, title, description, headingId, done = false }) {
  return (
    <div className="flex items-start gap-3">
      <span
        className={cn(
          "flex size-7 shrink-0 items-center justify-center rounded-lg border transition-colors",
          done
            ? "border-success/30 bg-success/10 text-success"
            : "border-primary/30 bg-primary/10 text-primary",
        )}
      >
        {done ? <Check className="size-4" aria-hidden /> : <Icon className="size-4" aria-hidden />}
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

// Checked as the user types: the API answers free, registered (a warning) or taken.
function PortField({ field, config, placeholder }) {
  const t = useTranslations("applications");
  const [check, setCheck] = useState(null); // { state, message, suggested }

  useEffect(() => {
    const raw = String(field.value ?? "").trim();
    const port = Number(raw);
    const valid =
      Boolean(raw) && Number.isInteger(port) && port >= 1024 && port <= 65535;
    let cancelled = false;
    // All state writes happen in the deferred callback, never synchronously in
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

// The start command runs directly, not through a shell; the backend rejects
// package managers and shell syntax with a 422.
function startCommandProblem(value) {
  const v = String(value ?? "").trim();
  if (!v) return null;
  if (/[&|;]|\$\(|[<>]/.test(v)) return "shell";
  if (/^(npm|yarn|pnpm|bun|npx)\b/.test(v)) return "packageManager";
  return null;
}

// Multi-line values show their first line plus a count.
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
  // What the chosen application supports, so the field can explain a shortened
  // list. The installer uses the site's own PHP version, not the server default.
  phpRange,
  nodeRange,
  timezones,
  // A FIELD_PATTERNS rule shown while typing: the review panel blocks Create on
  // it, and a disabled button needs a visible reason.
  patternRule = null,
}) {
  const t = useTranslations("applications");
  const isAccount = config.source === "git_accounts";
  // Memoised: the `[]` fallback is a new array every render.
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

  // `rangeLabel` returns "" for an app that runs on any version.
  const runtimeRange =
    config.source === "php_versions"
      ? phpRange
      : config.source === "node_versions"
        ? nodeRange
        : null;
  const runtimeRequirement = isRuntime ? rangeLabel(runtimeRange) : "";
  // A dependency-installed interpreter (`openlitespeed` brings `lsphp83`) lacks
  // common extensions; warn on the selected value.
  const chosenVersion = useWatch({ control: form.control, name: config.name });
  const chosenIncomplete = useMemo(() => {
    if (!isRuntime || !chosenVersion) return null;
    const chosen = runtimeVersions.find(
      (item) => String(item?.version) === String(chosenVersion),
    );
    const missing = chosen?.missing_packages ?? [];
    return missing.length ? missing : null;
  }, [isRuntime, chosenVersion, runtimeVersions]);
  const isTimezone =
    config.source === "timezones" ||
    config.name === "timezone" ||
    config.name === "site_timezone" ||
    config.label?.toLowerCase().includes("time zone");
  const isPassword = config.type === "password";
  const isPort = config.name === "app_port";
  const isStartCommand = config.name === "start_command";
  const isDatabaseEngine = config.name === "database_engine";
  const isToggle = config.type === "toggle";
  // An input would silently drop newlines. build_command is declared `text`, but its
  // templates are two lines ("npm ci\nnpm run build").
  const isTextarea = config.type === "textarea" || config.name === "build_command";
  // `GitDeployer::script()` ignores build_command when a deploy script is present.
  const deployScript = useWatch({ control: form.control, name: "deploy_script" });
  const supersededByDeployScript =
    config.name === "build_command" && String(deployScript ?? "").trim() !== "";
  // A declared choice field renders a chooser even before its options arrive,
  // so it never degrades to free text.
  const isChoice = ["select", "enum", "dropdown"].includes(config.type);
  const [reveal, setReveal] = useState(false);
  // Unique by value: duplicate values make Radix's trigger render both items'
  // text ("8.48.4") and collide on the React key.
  const options = useMemo(() => {
    const raw = config.options?.length
      ? config.options
      : runtimeVersions.map((version) => ({
          value: version.version,
          label: version.version,
          // Required by `runtimeDefault` below; without it the newest version (not the
          // server default) would be preselected.
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
  const runtimeDefault = isRuntime ? preselectOption(options) : declaredDefault(config);
  const timezoneChoices = useMemo(
    () => (isTimezone ? timezoneOptions(timezones) : []),
    [isTimezone, timezones],
  );
  const isChooser =
    options.length > 0 || isRuntime || isChoice || isTimezone;
  // Long lists (countries, timezones, languages) get a searchable Combobox;
  // short ones stay a plain Select.
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
          {/* Label rows share a fixed height so inputs in a grid row align. The label
              truncates and the action never shrinks, so the row cannot overflow. */}
          <div className="flex min-h-7 items-center justify-between gap-2">
            {/* Backend-declared fields arrive with a translated label but no i18n key, so
                help text is looked up by field name, and only where one was written. */}
            <FormLabel
              className="min-w-0"
              required={config.required}
              hint={t.has(`fieldHints.${config.name}`) ? t(`fieldHints.${config.name}`) : undefined}
            >
              {label}
            </FormLabel>
            {isPassword && (config.generate || field.value) ? (
              <div className="flex shrink-0 items-center gap-2">
                {/* Generatable fields (admin and DB passwords) get a one-click strong value,
                    revealed so it can be copied before submitting. */}
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
                {field.value ? (
                  <CopyButton value={String(field.value)} className="size-6" />
                ) : null}
              </div>
            ) : null}
            {/* PHP and Node: versions are installed on another screen. `runtimeVersions`
                is the full installed list, not just what this site type can use. */}
            {isRuntime ? (
              <RuntimeRefresh
                runtime={config.source === "php_versions" ? "PHP" : "Node.js"}
                versions={runtimeVersions}
              />
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
                // Mono: textarea fields are commands or scripts, where spacing matters.
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
          {patternRule &&
          !form.formState.errors?.[config.name] &&
          String(field.value ?? "").trim() !== "" &&
          !patternRule.pattern.test(String(field.value).trim()) ? (
            <p className="text-sm text-destructive">{t(patternRule.message)}</p>
          ) : null}
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
          ) : isDatabaseEngine ? (
            /* Irreversible: no operation moves a site to another engine. The API has no capability flag, so keyed on the field name. */
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.databaseEnginePermanent")}
            </FormDescription>
          ) : chosenIncomplete ? (
            /* Outranks the range hint: the chosen version cannot meet the app's needs. */
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.runtimeIncomplete", {
                runtime: config.source === "php_versions" ? "PHP" : "Node.js",
                version: String(chosenVersion),
                packages: chosenIncomplete.join(", "),
              })}
            </FormDescription>
          ) : runtimeRequirement ? (
            <FormDescription>
              {t("form.runtimeRequirement", {
                runtime: config.source === "php_versions" ? "PHP" : "Node.js",
                range: runtimeRequirement,
              })}
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
  initialGitAccountId = "",
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
  engines = [],
  phpVersionsAll = [],
  nodeVersionsAll = [],
  phpInstallable = [],
  nodeInstallable = [],
  canInstall = {},
}) {
  const t = useTranslations("applications");
  // Both refresh actions share one visible label from `common`; only their
  // accessible names differ.
  const tCommon = useTranslations("common");
  const { name: brand } = useBranding();
  const router = useRouter();
  const { pushAndWait, refreshAndWait } = useRefresh();
  const [accountsRefreshing, startAccountsRefresh] = useTransition();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  // Starts "loading" when an account is preselected: the fetch runs on mount but
  // only the change handler would set this.
  const [repositoriesState, setRepositoriesState] = useState(() =>
    gitAccounts.length === 1 ? "loading" : "idle",
  );
  const [branchesState, setBranchesState] = useState("idle");
  // The site type the declared defaults were last applied for. Holds the type
  // object, since clearing the previous type's answers needs its fields.
  const lastType = useRef(null);
  // Bumped to re-fetch the same account's repositories (e.g. a token added in
  // another tab does not change `git_account_id`).
  const [repositoriesNonce, setRepositoriesNonce] = useState(0);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [focusRequest, setFocusRequest] = useState(null);
  // Bumped to ask for a scroll; the effect runs after the reveal has committed.
  const [scrollRequest, setScrollRequest] = useState(0);
  const formRef = useRef(null);
  // Set once the username is typed in, so renaming the application stops
  // overwriting it.
  const usernameEdited = useRef(false);
  // Used until the name gives a usable one, so the field is never blank.
  const fallbackUsername = useRef("");
  // Guards a second click that lands before the button is disabled, which would
  // create the same system user twice.
  const submitting = useRef(false);

  // With exactly one connected account, preselect it.
  const soleGitAccountId = gitAccounts.length === 1 ? String(gitAccounts[0].id) : "";
  // An account named in the URL (e.g. just connected on the Git page) wins.
  // The page validates it, so an unknown id arrives as "".
  const startingGitAccountId = initialGitAccountId || soleGitAccountId;
  const form = useForm({
    resolver: zodResolver(createApplicationSchema),
    mode: "onBlur",
    reValidateMode: "onChange",
    defaultValues: {
      // Seeded from the URL when a caller links to a specific type (e.g. "Install
      // phpMyAdmin").
      site_type: initialType,
      name: initialName,
      domain: "",
      // On by default; off for users who cannot create system users, since the API
      // refuses to generate for them.
      generate_system_user: canCreateSystemUser,
      system_user_id: "",
      system_user_username: suggestSystemUsername(initialName, systemUsers.map((user) => user.username)),
      system_user_password: "",
      git_account_id: startingGitAccountId,
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
  const newUsername = useWatch({ control: form.control, name: "system_user_username" });
  // After mount: a random value rendered on the server would cause a hydration
  // mismatch.
  useEffect(() => {
    form.setValue("system_user_password", generatePassword());
  }, [form]);
  useEffect(() => {
    if (usernameEdited.current) return;
    const taken = systemUsers.map((user) => user.username);
    const suggested = suggestSystemUsername(name, taken);
    if (!suggested && !fallbackUsername.current) fallbackUsername.current = fallbackSystemUsername(taken);
    form.setValue(
      "system_user_username",
      suggested || fallbackUsername.current,
      { shouldValidate: Boolean(form.formState.errors.system_user_username) },
    );
  }, [name, form, systemUsers]);
  const domain = useWatch({ control: form.control, name: "domain" });
  const repositoryUrl = useWatch({
    control: form.control,
    name: "repository_url",
  });
  const isDirty = form.formState.isDirty && !submitted;
  useWatchUnsaved("application-create", isDirty);

  // Temporary domain only when the server reported an IP for wildcard DNS.
  const canUseTemporary = Boolean(ipToLabel(serverIp));
  const [domainMode, setDomainMode] = useState(() =>
    initialDomainMode({ serverIp }),
  );
  const temporary = domainMode === "temporary";
  const generated = temporary
    ? temporaryDomain(name, serverIp, { suffixes: temporaryDomainSuffixes })
    : null;

  // The generated value is written into the field so validation, summary and
  // payload share one source.
  useEffect(() => {
    if (!temporary) return;
    const next = generated ?? "";
    if (form.getValues("domain") !== next) {
      form.setValue("domain", next, { shouldValidate: Boolean(next) });
    }
  }, [temporary, generated, form]);
  const generateSystemUser = useWatch({
    control: form.control,
    name: "generate_system_user",
  });
  const systemUserId = useWatch({
    control: form.control,
    name: "system_user_id",
  });
  const branch = useWatch({ control: form.control, name: "branch" });
  // Unused, but kept on purpose: these `useWatch` subscriptions re-render the
  // form when the fields change. Removing them is a separate decision.
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const phpVersion = useWatch({ control: form.control, name: "php_version" });
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const nodeVersion = useWatch({ control: form.control, name: "node_version" });
  const selected = useMemo(
    () => siteTypes.find((type) => type.name === selectedName),
    [siteTypes, selectedName],
  );
  // Versions this type runs on, filtered once so the pickers, the preselect and
  // the type-change reset share one list.
  const typePhpVersions = useMemo(
    () => versionsInRange(phpVersions, selected?.php_version_range),
    [phpVersions, selected],
  );
  const typeNodeVersions = useMemo(
    () => versionsInRange(nodeVersions, selected?.node_version_range),
    [nodeVersions, selected],
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
    // The API requires start_command when rendering_type is "ssr" but the schema
    // does not say so. Keyed on the name: `app_port` shares the dependency and is optional.
    .map((config) =>
      config.name === "start_command" ? { ...config, required: true } : config,
    );
  const standardFields = visibleFields.filter((config) => !config.advanced);
  const advancedFields = visibleFields.filter((config) => config.advanced);
  const advancedFieldNames = new Set(advancedFields.map((config) => config.name));
  const advancedErrorCount = advancedFields.filter(
    (config) => form.formState.errors[config.name],
  ).length;
  const availableSystemUsers = systemUsers;
  // A deploy script supersedes the build command, so the summary must not list
  // it as set.
  const hasDeployScript = String(values?.deploy_script ?? "").trim() !== "";
  const configurationSummaryItems = visibleFields
    .filter(
      (config) =>
        !(config.name === "build_command" && hasDeployScript) &&
        (config.required || hasConfigValue(config, values?.[config.name])),
    )
    .map((config) => {
      const value = values?.[config.name];
      const rule = FIELD_PATTERNS[values?.site_type]?.[config.name];
      const breaksRule = Boolean(rule) && String(value ?? "").trim() !== "" && !rule.pattern.test(String(value).trim());
      const ready = (!config.required || hasConfigValue(config, value)) && !breaksRule;
      return {
        key: `configuration-${config.name}`,
        target: config.name,
        label: fieldLabel(config),
        // Sensitive values are presence-only in the review card.
        value: isSensitiveConfig(config)
          ? t("readiness.configured")
          : config.type === "toggle"
            ? toggleValue(value)
              ? t("form.toggleOn")
              : t("form.toggleOff")
            : ready || breaksRule
              ? summariseValue(String(value), t)
              : "—",
        ready,
        // Filled in but refused: "Missing" would be untrue.
        invalid: breaksRule,
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
      target: generateSystemUser ? "system_user_username" : "system_user_id",
      label: t("systemUser"),
      // Generating is a complete answer, so the row reads as ready.
      value: generateSystemUser
        ? newUsername
          ? t("form.systemUserNew", { username: newUsername })
          : "—"
        : (availableSystemUsers.find(
            (user) => String(user.id) === String(systemUserId),
          )?.username ?? "—"),
      ready: generateSystemUser ? Boolean(newUsername) : Boolean(systemUserId),
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

  // Derived from the submit button's checklist, so the two cannot disagree.
  const DETAIL_TARGETS = ["name", "domain", "system_user_id", "system_user_username"];
  const sectionDone = {
    1: Boolean(selected),
    2: !missingReadinessItems.some((item) => DETAIL_TARGETS.includes(item.target)),
    3:
      Boolean(selected) &&
      !missingReadinessItems.some(
        (item) => item.target !== "site_type" && !DETAIL_TARGETS.includes(item.target),
      ),
  };

  // A blocked type can be chosen (to install its requirements) but not created.
  const outstandingServices = Array.isArray(selected?.blockers) ? selected.blockers.length : 0;

  const submitReason = !selected
    ? t("form.submitNeedsType")
    : outstandingServices
      ? t("form.submitNeedsServices", { count: outstandingServices })
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

  // Accounts are a server prop; `router.refresh()` re-reads them and keeps typed values.
  function refreshGitAccounts() {
    startAccountsRefresh(() => router.refresh());
  }

  /** Re-fetch the selected account's repositories (separate from the accounts list). */
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

    // Must run before the two blocks below: they fill only empty fields, so
    // clearing an unsupported version lets them refill it in this same pass.
    const previous = lastType.current;
    const typeChanged = previous !== null && previous.name !== selected.name;
    lastType.current = selected;

    if (typeChanged) {
      // Unregistered, not blanked: blanking would leave the field dirty and the
      // form "unsaved" over a type the user left.
      const orphans = orphanFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (orphans.length > 0) form.unregister(orphans);

      // Value kept, error cleared: server errors came from the old type's rules.
      const shared = sharedFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (shared.length > 0) form.clearErrors(shared);

      // Runtime versions are shared by name but not by meaning (Node 20 suits n8n,
      // not NodeBB), so clear any the new type would refuse.
      for (const [field, range] of [
        [phpField, selected.php_version_range],
        [nodeField, selected.node_version_range],
      ]) {
        if (!field) continue;
        const current = form.getValues(field.name);
        if (current && !versionWithin(current, range))
          form.setValue(field.name, "", { shouldDirty: false });
      }
    }

    if (phpField && !form.getValues(phpField.name)) {
      const version = preselectVersion(typePhpVersions, phpDefaultVersion);
      if (version)
        form.setValue(phpField.name, version, {
          shouldDirty: true,
          shouldValidate: true,
        });
    }
    if (nodeField && !form.getValues(nodeField.name)) {
      const version = preselectVersion(typeNodeVersions, nodeDefaultVersion);
      if (version)
        form.setValue(nodeField.name, version, {
          shouldDirty: true,
          shouldValidate: true,
        });
    }
    // Re-apply declared defaults on a type change, or a shared field keeps the old
    // type's value. `shouldDirty: false` keeps user-typed values distinguishable.
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
      // Fill when empty; re-default when the type changed and the value came from
      // the old type rather than the user.
      if (filled && !(typeChanged && !edited)) continue;
      // Same helper the field's Controller uses, so they agree on `8` vs `"8"`.
      const value = declaredDefault(field);
      form.setValue(field.name, value, {
        shouldDirty: false,
        shouldValidate: true,
      });
    }
  }, [
    form,
    nodeDefaultVersion,
    phpDefaultVersion,
    selected,
    typeNodeVersions,
    typePhpVersions,
  ]);

  // Site title tracks the name until edited. Ownership compares with the last value
  // written, not `isDirty`: RHF marks an undeclared-default field dirty on first write.
  const suggestedTitle = useRef("");
  useEffect(() => {
    // Joomla's `admin_name` (a person) and Moodle's `short_name` are deliberately excluded.
    const field = selected?.fields?.find((f) => TITLE_FIELDS.has(f.name));
    if (!field) return;
    const key = field.name;

    const current = form.getValues(key) ?? "";
    if (current && current !== suggestedTitle.current) return;

    const next = siteTitleFrom(name);
    if (current === next) return;
    suggestedTitle.current = next;
    form.setValue(key, next, {
      shouldDirty: false,
      // Only when there is a value, so an empty Name does not flag an untouched field.
      shouldValidate: Boolean(next),
    });
  }, [form, name, selected]);

  // Switching package manager fills in matching install+build commands, but only
  // while build_command is untouched; the user's own text always wins.
  useEffect(() => {
    if (!packageManager) return;
    const field = selected?.fields?.find(
      (item) => item.name === "package_manager",
    );
    const templates = field?.build_templates ?? {};
    // "Untouched" means empty or still exactly one of the templates.
    const current = form.getValues("build_command") ?? "";
    if (current && !Object.values(templates).includes(current)) return;
    const template = templates[packageManager];
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

  // Opens Advanced first when needed; scrolls after mount (Radix unmounts collapsed content).
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
      // One selector at a time: as a list, querySelector returns the first in DOM
      // order, and a password's Generate button sits above its input.
      const control = [
        '[data-slot="form-control"]',
        "input:not([type=hidden])",
        "textarea",
        "button:not([disabled])",
      ]
        .map((selector) => container?.querySelector(selector))
        .find(Boolean);
      control?.focus({ preventScroll: true });
      setFocusRequest(null);
    });
    return () => cancelAnimationFrame(frame);
  }, [focusRequest, advancedOpen]);

  async function onSubmit(values) {
    if (submitting.current) return;
    submitting.current = true;
    try {
      await submitApplication(values);
    } finally {
      submitting.current = false;
    }
  }

  async function submitApplication(values) {
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
    // Rules the installer enforces but the API does not check yet, so the install
    // cannot fail half way through on them.
    const badPatterns = visibleFields.filter((config) => {
      const rule = FIELD_PATTERNS[values.site_type]?.[config.name];
      const value = String(values[config.name] ?? "").trim();
      return rule && value && !rule.pattern.test(value);
    });
    if (badPatterns.length) {
      badPatterns.forEach((config) =>
        form.setError(config.name, {
          type: "manual",
          message: t(FIELD_PATTERNS[values.site_type][config.name].message),
        }),
      );
      revealErrors(badPatterns.map((config) => config.name));
      return;
    }
    const payload = {
      site_type: values.site_type,
      name: values.name.trim(),
      domain: values.domain.trim(),
      system_user_id: Number(values.system_user_id),
    };
    // Type fields go top-level on create; `settings` is only for update.
    // Only visible fields are sent.
    for (const config of visibleFields) {
      const value = values[config.name];
      // A toggle is always sent as a boolean: the backend requires "true or false",
      // and the string "false" is a 422.
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
      } else {
        /* The API rejects a username in the clone URL (Bitbucket adds one); strip it. */
        payload.repository_url = normalizeRepositoryUrl(values.repository_url).url;
      }
      if (values.branch?.trim()) payload.branch = values.branch.trim();
    }

    // Create the generated user first (`generate_system_user` sets no password);
    // it is removed if the application is refused.
    let newUser = null;
    if (values.generate_system_user) {
      try {
        const { data } = await createSystemUser({
          username: values.system_user_username,
          ...(values.system_user_password ? { password: values.system_user_password } : {}),
        });
        newUser = data?.system_user ?? null;
      } catch (error) {
        const errors = error.response?.data?.errors ?? {};
        const mapped = { username: "system_user_username", password: "system_user_password" };
        const fields = Object.keys(errors).filter((key) => mapped[key]);
        fields.forEach((key) => form.setError(mapped[key], { type: "server", message: errors[key][0] }));
        if (fields.length) revealErrors(fields.map((key) => mapped[key]));
        else toast.error(apiMessage(error, t("form.systemUserCreateFailed")));
        return;
      }
      payload.system_user_id = newUser?.id;
    }

    try {
      const { data } = await createApplication(payload);
      setSubmitted(true);
      // Navigate first, then toast, so the form is not left up saying "created".
      await pushAndWait(
        data?.application?.id
          ? `/applications/${data.application.id}`
          : "/applications",
      );
      toast.success(t("created"));
    } catch (error) {
      // Rolled back only when the server refused (4xx). After a 5xx or no answer the
      // application may exist, and removing its system user would break it; the user
      // is kept and offered under "Use an existing system user".
      const refused = Boolean(error.response) && error.response.status < 500;
      if (newUser?.id && !refused) {
        // Switch to the kept user so pressing Create again does not hit "name taken".
        await refreshAndWait();
        form.setValue("generate_system_user", false);
        form.setValue("system_user_id", String(newUser.id), { shouldValidate: true });
      }
      if (newUser?.id && refused) {
        const removed = await deleteSystemUser(newUser.id).then(() => true, () => false);
        // Left behind: a retry would find the name taken, so warn and refresh so it is
        // offered under "Use an existing system user".
        if (!removed) {
          toast.warning(t("form.systemUserLeftBehind", { username: newUser.username }), { duration: 15000 });
          router.refresh();
        }
      }
      handleValidationError(error, form);
      // Surface backend field errors too, including inside the Advanced section.
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
          {/* @container, not a viewport breakpoint: width depends on sidebar, summary and zoom. */}
          <div className="@container min-w-0 space-y-6">
            <section
              className="space-y-3 rounded-xl bg-card p-4 shadow-sm ring-1 ring-foreground/10 sm:p-5"
              aria-labelledby="application-type-heading"
            >
              <SectionHeading
                icon={LayoutGrid}
                done={sectionDone[1]}
                title={t("guided.stageType")}
                description={t("guided.typeHint")}
                headingId="application-type-heading"
              />
              <FormField
                control={form.control}
                name="site_type"
                render={({ field }) => (
                  <FormItem data-field-name="site_type" className="min-w-0">
                    {/* min-w-0: a grid item defaults to min-width:auto, so the trigger's long
                        tagline would widen the page instead of truncating. */}
                    <div className="min-w-0">
                      <SiteTypePicker
                        types={siteTypes}
                        value={field.value}
                        onChange={field.onChange}
                      />
                    </div>
                    {/* Only once an application is chosen. */}
                    {field.value ? (
                      <RequiredServices
                        // Remounts per application, so a finished run does not carry over.
                        key={field.value}
                        type={siteTypes.find((item) => item.name === field.value)}
                        engines={engines}
                        phpVersions={phpVersionsAll}
                        nodeVersions={nodeVersionsAll}
                        phpInstallable={phpInstallable}
                        nodeInstallable={nodeInstallable}
                        canInstall={canInstall}
                      />
                    ) : null}
                    <FormMessage />
                  </FormItem>
                )}
              />
            </section>

            <section
              className="space-y-4 rounded-xl bg-card p-4 shadow-sm ring-1 ring-foreground/10 sm:p-5"
              aria-labelledby="application-details-heading"
            >
              <SectionHeading
                icon={Globe}
                done={sectionDone[2]}
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
                      {/* Same min-h-7 label row as Domain, so the two cells line up. */}
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
                      <FormDescription>{t("form.nameHint")}</FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="domain"
                  render={({ field }) => (
                    // min-w-0: a grid item defaults to min-width:auto, so its contents would set
                    // the column's floor and overflow the card at larger text sizes.
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
                          // Read-only, not disabled: disabled fields are skipped by the keyboard and
                          // read as broken.
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
                        <FormLabel className="min-w-0" required hint={t("systemUserHint")}>
                          {t("systemUser")}
                        </FormLabel>
                      </div>

                      {/* Only offered to users who may create an account; otherwise there is only
                          one answer. */}
                      {canCreateSystemUser ? (
                        <div className="grid gap-4 @md:grid-cols-2">
                          {[
                            { generate: true, label: t("form.generateSystemUser"), hint: t("form.generateSystemUserHint") },
                            { generate: false, label: t("form.pickSystemUser"), hint: t("form.pickSystemUserHint") },
                          ].map((choice) => {
                            const active = generateSystemUser === choice.generate;
                            return (
                              <button
                                key={String(choice.generate)}
                                type="button"
                                aria-pressed={active}
                                onClick={() => {
                                  form.setValue("generate_system_user", choice.generate, {
                                    shouldDirty: true,
                                    shouldValidate: true,
                                  });
                                  // Clear the id when switching to generate: the API refuses both together.
                                  if (choice.generate) {
                                    form.setValue("system_user_id", "", { shouldValidate: true });
                                  }
                                }}
                                className={cn(
                                  "rounded-lg border p-3 text-left transition-colors",
                                  active
                                    ? "border-primary bg-primary/5"
                                    : "hover:border-muted-foreground/40",
                                )}
                              >
                                <span className="block text-sm font-medium">{choice.label}</span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                  {choice.hint}
                                </span>
                              </button>
                            );
                          })}
                        </div>
                      ) : null}

                      {generateSystemUser ? null : (
                      <>
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
                      </>
                      )}
                      <FormMessage />
                    </FormItem>
                  )}
                />
                {generateSystemUser ? (
                  <div className="grid min-w-0 gap-4 @md:grid-cols-2 @2xl:col-span-2">
                    <FormField
                      control={form.control}
                      name="system_user_username"
                      render={({ field }) => (
                        <FormItem data-field-name="system_user_username" className="min-w-0">
                          <FormLabel required>{t("form.systemUserUsername")}</FormLabel>
                          <FormControl>
                            <Input
                              autoComplete="off"
                              spellCheck={false}
                              className="font-mono"
                              {...field}
                              onChange={(event) => {
                                usernameEdited.current = true;
                                field.onChange(event);
                              }}
                            />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name="system_user_password"
                      render={({ field }) => (
                        <FormItem data-field-name="system_user_password" className="relative min-w-0">
                          <FormLabel hint={t("form.systemUserPasswordHint")}>
                            {t("form.systemUserPassword")}
                          </FormLabel>
                          <FormControl>
                            <PasswordInput
                              autoComplete="new-password"
                              placeholder={t("form.systemUserPasswordPlaceholder")}
                              {...field}
                            />
                          </FormControl>
                          <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="absolute top-0 right-0 h-auto p-0 text-xs"
                            onClick={() =>
                              form.setValue("system_user_password", generatePassword(), {
                                shouldDirty: true,
                                shouldValidate: true,
                              })
                            }
                          >
                            <Sparkles className="size-3" />
                            {t("form.systemUserGeneratePassword")}
                          </Button>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </div>
                ) : null}
              </div>
            </section>

            <section
              className="space-y-4 rounded-xl bg-card p-4 shadow-sm ring-1 ring-foreground/10 sm:p-5"
              aria-labelledby="application-configure-heading"
            >
              <SectionHeading
                icon={SlidersHorizontal}
                done={sectionDone[3]}
                title={t("guided.stageConfigure")}
                /* Before a type is chosen, the box below does the asking; the heading only
                   describes the section. */
                description={t("guided.configureHint")}
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
                      {/* Cards, like the System user choice: two ways of working, with the hint
                          saying what each needs. */}
                      <ChoiceField
                        variant="card"
                        className="grid gap-4 @md:grid-cols-2"
                        value={gitSource}
                        onChange={setGitSource}
                        options={[
                          {
                            value: "account",
                            label: t("useAccount"),
                            hint: t("form.useAccountHint"),
                          },
                          {
                            value: "public_url",
                            label: t("usePublicUrl"),
                            hint: t("form.usePublicUrlHint"),
                          },
                        ]}
                      />
                      {gitSource === "account" ? (
                        <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                          <FormField
                            control={form.control}
                            name="git_account_id"
                            render={({ field }) => (
                              <FormItem data-field-name="git_account_id" className="min-w-0">
                                {/* Same min-h-7 label row as Repository, so both comboboxes align. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("gitAccountHint")}>
                                    {t("gitAccount")}
                                  </FormLabel>
                                  {/* Picks up an account added via "Connect Git" in another tab without
                                      reloading the form. */}
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
                                  /* Explains why nothing can be chosen and offers the way out on one line. */
                                  <div className="flex items-center gap-2 rounded-lg border border-dashed px-2.5 py-2 text-xs text-muted-foreground">
                                    <GitBranch className="size-3.5 shrink-0" aria-hidden />
                                    <span className="min-w-0 flex-1">{t("form.noGitAccount")}</span>
                                    <Link
                                      href="/integrations/git"
                                      target="_blank"
                                      rel="noreferrer"
                                      className="inline-flex shrink-0 items-center gap-1 font-medium text-primary hover:underline"
                                    >
                                      {t("connectGit")}
                                      <ExternalLink className="size-3" aria-hidden />
                                    </Link>
                                  </div>
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
                                {/* Action on the label row, so input right edges align with Branch below. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("repositoryHint")}>
                                    {t("repository")}
                                  </FormLabel>
                                  {/* Both actions read "Refresh"; the accessible name tells them apart. */}
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
                                className="min-w-0"
                              >
                                <FormLabel hint={t("branchHint")}>{t("branch")}</FormLabel>
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
                                <FormLabel hint={t("publicRepositoryHint")}>{t("publicRepository")}</FormLabel>
                                <FormControl>
                                  <Input
                                    type="url"
                                    placeholder="https://github.com/owner/repository.git"
                                    {...field}
                                  />
                                </FormControl>
                                {/* Explains the stripped credentials up front rather than via a 422. */}
                                {normalizeRepositoryUrl(repositoryUrl).strippedCredentials ? (
                                  <p className="text-xs text-muted-foreground">
                                    {t("publicRepositoryUsernameDropped")}
                                  </p>
                                ) : null}
                                <FormMessage />
                              </FormItem>
                            )}
                          />
                          <FormField
                            control={form.control}
                            name="branch"
                            render={({ field }) => (
                              <FormItem data-field-name="branch" className="min-w-0">
                                <FormLabel hint={t("branchHint")}>{t("branch")}</FormLabel>
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
                          phpVersions={typePhpVersions}
                          phpVersionsFailed={phpVersionsFailed}
                          nodeVersions={typeNodeVersions}
                          nodeVersionsFailed={nodeVersionsFailed}
                          phpRange={selected?.php_version_range ?? null}
                          nodeRange={selected?.node_version_range ?? null}
                          timezones={timezones}
                          patternRule={FIELD_PATTERNS[selected?.name]?.[config.name] ?? null}
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
                            {/* Shown on the closed row too, so a rejected field is never hidden behind
                                the summary. */}
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
                            phpVersions={typePhpVersions}
                            phpVersionsFailed={phpVersionsFailed}
                            nodeVersions={typeNodeVersions}
                            nodeVersionsFailed={nodeVersionsFailed}
                            timezones={timezones}
                            patternRule={FIELD_PATTERNS[selected?.name]?.[config.name] ?? null}
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

            {/* Sticky in the flow (not fixed), so Create stays reachable on a long form
                without covering the last field. */}
            <div className="sticky bottom-0 z-10 -mx-1 flex flex-col gap-3 rounded-xl bg-background/85 px-4 py-3 shadow-sm ring-1 ring-foreground/10 backdrop-blur-sm sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
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
          {/* Offset by the shell's measured sticky chrome, not a fixed value. */}
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
