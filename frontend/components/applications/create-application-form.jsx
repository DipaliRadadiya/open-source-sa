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
  Container,
  ExternalLink,
  GitBranch,
  Info,
  Loader2,
  RefreshCw,
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
import { nextBuildCommand } from "@/lib/applications/build-command";
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
import { DeploySourcePicker } from "@/components/applications/deploy-source-picker";
import { RequiredServices } from "@/components/applications/required-services";
import { RuntimeRefresh } from "@/components/applications/runtime-refresh";
import {
  orphanFieldNames,
  sharedFieldNames,
} from "@/lib/applications/form-reset";
import { CreateReadinessPanel } from "@/components/applications/create-readiness-panel";
import { DockerImageSetup } from "@/components/applications/docker-image-setup";
import { DockerComposeSetup } from "@/components/applications/docker-compose-setup";
import {
  DOCKER_LIMITS,
  dockerCreateFields,
  dockerEnvProblem,
  dockerServerErrors,
  dockerVolumeProblem,
} from "@/lib/docker/create-request";
import { fallbackSystemUsername, suggestSystemUsername } from "@/lib/applications/system-username";
import { breaksDockerLimit, dockerLimitRule } from "@/lib/applications/docker-limit-rules";

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

const DOCKER_PICKER_FIELDS = new Set([
  "image",
  "container_port",
  "registry_id",
  "volume_new",
  "volume_path",
]);
const DOCKER_ADVANCED_FIELDS = new Set(["memory_limit", "cpu_limit"]);

const FIELD_PATTERNS = {
  joomla: {
    table_prefix: { pattern: /^[A-Za-z][A-Za-z0-9]*_$/, message: "form.tablePrefixInvalid" },
  },
};

function fieldLabel(config) {
  const label = config.label;
  const looksLikeKey = !label || /^[a-z0-9_]+(\.[a-z0-9_]+)+$/i.test(label);
  if (!looksLikeKey) return label;
  const source = config.name || label.split(".").pop() || "";
  const words = source.replace(/[._]+/g, " ").trim();
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : label;
}

function DescribedInput({ helpId, ...props }) {
  return <Input {...props} aria-describedby={cn(props["aria-describedby"], helpId)} />;
}

