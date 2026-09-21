"use client";

import { useEffect, useMemo, useRef, useState, useTransition } from "react";
import Link from "next/link";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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
 * A step marked by what it is FOR, and by whether it is finished.
 *
 * It was a number, which is the one thing a reader can already see — the
 * sections are in order down the page, so "2" told them nothing the position
 * had not. An icon says which of the three this is at a glance: the grid you
 * pick from, the globe for the site's own name and address, the sliders for
 * its settings.
 *
 * The tick still wins over the icon when a section has nothing outstanding.
 * Progress is the more useful thing to know, and it comes from the same
 * checklist the Create button trusts — so the badge and the button cannot
 * disagree about whether you are done.
 */
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
  // What the CHOSEN APPLICATION supports, so the field can say why the list is
  // shorter than the server's. Not "the server default must be X": the
  // installer runs on the site's own version (AbstractPhpInstaller::phpCommand
  // reads `$application->php_version` and only falls back to the default when
  // a site names none), so a note about the default would describe a rule this
  // panel does not have.
  phpRange,
  nodeRange,
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

  /*
   * What this application supports, said out loud.
   *
   * The dropdown is already filtered to versions in range, which is correct and
   * completely silent: on a server with PHP 8.1 and 8.3, an app needing 8.2+
   * simply shows one option and never explains where the other went. Asked for
   * as a note about the *server default* PHP — but nothing here depends on the
   * default (the installer runs the site's own version), so the true statement
   * is the application's own requirement.
   *
   * Only when there is a real bound. `rangeLabel` returns "" for a range with
   * neither end, which is an app that runs on anything, and "PHP: any version"
   * is noise on every other form.
   */
  const runtimeRange =
    config.source === "php_versions"
      ? phpRange
      : config.source === "node_versions"
        ? nodeRange
        : null;
  const runtimeRequirement = isRuntime ? rangeLabel(runtimeRange) : "";
  /*
   * The version they have actually chosen, and whether it is a real install.
   *
   * This is where the damage happens. An interpreter that arrived as another
   * package's dependency — `openlitespeed` pulls in `lsphp83` — has no curl,
   * sqlite3, redis, intl or pgsql, and the picker offered it indistinguishably
   * from the version the panel set up. The application is created, and the
   * missing extension surfaces days later inside somebody's site.
   *
   * Read off the SELECTED value rather than marking every option: a dropdown
   * that is closed most of the time cannot warn anyone, and the moment worth
   * interrupting is the one where the choice is already made.
   */
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
  const runtimeDefault = isRuntime ? preselectOption(options) : declaredDefault(config);
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
            {/* These fields are declared by the BACKEND, so there is no i18n
                key per field to hang an explanation on — the label itself
                arrives already translated. The explanation is therefore looked
                up by field NAME, and only when we have written one: most of
                these (admin_email, company_name, shop_name) explain themselves
                and a "?" on them would be noise. */}
            <FormLabel
              className="min-w-0"
              required={config.required}
              hint={t.has(`fieldHints.${config.name}`) ? t(`fieldHints.${config.name}`) : undefined}
            >
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
            {/* PHP and Node both: the version is installed on another screen,
                and coming back to a stale list is the same problem either way.
                `runtimeVersions` is the whole installed list, not the subset
                this site type can use — the diff is about what the server has. */}
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
          ) : isDatabaseEngine ? (
            /*
             * The one choice on this form that cannot be revised. No operation
             * moves a site from one engine to another — it would be delete and
             * start again — and the field itself gives no sign of that. It only
             * appears when the server genuinely has two of the engines this
             * type accepts, so it is a real decision every time it is shown.
             *
             * Keyed on the field NAME, which the API defines, not on an engine
             * name. There is no capability that says "irreversible", and the
             * sentence is ours rather than the server's.
             */
            <FormDescription className="flex items-start gap-1.5 text-warning">
              <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
              {t("form.databaseEnginePermanent")}
            </FormDescription>
          ) : chosenIncomplete ? (
            /* Outranks the range hint: the range says what this application
               needs, and this says the version in the box cannot deliver it. */
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
  // Both refresh actions show the same one-word label; only their accessible
  // names differ, so the shared string comes from `common`.
  const tCommon = useTranslations("common");
  const { name: brand } = useBranding();
  const router = useRouter();
  const [accountsRefreshing, startAccountsRefresh] = useTransition();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  // "loading" from the start when an account is already chosen: the fetch
  // effect fires on a non-empty id, but only the change handler sets this, so
  // a preselected account left the repository picker reading as idle while its
  // request was in flight.
  const [repositoriesState, setRepositoriesState] = useState(() =>
    gitAccounts.length === 1 ? "loading" : "idle",
  );
  const [branchesState, setBranchesState] = useState("idle");
  // The site type the declared defaults were last applied for, so a change of
  // type can be told apart from the first render. Holds the type itself, not
  // just its name: clearing the previous type's answers needs the fields it
  // declared, and by the time we notice the change `selected` is the new one.
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

  /*
   * The only connected account, preselected.
   *
   * With one account the Git step opened with an empty picker, and the
   * repository list below it stayed idle until you opened a menu and chose the
   * single entry — a question with one possible answer standing between you
   * and the field you actually came to fill in.
   *
   * Still a picker. The moment a second account exists the choice is real.
   */
  const soleGitAccountId = gitAccounts.length === 1 ? String(gitAccounts[0].id) : "";
  /*
   * An account named in the URL wins over the sole-account shortcut.
   *
   * The Git page sends you here straight after connecting one, and with two or
   * more accounts the picker would otherwise open empty — asking you to find
   * the account you made ten seconds ago. Already validated against the real
   * list by the page, so an unknown id arrives as "".
   */
  const startingGitAccountId = initialGitAccountId || soleGitAccountId;
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
      // On by default, because a dedicated account per site is the right
      // answer often enough to be where the form starts. Off for anyone who
      // cannot create system users: the API refuses to generate for them, and
      // a form that defaults to a refusal is a form that is wrong on open.
      generate_system_user: canCreateSystemUser,
      system_user_id: "",
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
    initialDomainMode({ serverIp }),
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
  const generateSystemUser = useWatch({
    control: form.control,
    name: "generate_system_user",
  });
  const systemUserId = useWatch({
    control: form.control,
    name: "system_user_id",
  });
  const branch = useWatch({ control: form.control, name: "branch" });
  // Held deliberately, not overlooked. Nothing reads these two, but they are
  // `useWatch` subscriptions rather than plain variables — deleting them stops
  // the form re-rendering when those fields change. The rendered output would
  // be identical and the re-renders strictly fewer, which is why it is a
  // separate decision from clearing unused imports rather than part of it.
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const phpVersion = useWatch({ control: form.control, name: "php_version" });
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const nodeVersion = useWatch({ control: form.control, name: "node_version" });
  const selected = useMemo(
    () => siteTypes.find((type) => type.name === selectedName),
    [siteTypes, selectedName],
  );
  // Only the versions this type runs on. Filtered here rather than in the
  // field so the pickers, the preselect and the type-change reset below all
  // read one list and cannot disagree about what is offerable.
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
      // Generating is a complete answer, so the row reads as ready rather than
      // as a blank waiting to be filled — the name itself does not exist yet.
      value: generateSystemUser
        ? t("form.systemUserWillBeCreated")
        : (availableSystemUsers.find(
            (user) => String(user.id) === String(systemUserId),
          )?.username ?? "—"),
      ready: generateSystemUser || Boolean(systemUserId),
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

  /*
   * Which numbered section each outstanding item belongs to.
   *
   * Derived from the checklist rather than re-deciding it: two answers to
   * "is this section finished" would eventually disagree, and the checklist is
   * the one the submit button already trusts. Section 3 owns everything that
   * is not the type or the three details — which is exactly what it renders —
   * and it cannot be finished before a type is chosen, because until then it
   * has no fields to be finished WITH.
   */
  const DETAIL_TARGETS = ["name", "domain", "system_user_id"];
  const sectionDone = {
    1: Boolean(selected),
    2: !missingReadinessItems.some((item) => DETAIL_TARGETS.includes(item.target)),
    3:
      Boolean(selected) &&
      !missingReadinessItems.some(
        (item) => item.target !== "site_type" && !DETAIL_TARGETS.includes(item.target),
      ),
  };

  /*
   * A blocked application can be CHOSEN now, so submit has to stop it.
   *
   * The grid used to refuse the click, which was the whole problem — you could
   * not reach the screen that installs what it needs. Choosing is allowed;
   * creating is not, until the server says the blockers are gone. Without this
   * the form would post and the API would refuse it after everything was
   * filled in, which is exactly the shape of failure this work removes.
   */
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

    // Read BEFORE the two blocks below, because both fill a field only when it
    // is empty — and emptying an unsupported version is exactly what makes
    // them refill it with a supported one on this same pass.
    const previous = lastType.current;
    const typeChanged = previous !== null && previous.name !== selected.name;
    lastType.current = selected;

    if (typeChanged) {
      // Dropped, not blanked: `unregister` takes the value, the error and the
      // edited flag together. Blanking would leave the field dirty, which
      // keeps the whole form "unsaved" over a type the user walked away from.
      const orphans = orphanFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (orphans.length > 0) form.unregister(orphans);

      // Value kept, error cleared. These errors only ever come back from the
      // server, generated from the old type's rules, so under the new type
      // they describe a validation that no longer exists.
      const shared = sharedFieldNames(previous.fields, selected.fields, COMMON_FIELD_NAMES);
      if (shared.length > 0) form.clearErrors(shared);

      // A runtime version is shared by name but not by meaning: Node 20 is a
      // valid answer for n8n and not for NodeBB. Clearing it here is what
      // stops a switch leaving a version the new type will refuse.
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
      // Same helper the field's Controller uses for its first render — two
      // copies of this coercion is how they start disagreeing about whether a
      // default is `8` or `"8"`.
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

  /*
   * Site title, tracking the name until the user has an opinion about it.
   *
   * It cannot ride the defaults loop above: that one fills from `field.default`
   * and `site_title` declares none, because the value is not a constant — it is
   * derived from another answer on this same form. So it is required, empty,
   * and asks for something already typed two fields higher up.
   *
   * Ownership is tracked here rather than read off `isDirty`, which does not
   * survive contact with a field that has no declared default. Writing the
   * first value moves it from `undefined` to a string, and RHF calls a field
   * that differs from its default dirty whatever `shouldDirty` said — so the
   * effect marked the field as user-edited on its own opening write and never
   * ran again. Comparing against the last value WE wrote asks the question we
   * actually mean: is what is in the box still ours?
   *
   * Empty counts as ours. That is what lets the suggestion come back after the
   * field is unregistered and re-registered by a type switch, and it costs
   * nothing: `site_title` is `required`, so a deliberately emptied title is a
   * state the form will not submit anyway.
   */
  const suggestedTitle = useRef("");
  useEffect(() => {
    // Keyed on the field, not the site type — seven types ask "what is this
    // site called" and no two of them agree on what to call the field. Keying
    // on `wordpress` would have left the other six with an empty required box
    // asking for something already typed two fields higher up.
    //
    // Two name-ish fields are deliberately NOT here. Joomla's `admin_name` is
    // a PERSON, and already defaults to "Administrator"; Moodle's `short_name`
    // is a separate abbreviation, and filling it with the same words as the
    // title is a guess dressed up as a convenience.
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
      // Only ever when there is something to validate, so an empty Name cannot
      // raise "Site title is required" against a box nobody has touched.
      shouldValidate: Boolean(next),
    });
  }, [form, name, selected]);

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
      // One or the other, never both: the API refuses a payload carrying a
      // generate flag *and* an id, because a client that sends both has not
      // decided and picking for it is how a site ends up owned by an account
      // nobody chose.
      ...(values.generate_system_user
        ? { generate_system_user: true }
        : { system_user_id: Number(values.system_user_id) }),
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
      } else {
        /*
         * Bitbucket's Clone button gives `https://you@bitbucket.org/team/repo.git`
         * and the API refuses any URL carrying a user component — rightly, since
         * this string ends up in `git clone`. Reported as a valid Bitbucket URL
         * being rejected with wording about a "self-hosted instance", which is
         * the GitLab host field's message and explains nothing here.
         *
         * For a public repository the username prefix means nothing, so it is
         * removed rather than refused. The field shows that it happened.
         */
        payload.repository_url = normalizeRepositoryUrl(values.repository_url).url;
      }
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
                    {/* Only once something is chosen: "Nextcloud needs two
                        more services" above an empty picker answers a question
                        nobody has asked yet. What an application needs belongs
                        to the application. */}
                    {field.value ? (
                      <RequiredServices
                        // Remounts on a different application, so a finished
                        // run cannot follow you to the next choice.
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
                        <FormLabel className="min-w-0" required hint={t("systemUserHint")}>
                          {t("systemUser")}
                        </FormLabel>
                        {/* Shows whether or not users already exist: wanting a
                            dedicated user for a new site is the normal case,
                            not a recovery from an empty list. Hidden while the
                            panel is generating one — there is nothing to pick
                            between, so the link would open a dialog whose
                            result the form would ignore. */}
                        {canCreateSystemUser && !generateSystemUser ? (
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

                      {/* Only offered to someone who may actually create an
                          account. Without the permission there is one way to
                          answer this question, and a disabled radio pair would
                          be two controls saying so. */}
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
                                  // Clearing on the way *into* generate mode,
                                  // so a stale id cannot be submitted beside
                                  // the flag — the API refuses that payload.
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
                /* One sentence, not the same one twice. Before a type is
                   chosen this said "Choose an application type to reveal its
                   configuration fields" — and so did the dashed box directly
                   under it, word for word, forty pixels apart. The heading
                   describes the section either way; the box does the asking. */
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
                      {/* Cards, like the System user choice two sections up,
                          and for the same reason: these are two different ways
                          of working rather than a setting with an on and an
                          off. Two bare radio dots gave the pair no weight on a
                          form where every other decision is a box you press,
                          and neither label said what it would COST — one wants
                          a connected account, the other wants nothing at all.
                          The hint is where that goes. */}
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
                                {/* Same min-h-7 label row as Repository beside
                                    it, so both comboboxes share one baseline. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("gitAccountHint")}>
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
                                  /* A state, not a stray link. The select above
                                     is disabled and a lone blue "Connect Git"
                                     under it read as a footnote rather than as
                                     the reason nothing can be chosen — so this
                                     says what is missing and offers the way out
                                     on the same line. */
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
                                {/* Action on the label row, matching the System
                                    user field — keeping it out of the control row
                                    leaves every input's right edge aligned with
                                    the Branch field below. */}
                                <div className="flex min-h-7 items-center justify-between gap-2">
                                  <FormLabel className="min-w-0" hint={t("repositoryHint")}>
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
                                {/* Said while the field is in front of you, not
                                    as a 422 afterwards in the GitLab host
                                    field's wording. */}
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
                            phpVersions={typePhpVersions}
                            phpVersionsFailed={phpVersionsFailed}
                            nodeVersions={typeNodeVersions}
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

            {/* Sticky, because this form is a screen and a half on a phone and
                Create was at the bottom of it — the button you are working
                towards should not be the one you have to go and find. It sits
                in the flow rather than fixed to the viewport, so it never
                covers the last field, and the blur keeps the fields readable
                as they pass underneath. */}
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