function SectionHeading({ step, title, description, headingId, done = false }) {
  return (
    <div className={cn("flex gap-3", description ? "items-start" : "items-center")}>
      <span
        className={cn(
          "flex size-7 shrink-0 items-center justify-center rounded-[2px] border font-mono text-sm font-semibold tabular-nums transition-colors",
          done
            ? "border-success/50 bg-success/15 text-success"
            : "border-primary/45 bg-primary/10 text-[var(--primary-700,var(--primary))] dark:text-primary",
        )}
        aria-hidden
      >
        {done ? <Check className="size-4" /> : step}
      </span>
      <div className="min-w-0 space-y-0.5">
        <h2 id={headingId} className="text-[15px] font-semibold tracking-tight">{title}</h2>
        {description ? <p className="text-sm leading-5 text-muted-foreground">{description}</p> : null}
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

function PortField({ field, config, placeholder }) {
  const t = useTranslations("applications");
  const [check, setCheck] = useState(null);

  useEffect(() => {
    const raw = String(field.value ?? "").trim();
    const port = Number(raw);
    const valid =
      Boolean(raw) && Number.isInteger(port) && port >= 1024 && port <= 65535;
    let cancelled = false;
    // State writes happen in the deferred callback, never in the effect body.
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

// The start command doesn't run through a shell; the backend rejects shell syntax.
function startCommandProblem(value) {
  const v = String(value ?? "").trim();
  if (!v) return null;
  if (/[&|;]|\$\(|[<>]/.test(v)) return "shell";
  if (/^(npm|yarn|pnpm|bun|npx)\b/.test(v)) return "packageManager";
  return null;
}

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
  phpRange,
  nodeRange,
  timezones,
  patternRule = null,
}) {
  const t = useTranslations("applications");
  const tv = useTranslations("validation");
  const isAccount = config.source === "git_accounts";
  // Memoised: the [] fallback is a new array every render.
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

  const runtimeRange =
    config.source === "php_versions"
      ? phpRange
      : config.source === "node_versions"
        ? nodeRange
        : null;
  const runtimeRequirement = isRuntime ? rangeLabel(runtimeRange) : "";
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
  const isRegistry = config.name === "registry_id";
  const isStartCommand = config.name === "start_command";
  const isDatabaseEngine = config.name === "database_engine";
  const isToggle = config.type === "toggle";
  const isTextarea = config.type === "textarea" || config.name === "build_command";
  const deployScript = useWatch({ control: form.control, name: "deploy_script" });
  const supersededByDeployScript =
    config.name === "build_command" && String(deployScript ?? "").trim() !== "";
  const isChoice = ["select", "enum", "dropdown"].includes(config.type);
  const [reveal, setReveal] = useState(false);
  const revealedByGenerate = useRef(false);
  // Unique by value: duplicates make Radix's trigger show both items and collide on key.
  const options = useMemo(() => {
    const raw = config.options?.length
      ? config.options
      : runtimeVersions.map((version) => ({
          value: version.version,
          label: version.version,
          // Needed by runtimeDefault, or the newest version gets preselected.
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
          <div className="flex min-h-7 items-center justify-between gap-2">
            <FormLabel
              className="min-w-0"
              required={config.required}
              hint={t.has(`fieldHints.${config.name}`) ? t(`fieldHints.${config.name}`) : undefined}
            >
              {label}
            </FormLabel>
            {isPassword && (config.generate || field.value) ? (
              <div className="flex shrink-0 items-center gap-2">
                {config.generate ? (
                  <button
                    type="button"
                    onClick={() => {
                      form.setValue(config.name, generatePassword(), {
                        shouldDirty: true,
                        shouldValidate: true,
                      });
                      setReveal(true);
                      revealedByGenerate.current = true;
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
                disabledReason={isRegistry ? t("container.noRegistries") : t("form.noOptions")}
              >
                <FormControl>
                  <SelectTrigger className="w-full">
                    <SelectValue
                      placeholder={
                        options.length
                          ? t("form.fieldSelectPlaceholder", { field: label })
                          : isRegistry
                            ? t("container.noRegistry")
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
                onShowChange={(next) => {
                  revealedByGenerate.current = false;
                  setReveal(next);
                }}
                {...field}
                onChange={(event) => {
                  field.onChange(event);
                  if (revealedByGenerate.current) {
                    revealedByGenerate.current = false;
                    setReveal(false);
                  }
                }}
                value={field.value ?? ""}
              />
            </FormControl>
          ) : isTextarea ? (
            <FormControl>
              <Textarea
                rows={6}
                spellCheck={false}
                placeholder={placeholder}
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
            <p className="text-sm text-destructive">{patternRule.scope === "validation" ? tv(patternRule.message) : t(patternRule.message)}</p>
          ) : null}
          {isPort ? null : runtimeFailed ? (
            <FormDescription className="text-destructive">
              {t("form.runtimeLoadFailed", { runtime: config.source === "php_versions" ? "PHP" : "Node.js" })}
            </FormDescription>
          ) : isStartCommand && startCommandProblem(field.value) && !form.formState.errors?.[config.name] ? (
            <FormDescription className="flex items-start gap-1.5 text-destructive">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t(`form.startCommand.${startCommandProblem(field.value)}`)}
            </FormDescription>
          ) : supersededByDeployScript ? (
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.buildCommandSuperseded")}
            </FormDescription>
          ) : isDatabaseEngine ? (
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.databaseEnginePermanent")}
            </FormDescription>
          ) : chosenIncomplete ? (
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
          ) : isRegistry && !options.length ? (
            <FormDescription>
              {t("container.noRegistries")}{" "}
              <Link href="/integrations/registries" className="font-medium text-primary underline">
                {t("container.noRegistriesLink")}
              </Link>
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

function requiredByOtherField(requiredIf, values) {
  if (!requiredIf || typeof requiredIf !== "object") return false;
  return Object.entries(requiredIf).some(([field, accepted]) => accepted.includes(String(values?.[field] ?? "")));
}

export function CreateApplicationForm({
  siteTypes = [],
  initialType = "",
  initialDockerSource = "search",
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
  databaseNotice = null,
}) {
  const t = useTranslations("applications");
  const [revealUserPassword, setRevealUserPassword] = useState(false);
  const userPasswordGenerated = useRef(false);
  const tCommon = useTranslations("common");
  const { name: brand } = useBranding();
  const router = useRouter();
  const { pushAndWait } = useRefresh();
  const [accountsRefreshing, startAccountsRefresh] = useTransition();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  const [repositoriesState, setRepositoriesState] = useState(() =>
    gitAccounts.length === 1 ? "loading" : "idle",
  );
  const [branchesState, setBranchesState] = useState("idle");
  const lastType = useRef(null);
  const [repositoriesNonce, setRepositoriesNonce] = useState(0);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [focusRequest, setFocusRequest] = useState(null);
  const [scrollRequest, setScrollRequest] = useState(0);
  const formRef = useRef(null);
  const usernameEdited = useRef(false);
  const fallbackUsername = useRef("");
  const submitting = useRef(false);

  const soleGitAccountId = gitAccounts.length === 1 ? String(gitAccounts[0].id) : "";
  const startingGitAccountId = initialGitAccountId || soleGitAccountId;
  const form = useForm({
    resolver: zodResolver(createApplicationSchema),
    mode: "onBlur",
    reValidateMode: "onChange",
    defaultValues: {
      site_type: initialType,
      docker_source: initialDockerSource,
      docker_search_query: "",
      ...(initialType === "docker" ? { docker_mode: initialDockerSource === "compose" ? "compose" : "simple" } : {}),
      name: initialName,
      domain: "",
      // The API refuses to generate one for users who can't create system users.
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

  const canUseTemporary = Boolean(ipToLabel(serverIp));
  const [domainMode, setDomainMode] = useState(() =>
    initialDomainMode({ serverIp }),
  );
  const temporary = domainMode === "temporary";
  const generated = temporary
    ? temporaryDomain(name, serverIp, { suffixes: temporaryDomainSuffixes })
    : null;

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
  const typePhpVersions = useMemo(
    () => versionsInRange(phpVersions, selected?.php_version_range),
    [phpVersions, selected],
  );
  const typeNodeVersions = useMemo(
    () => versionsInRange(nodeVersions, selected?.node_version_range),
    [nodeVersions, selected],
  );
  const isGit = selected?.method === "git" || selected?.name === "git";
  const containerServer = siteTypes.some((type) => type.name === "docker");
  const isDocker = selected?.name === "docker";
  const dockerMode = useWatch({ control: form.control, name: "docker_mode" });
  const dockerSource = useWatch({ control: form.control, name: "docker_source" });
  const isDockerSimple = isDocker && (dockerMode || "simple") === "simple";
  const dockerImageState = useWatch({ control: form.control, name: "docker_image_state" });
  const dockerEnv = useWatch({ control: form.control, name: "docker_env" });
  const dockerRequiredEnv = useWatch({ control: form.control, name: "docker_required_env" });
  const dockerVolumes = useWatch({ control: form.control, name: "docker_volumes" });
  const typeFields = (selected?.fields ?? []).filter(
    (config) =>
      !COMMON_FIELD_NAMES.has(config.name),
  );
  const dependencyValues = useWatch({ control: form.control });
  const dependsOnSatisfied = (dependsOn) => {
    if (typeof dependsOn !== "string" || !dependsOn.includes(":")) return true;

    const [field, expected] = dependsOn.split(":");

    return String(dependencyValues?.[field] ?? "") === expected;
  };

  const asksRendering = typeFields.some((config) => config.name === "rendering_type");
  const visibleFields = typeFields
    .filter(
      (config) =>
        dependsOnSatisfied(config.depends_on) &&
        (config.depends_on !== "rendering_type" || renderingType === "ssr") &&
        (config.source !== "php_versions" || !asksRendering || renderingType === "php") &&
        (config.depends_on !== "node_rendering" ||
          ["ssr", "csr"].includes(renderingType)),
    )
    .map((config) =>
      requiredByOtherField(config.required_if, dependencyValues) ? { ...config, required: true } : config,
    )
    .map((config) =>
      isDocker && DOCKER_ADVANCED_FIELDS.has(config.name)
        ? { ...config, advanced: true }
        : isDockerSimple && (config.name === "image" || config.name === "container_port")
          ? { ...config, required: true }
          : isDocker && !isDockerSimple && config.name === "compose"
            ? { ...config, required: true }
            : config,
    );
  const standardFields = visibleFields.filter((config) => !config.advanced);
  const advancedFields = visibleFields.filter((config) => config.advanced);
  const renderedByForm = (config) =>
    !(isDocker && config.name === "docker_mode") &&
    !(isDockerSimple && DOCKER_PICKER_FIELDS.has(config.name)) &&
    !(isDocker && !isDockerSimple && (config.name === "compose" || config.name === "registry_id"));
  const formStandardFields = standardFields.filter(renderedByForm);
  const formAdvancedFields = advancedFields.filter(renderedByForm);
  const registryOptions =
    selected?.fields?.find((config) => config.name === "registry_id")?.options ?? [];
  const dockerEnvBlocked = isDockerSimple && dockerEnvProblem(dockerEnv, dockerRequiredEnv);
  const dockerVolumesBlocked = isDockerSimple && dockerVolumeProblem(dockerVolumes);
  const advancedFieldNames = new Set(advancedFields.map((config) => config.name));
  const advancedErrorCount = advancedFields.filter(
    (config) => form.formState.errors[config.name],
  ).length;
  const availableSystemUsers = systemUsers;
  const hasDeployScript = String(values?.deploy_script ?? "").trim() !== "";
  const configurationSummaryItems = visibleFields
    .filter(
      (config) =>
        !(config.name === "build_command" && hasDeployScript) &&
        !(isDocker && config.name === "docker_mode") &&
        (config.required || hasConfigValue(config, values?.[config.name])),
    )
    .map((config) => {
      const value = values?.[config.name];
      const rule = FIELD_PATTERNS[values?.site_type]?.[config.name];
      const breaksRule =
        (Boolean(rule) && String(value ?? "").trim() !== "" && !rule.pattern.test(String(value).trim())) ||
        (config.name === "start_command" && Boolean(startCommandProblem(value))) ||
        breaksDockerLimit(config.name, value) ||
        Boolean(form.formState.errors[config.name]);
      const ready = (!config.required || hasConfigValue(config, value)) && !breaksRule;
      if (isDockerSimple && config.name === "image" && hasConfigValue(config, value)) {
        const imageState = dockerImageState || "checking";
        const tooLong = String(value).length > DOCKER_LIMITS.image;
        return {
          key: `configuration-${config.name}`,
          target: config.name,
          label: fieldLabel(config),
          value: imageState === "checking" ? t("dockerImage.checking") : String(value),
          ready: (imageState === "ok" || imageState === "unknown") && !tooLong,
          invalid: imageState === "notfound" || tooLong,
        };
      }
      return {
        key: `configuration-${config.name}`,
        target: config.name,
        label: fieldLabel(config),
        value: isSensitiveConfig(config)
          ? t("readiness.configured")
          : config.type === "toggle"
            ? toggleValue(value)
              ? t("form.toggleOn")
              : t("form.toggleOff")
            : ready || breaksRule
              ?
                (config.options?.find((option) => String(option.value) === String(value))?.label ??
                summariseValue(String(value), t))
              : "—",
        ready,
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
            label: missingGitTarget
              ? {
                  git_account_id: t("gitAccount"),
                  repository: t("repository"),
                  branch: t("branch"),
                  repository_url: t("publicRepository"),
                }[missingGitTarget]
              : t("sourceLabel"),
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
    ...(dockerEnvBlocked
      ? [
          {
            key: "docker-env",
            target: "docker_env",
            label: t("dockerImage.settingsLabel"),
            value: t("dockerImage.settingsIncomplete"),
            ready: false,
          },
        ]
      : []),
    ...(dockerVolumesBlocked
      ? [
          {
            key: "docker-volumes",
            target: "docker_volumes",
            label: t("dockerImage.storageLabel"),
            value: t("dockerImage.storageLimit", { max: DOCKER_LIMITS.volumes }),
            ready: false,
          },
        ]
      : []),
  ];
  const missingReadinessItems = readinessItems.filter((item) => !item.ready);

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

  function refreshGitAccounts() {
    startAccountsRefresh(() => router.refresh());
  }

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

  function dropTypeFields(next) {
    if (next === form.getValues("site_type")) return;
    for (const config of typeFields) form.unregister(config.name);
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

    // Must run before the two blocks below, which only fill empty fields.
    const previous = lastType.current;
    const typeChanged = previous !== null && previous.name !== selected.name;
    lastType.current = selected;

    if (typeChanged) {
      const orphans = orphanFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (orphans.length > 0) form.unregister(orphans);

      const shared = sharedFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (shared.length > 0) form.clearErrors(shared);

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
    for (const field of selected.fields ?? []) {
      if (
        COMMON_FIELD_NAMES.has(field.name) ||
        field.type === "password" ||
        field.source === "php_versions" ||
        field.source === "node_versions" ||
        field.default == null ||
        field.default === "" ||
        (selected.name === "docker" && field.name === "container_port")
      )
        continue;
      const filled = Boolean(form.getValues(field.name));
      const edited = form.getFieldState(field.name).isDirty;
      if (filled && !(typeChanged && !edited)) continue;
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

  const suggestedTitle = useRef("");
  useEffect(() => {
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
      shouldValidate: Boolean(next),
    });
  }, [form, name, selected]);

  useEffect(() => {
    const field = selected?.fields?.find(
      (item) => item.name === "package_manager",
    );
    if (!field) return;
    const next = nextBuildCommand({
      renderingType,
      packageManager,
      templates: field.build_templates ?? {},
      current: form.getValues("build_command") ?? "",
    });
    if (next === undefined) return;
    form.setValue("build_command", next, {
      shouldDirty: true,
      shouldValidate: true,
    });
  }, [form, packageManager, renderingType, selected]);

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

  // Radix unmounts collapsed content, so scroll after it mounts.
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
      const control = [
        '[data-slot="form-control"]',
        "input:not([type=hidden])",
        "textarea",
        "button:not([disabled])",
      ]
        .map((selector) => container?.querySelector(selector))
        .find(Boolean);
      control?.focus({ preventScroll: true });
      if (control) {
        control.dataset.attention = "true";
        setTimeout(() => delete control.dataset.attention, 1600);
      }
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
    const startProblem = visibleFields.some((config) => config.name === "start_command")
      ? startCommandProblem(values.start_command)
      : null;
    if (startProblem) {
      form.setError("start_command", { type: "manual", message: t(`form.startCommand.${startProblem}`) });
      revealErrors(["start_command"]);
      return;
    }
    const payload = {
      site_type: values.site_type,
      name: values.name.trim(),
      domain: values.domain.trim(),
      system_user_id: Number(values.system_user_id),
    };
    for (const config of visibleFields) {
      const value = values[config.name];
      if (config.type === "toggle") {
        payload[config.name] = toggleValue(value);
        continue;
      }
      if (value === undefined || value === "") continue;
      payload[config.name] = config.type === "number" ? Number(value) : value;
    }
    let dockerSent = null;
    if (isDockerSimple) {
      dockerSent = dockerCreateFields({
        applicationName: values.name,
        volumes: values.docker_volumes ?? [],
        envRows: values.docker_env ?? [],
      });
      delete payload.volume_new;
      delete payload.volume_path;
      Object.assign(payload, dockerSent.fields);
    }
    if (isGit) {
      payload.git_source = gitSource;
      if (gitSource === "account") {
        payload.git_account_id = Number(values.git_account_id);
        payload.repository = values.repository;
      } else {
        payload.repository_url = normalizeRepositoryUrl(values.repository_url).url;
      }
      if (values.branch?.trim()) payload.branch = values.branch.trim();
    }

    if (values.generate_system_user) {
      delete payload.system_user_id;
      payload.generate_system_user = true;
      payload.system_user = {
        username: values.system_user_username,
        ...(values.system_user_password ? { password: values.system_user_password } : {}),
      };
    }

    try {
      const { data } = await createApplication(payload);
      setSubmitted(true);
      await pushAndWait(
        data?.application?.id
          ? `/applications/${data.application.id}`
          : "/applications",
      );
      toast.success(t("created"));
      for (const warning of Array.isArray(data?.warnings) ? data.warnings : []) {
        if (typeof warning === "string" && warning) toast.warning(warning, { duration: 20000 });
      }
    } catch (error) {
      const errors = error.response?.data?.errors ?? {};
      const userFields = { "system_user.username": "system_user_username", "system_user.password": "system_user_password" };
      for (const [key, field] of Object.entries(userFields)) {
        if (errors[key]?.[0]) form.setError(field, { type: "server", message: errors[key][0] });
      }
      const docker = dockerSent && error.response?.data?.errors
        ? dockerServerErrors(errors, {
            ...dockerSent,
            envRows: values.docker_env ?? [],
            volumes: values.docker_volumes ?? [],
          })
        : null;
      docker?.set.forEach(([name, message]) => form.setError(name, { type: "server", message }));
      if (!docker?.set.length || Object.keys(docker.rest).length) {
        handleValidationError(
          docker ? { ...error, response: { ...error.response, data: { ...error.response.data, errors: docker.rest } } } : error,
          form,
        );
      }
      revealErrors(Object.keys(errors ?? {}).map((key) => userFields[key] ?? key));
    }
  }

  const actionButtons = (
    <>
      <Button
        type="button"
        variant="neutral"
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
    </>
  );

  return (
    <Form {...form}>
      {databaseNotice && (!selected || selected.needs_database) ? databaseNotice : null}
      <form
        ref={formRef}
        onSubmit={(event) =>
          form.handleSubmit(onSubmit, onInvalidSubmit)(event)
        }
        noValidate
      >
        <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <div className="@container min-w-0 space-y-6">
            <section
              className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1"
              aria-labelledby="application-type-heading"
            >
              <div className="border-b px-4 py-3.5 sm:px-5">
                <SectionHeading
                  step={1}
                  done={sectionDone[1]}
                  title={t("guided.stageType")}
                  headingId="application-type-heading"
                />
              </div>
              <div className="space-y-4 p-4 sm:p-5">
              <FormField
                control={form.control}
                name="site_type"
                render={({ field }) => (
                  <FormItem data-field-name="site_type" className="min-w-0">
                    <div className="min-w-0">
                      {containerServer ? (
                        <DeploySourcePicker
                          siteTypes={siteTypes}
                          value={field.value}
                          onChange={(next) => {
                            if (next) dropTypeFields(next);
                            field.onChange(next);
                          }}
                          form={form}
                          initialSource={initialType === "docker" ? initialDockerSource : undefined}
                        />
                      ) : (
                        <>
                          <SiteTypePicker
                            types={siteTypes}
                            value={field.value}
                            onChange={(next) => {
                              dropTypeFields(next);
                              field.onChange(next);
                            }}
                          />
                          <p className="mt-3 flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                            <Container className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            <span>{t("guided.moreOnDocker")}</span>
                          </p>
                        </>
                      )}
                    </div>
                    {field.value ? (
                      <RequiredServices
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
              </div>
            </section>

            <section
              className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1"
              aria-labelledby="application-details-heading"
            >
              <div className="border-b px-4 py-3.5 sm:px-5">
                <SectionHeading
                  step={2}
                  done={sectionDone[2]}
                  title={t("form.detailsTitle")}
                  headingId="application-details-heading"
                />
              </div>
              <div className="space-y-4 p-4 sm:p-5">
              <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem data-field-name="name" className="min-w-0">
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
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="domain"
                  render={({ field }) => (
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
                        <DescribedInput
                          helpId="create-domain-help"
                          inputMode="url"
                          autoComplete="url"
                          autoCapitalize="none"
                          spellCheck={false}
                          placeholder={t("form.domainPlaceholder")}
                          {...field}
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

                      <FormMessage />
                    </FormItem>
                  )}
                />
                <div className="flex min-w-0 items-start gap-2.5 rounded-lg bg-muted/50 p-2.5 text-xs text-muted-foreground @2xl:col-span-2">
                  <Info className="mt-px size-3.5 shrink-0" aria-hidden />
                  <div className="min-w-0 space-y-1.5">
                    <p id="create-domain-help">
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
                      ) : (
                        <>
                          {temporary ? t("form.temporaryDomainHint") : t("form.domainHint")}{" "}
                          {t("form.httpsAutomatic")}
                        </>
                      )}
                    </p>
                    {!temporary && serverIp && isValidApplicationDomain(domain) ? (
                      <>
                        <p>{t("form.dnsNote")}</p>
                        <div className="flex items-center gap-1.5">
                          <code className="rounded bg-background px-1.5 py-0.5 font-mono text-foreground">
                            {serverIp}
                          </code>
                          <CopyButton value={serverIp} className="size-6" />
                        </div>
                      </>
                    ) : null}
                  </div>
                </div>
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
                              show={revealUserPassword}
                              onShowChange={(next) => {
                                userPasswordGenerated.current = false;
                                setRevealUserPassword(next);
                              }}
                              {...field}
                              onChange={(event) => {
                                field.onChange(event);
                                if (userPasswordGenerated.current) {
                                  userPasswordGenerated.current = false;
                                  setRevealUserPassword(false);
                                }
                              }}
                            />
                          </FormControl>
                          <div className="absolute top-0 right-0 flex items-center gap-2">
                            <Button
                              type="button"
                              variant="link"
                              size="sm"
                              className="h-auto p-0 text-xs"
                              onClick={() => {
                                form.setValue("system_user_password", generatePassword(), {
                                  shouldDirty: true,
                                  shouldValidate: true,
                                });
                                setRevealUserPassword(true);
                                userPasswordGenerated.current = true;
                              }}
                            >
                              <Sparkles className="size-3" />
                              {t("form.systemUserGeneratePassword")}
                            </Button>
                            {field.value ? <CopyButton value={String(field.value)} className="size-6" /> : null}
                          </div>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </div>
                ) : null}
              </div>
              </div>
            </section>

            <section
              className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1"
              aria-labelledby="application-configure-heading"
            >
              <div className="border-b px-4 py-3.5 sm:px-5">
                <SectionHeading
                  step={3}
                  done={sectionDone[3]}
                  title={t("guided.stageConfigure")}
                  headingId="application-configure-heading"
                />
              </div>
              <div className="space-y-4 p-4 sm:p-5">
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
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("gitAccountHint")}>
                                    {t("gitAccount")}
                                  </FormLabel>
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
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("repositoryHint")}>
                                    {t("repository")}
                                  </FormLabel>
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
                  {isDockerSimple ? (
                    <DockerImageSetup
                      form={form}
                      mode={dockerSource === "manual" ? "manual" : "search"}
                      recipes={siteTypes.filter((type) => type.recipe)}
                      onUseRecipe={(slug) => form.setValue("site_type", slug, { shouldDirty: true })}
                      initialQuery={values.docker_search_query ?? ""}
                      registryOptions={registryOptions}
                    />
                  ) : isDocker ? (
                    <DockerComposeSetup form={form} registryOptions={registryOptions} />
                  ) : null}
                  {formStandardFields.length ? (
                    <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
                      {formStandardFields.map((config) => (
                        <ConfigField
                          key={`${selectedName}:${config.name}`}
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
                          patternRule={FIELD_PATTERNS[selected?.name]?.[config.name] ?? dockerLimitRule(config.name)}
                        />
                      ))}
                    </div>
                  ) : null}
                  {formAdvancedFields.length ? (
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
                        {formAdvancedFields.map((config) => (
                          <ConfigField
                            key={`${selectedName}:${config.name}`}
                            config={config}
                            form={form}
                            accounts={gitAccounts}
                            phpVersions={typePhpVersions}
                            phpVersionsFailed={phpVersionsFailed}
                            nodeVersions={typeNodeVersions}
                            nodeVersionsFailed={nodeVersionsFailed}
                            timezones={timezones}
                            patternRule={FIELD_PATTERNS[selected?.name]?.[config.name] ?? dockerLimitRule(config.name)}
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
              </div>
            </section>

            {selected ? (
              <div className="xl:hidden">
                <CreateReadinessPanel
                  items={readinessItems}
                  onSelectItem={focusReadinessItem}
                />
              </div>
            ) : null}

            <div className="flex items-center gap-3 rounded-2xl border border-border/70 bg-card px-4 py-3 shadow-e1 sm:px-5">
              {selected ? (
                <p className="hidden min-w-0 flex-1 text-sm text-muted-foreground md:block">
                  {t("guided.reviewHint", { brand })}
                </p>
              ) : null}
              <div className="ml-auto flex w-full gap-2 sm:w-auto [&>*]:flex-1 sm:[&>*]:flex-none">
                {actionButtons}
              </div>
            </div>
          </div>
          <aside className="hidden xl:sticky xl:top-[calc(var(--app-chrome,7rem)_+_1.5rem)] xl:block">
            <CreateReadinessPanel
              items={readinessItems}
              onSelectItem={focusReadinessItem}
              className="max-h-[calc(100svh-var(--app-chrome,7rem)-7rem)]"
            />
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
