"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowRight, ChevronDown, Loader2, Sparkles } from "lucide-react";
import { toast } from "sonner";
import { createApplicationSchema } from "@/lib/schemas/application";
import { branchesResponseSchema, repositoriesResponseSchema } from "@/lib/schemas/git";
import { createApplication, getBranches, getRepositories } from "@/lib/api/applications";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ChoiceField } from "@/components/ui/choice-field";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { SiteTypePicker } from "@/components/applications/site-type-picker";
import { CreateReadinessPanel } from "@/components/applications/create-readiness-panel";
import { CreateSystemUserDialog } from "@/components/system-users/create-system-user-dialog";

const TOP_LEVEL_FIELDS = new Set([
  "web_root", "build_command", "rendering_type", "start_command", "app_port",
  "branch", "repository", "repository_url", "php_version", "node_version",
]);
const COMMON_FIELD_NAMES = new Set([
  "site_type", "name", "domain", "system_user_id", "git_source", "git_account_id",
  "repository", "repository_url", "branch",
]);

function SectionHeading({ number, title, description }) {
  return <div className="flex items-start gap-3"><span className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-semibold text-muted-foreground">{number}</span><div className="space-y-0.5"><h2 className="text-base font-semibold tracking-tight">{title}</h2><p className="text-sm leading-5 text-muted-foreground">{description}</p></div></div>;
}

function PickerStatus({ state, messages }) {
  if (state === "loading") return <FormDescription>{messages.loading}</FormDescription>;
  if (state === "empty") return <FormDescription>{messages.empty}</FormDescription>;
  if (state === "error") return <FormDescription className="text-destructive">{messages.error}</FormDescription>;
  return null;
}

function ConfigField({ config, form, accounts, phpVersions, phpVersionsFailed, nodeVersions, nodeVersionsFailed }) {
  const t = useTranslations("applications");
  const isAccount = config.source === "git_accounts";
  const runtimeVersions = config.source === "php_versions" ? phpVersions : config.source === "node_versions" ? nodeVersions : [];
  const runtimeFailed = config.source === "php_versions" ? phpVersionsFailed : config.source === "node_versions" ? nodeVersionsFailed : false;
  const isRuntime = config.source === "php_versions" || config.source === "node_versions";
  const options = config.options?.length ? config.options : runtimeVersions.map((version) => ({ value: version.version, label: version.version }));
  const runtimeDefault = isRuntime ? options.find((option) => option.is_default)?.value ?? options[0]?.value : undefined;
  const placeholder = config.placeholder ?? t("form.fieldPlaceholder", { field: config.label });

  return <FormField control={form.control} name={config.name} defaultValue={runtimeDefault} render={({ field }) => <FormItem className="min-w-0 self-start">
    <FormLabel>{config.label}</FormLabel>
    {isAccount ? <Select onValueChange={field.onChange} value={field.value ? String(field.value) : ""}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("gitAccountPlaceholder")} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{accounts.map((account) => <SelectItem key={account.id} value={String(account.id)}>{account.label} · {account.provider_title}</SelectItem>)}</SelectContent></Select>
      : (options.length || isRuntime) ? <Select onValueChange={field.onChange} value={field.value ? String(field.value) : ""} disabled={isRuntime && !options.length}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("form.fieldSelectPlaceholder", { field: config.label })} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{options.map((option) => <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>)}</SelectContent></Select>
        : <FormControl><Input type={config.type === "password" ? "password" : config.type === "number" ? "number" : "text"} placeholder={placeholder} {...field} value={field.value ?? ""} /></FormControl>}
    {runtimeFailed ? <FormDescription className="text-destructive">{t("loadFailed")}</FormDescription> : config.help ? <FormDescription>{config.help}</FormDescription> : null}
    <FormMessage />
  </FormItem>} />;
}

export function CreateApplicationForm({ siteTypes = [], systemUsers = [], systemUsersFailed = false, canCreateSystemUser = false, gitAccounts = [], gitAccountsFailed = false, phpVersions = [], phpDefaultVersion = null, phpVersionsFailed = false, nodeVersions = [], nodeDefaultVersion = null, nodeVersionsFailed = false }) {
  const t = useTranslations("applications");
  const router = useRouter();
  const [gitSource, setGitSource] = useState("account");
  const [repositories, setRepositories] = useState([]);
  const [branches, setBranches] = useState([]);
  const [repositoriesState, setRepositoriesState] = useState("idle");
  const [branchesState, setBranchesState] = useState("idle");
  const [systemUserDialogOpen, setSystemUserDialogOpen] = useState(false);
  const [createdSystemUsers, setCreatedSystemUsers] = useState([]);
  const form = useForm({
    resolver: zodResolver(createApplicationSchema),
    mode: "onBlur",
    reValidateMode: "onChange",
    defaultValues: { site_type: "", name: "", domain: "", system_user_id: "", git_account_id: "", repository: "", branch: "" },
  });
  const values = useWatch({ control: form.control });
  const selectedName = useWatch({ control: form.control, name: "site_type" });
  const gitAccountId = useWatch({ control: form.control, name: "git_account_id" });
  const repository = useWatch({ control: form.control, name: "repository" });
  const renderingType = useWatch({ control: form.control, name: "rendering_type" });
  const name = useWatch({ control: form.control, name: "name" });
  const domain = useWatch({ control: form.control, name: "domain" });
  const systemUserId = useWatch({ control: form.control, name: "system_user_id" });
  const branch = useWatch({ control: form.control, name: "branch" });
  const phpVersion = useWatch({ control: form.control, name: "php_version" });
  const nodeVersion = useWatch({ control: form.control, name: "node_version" });
  const selected = useMemo(() => siteTypes.find((type) => type.name === selectedName), [siteTypes, selectedName]);
  const isGit = selected?.method === "git" || selected?.name === "git";
  const typeFields = (selected?.fields ?? []).filter((config) => !COMMON_FIELD_NAMES.has(config.name));
  const visibleFields = typeFields.filter((config) => config.depends_on !== "rendering_type" || renderingType === "ssr");
  const standardFields = visibleFields.filter((config) => !config.advanced);
  const advancedFields = visibleFields.filter((config) => config.advanced);
  const availableSystemUsers = [...systemUsers, ...createdSystemUsers.filter((created) => !systemUsers.some((user) => user.id === created.id))];
  const runtimeSummaryItems = visibleFields
    .filter((config) => config.source === "php_versions" || config.source === "node_versions")
    .map((config) => {
      const versions = config.source === "php_versions" ? phpVersions : nodeVersions;
      const value = values?.[config.name] ?? form.getValues(config.name) ?? versions[0]?.version;
      return value ? { key: `runtime-${config.name}`, label: config.label, value: String(value), ready: true } : null;
    })
    .filter(Boolean);
  const advancedSummaryItems = advancedFields
    .filter((config) => String(values?.[config.name] ?? "").trim())
    .map((config) => ({ key: `advanced-${config.name}`, label: config.label, value: String(values[config.name]), ready: true }));
  const readinessItems = [
    { key: "type", label: t("chooseType"), value: selected?.title ?? t("form.chooseTypeHint"), ready: Boolean(selected) },
    { key: "name", label: t("name"), value: name || "—", ready: Boolean(name?.trim()) },
    { key: "domain", label: t("domain"), value: domain || "—", ready: Boolean(domain?.trim()) },
    { key: "user", label: t("systemUser"), value: availableSystemUsers.find((user) => String(user.id) === String(systemUserId))?.username ?? "—", ready: Boolean(systemUserId) },
    ...(isGit ? [{ key: "source", label: t("source"), value: gitSource === "account" ? [gitAccounts.find((account) => String(account.id) === String(gitAccountId))?.label, repository, branch].filter(Boolean).join(" · ") || "—" : [form.getValues("repository_url"), branch].filter(Boolean).join(" · ") || "—", ready: gitSource === "account" ? Boolean(gitAccountId && repository && branch) : Boolean(form.getValues("repository_url") && branch) }] : []),
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
    const phpField = selected.fields?.find((field) => field.source === "php_versions");
    const nodeField = selected.fields?.find((field) => field.source === "node_versions");
    if (phpField && !form.getValues(phpField.name)) {
      const candidates = [phpDefaultVersion, phpVersions.find((item) => item.is_default)?.version, phpVersions[0]?.version];
      const version = candidates.find((candidate) => candidate && phpVersions.some((item) => item.version === candidate));
      if (version) form.setValue(phpField.name, version, { shouldDirty: true, shouldValidate: true });
    }
    if (nodeField && !form.getValues(nodeField.name)) {
      const candidates = [nodeDefaultVersion, nodeVersions.find((item) => item.is_default)?.version, nodeVersions[0]?.version];
      const version = candidates.find((candidate) => candidate && nodeVersions.some((item) => item.version === candidate));
      if (version) form.setValue(nodeField.name, version, { shouldDirty: true, shouldValidate: true });
    }
  }, [form, nodeDefaultVersion, nodeVersions, phpDefaultVersion, phpVersions, selected]);

  useEffect(() => {
    let cancelled = false;
    if (!isGit || gitSource !== "account" || !gitAccountId) return undefined;

    getRepositories(gitAccountId, { per_page: 100 })
      .then(({ data }) => {
        const parsed = repositoriesResponseSchema.safeParse(data);
        if (!parsed.success) throw new Error("Invalid repository response");
        if (cancelled) return;
        setRepositories(parsed.data.repositories);
        setRepositoriesState(parsed.data.repositories.length ? "ready" : "empty");
      })
      .catch(() => { if (!cancelled) setRepositoriesState("error"); });

    return () => { cancelled = true; };
  }, [form, gitAccountId, gitSource, isGit]);

  useEffect(() => {
    let cancelled = false;
    if (!isGit || gitSource !== "account" || !gitAccountId || !repository) return undefined;

    getBranches(gitAccountId, repository)
      .then(({ data }) => {
        const parsed = branchesResponseSchema.safeParse(data);
        if (!parsed.success) throw new Error("Invalid branch response");
        if (cancelled) return;
        setBranches(parsed.data.branches);
        setBranchesState(parsed.data.branches.length ? "ready" : "empty");
        const defaultBranch = repositories.find((item) => item.full_name === repository)?.default_branch;
        if (defaultBranch && parsed.data.branches.some((item) => item.name === defaultBranch)) form.setValue("branch", defaultBranch);
      })
      .catch(() => { if (!cancelled) setBranchesState("error"); });

    return () => { cancelled = true; };
  }, [form, gitAccountId, gitSource, isGit, repository, repositories]);

  async function onSubmit(values) {
    const missingFields = visibleFields.filter((config) => config.required && !String(values[config.name] ?? "").trim());
    const missingGitFields = isGit && gitSource === "account"
      ? [{ name: "git_account_id", label: t("gitAccount") }, { name: "repository", label: t("repository") }].filter((field) => !String(values[field.name] ?? "").trim())
      : isGit && !String(values.repository_url ?? "").trim() ? [{ name: "repository_url", label: t("publicRepository") }] : [];
    if (missingFields.length || missingGitFields.length) {
      [...missingFields, ...missingGitFields].forEach((field) => form.setError(field.name, { type: "manual", message: t("form.requiredField", { field: field.label }) }));
      return;
    }
    const payload = { site_type: values.site_type, name: values.name.trim(), domain: values.domain.trim(), system_user_id: Number(values.system_user_id) };
    const settings = {};
    for (const config of selected?.fields ?? []) {
      const value = values[config.name];
      if (value === undefined || value === "" || COMMON_FIELD_NAMES.has(config.name)) continue;
      if (TOP_LEVEL_FIELDS.has(config.name)) payload[config.name] = config.type === "number" ? Number(value) : value;
      else settings[config.name] = value;
    }
    if (isGit) {
      payload.git_source = gitSource;
      if (gitSource === "account") { payload.git_account_id = Number(values.git_account_id); payload.repository = values.repository; }
      else payload.repository_url = values.repository_url?.trim();
      if (values.branch?.trim()) payload.branch = values.branch.trim();
    }
    if (Object.keys(settings).length) payload.settings = settings;

    try {
      const { data } = await createApplication(payload);
      toast.success(t("created"));
      router.push(data?.application?.id ? `/applications/${data.application.id}` : "/applications");
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  return <Form {...form}>
    <form onSubmit={form.handleSubmit(onSubmit)} noValidate className="mx-auto max-w-6xl">
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="space-y-6">
      <section className="space-y-3 rounded-xl border bg-card p-4 shadow-sm sm:p-5" aria-labelledby="application-type-heading">
        <SectionHeading number="1" title={t("guided.stageType")} description={t("guided.typeHint")} />
        <FormField control={form.control} name="site_type" render={({ field }) => <FormItem><div id="application-type-heading"><SiteTypePicker types={siteTypes} value={field.value} onChange={field.onChange} /></div><FormMessage /></FormItem>} />
      </section>

      <section className="space-y-4 rounded-xl border bg-card p-4 shadow-sm sm:p-5" aria-labelledby="application-details-heading">
        <SectionHeading number="2" title={t("form.detailsTitle")} description={t("form.detailsHint")} />
        <div className="grid items-start gap-4 md:grid-cols-2">
          <FormField control={form.control} name="name" render={({ field }) => <FormItem><FormLabel>{t("name")}</FormLabel><FormControl><Input autoComplete="off" placeholder={t("form.namePlaceholder")} {...field} /></FormControl><FormMessage /></FormItem>} />
          <FormField control={form.control} name="domain" render={({ field }) => <FormItem><FormLabel>{t("domain")}</FormLabel><FormControl><Input inputMode="url" autoComplete="url" placeholder={t("form.domainPlaceholder")} {...field} /></FormControl><FormMessage /></FormItem>} />
          <FormField control={form.control} name="system_user_id" render={({ field }) => <FormItem className="md:col-span-2"><FormLabel>{t("systemUser")}</FormLabel><Select onValueChange={field.onChange} value={field.value === undefined ? "" : String(field.value)}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("systemUserPlaceholder")} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{availableSystemUsers.map((user) => <SelectItem key={user.id} value={String(user.id)}>{user.username}</SelectItem>)}</SelectContent></Select>{availableSystemUsers.length === 0 ? <div className="flex flex-wrap items-center justify-between gap-2"><FormDescription className={systemUsersFailed ? "text-destructive" : undefined}>{systemUsersFailed ? t("form.systemUsersUnavailable") : t("form.noSystemUsers")}</FormDescription>{canCreateSystemUser ? <Button type="button" size="sm" variant="outline" onClick={() => setSystemUserDialogOpen(true)}>{t("form.createSystemUser")}</Button> : <FormDescription className="text-destructive">{t("form.noSystemUserCreatePermission")}</FormDescription>}</div> : null}<FormMessage /></FormItem>} />
        </div>
      </section>

      <section className="space-y-4 rounded-xl border bg-card p-4 shadow-sm sm:p-5" aria-labelledby="application-configure-heading">
        <SectionHeading number="3" title={t("guided.stageConfigure")} description={selected ? t("guided.configureHint") : t("form.chooseTypeHint")} />
        {selected ? <div className="space-y-5" id="application-configure-heading">
          {isGit ? <div className="space-y-4 border-b pb-5"><div><p className="font-medium">{t("source")}</p><p className="mt-1 text-sm leading-5 text-muted-foreground">{t("form.repositoryHint")}</p></div><ChoiceField value={gitSource} onChange={setGitSource} options={[{ value: "account", label: t("useAccount") }, { value: "public_url", label: t("usePublicUrl") }]} />
            {gitSource === "account" ? <div className="grid items-start gap-4 md:grid-cols-2"><FormField control={form.control} name="git_account_id" render={({ field }) => <FormItem><FormLabel>{t("gitAccount")}</FormLabel><Select onValueChange={handleGitAccountChange} value={field.value === undefined ? "" : String(field.value)} disabled={!gitAccounts.length}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("gitAccountPlaceholder")} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{gitAccounts.map((account) => <SelectItem key={account.id} value={String(account.id)}>{account.label} · {account.provider_title}</SelectItem>)}</SelectContent></Select>{gitAccountsFailed ? <FormDescription className="text-destructive">{t("loadFailed")}</FormDescription> : !gitAccounts.length ? <div className="flex flex-wrap items-center justify-between gap-2"><FormDescription className="text-destructive">{t("noAccounts")}</FormDescription><Button size="sm" variant="outline" asChild><Link href="/integrations/git" target="_blank" rel="noreferrer">{t("connectGit")}</Link></Button></div> : null}<FormMessage /></FormItem>} />
              <FormField control={form.control} name="repository" render={({ field }) => <FormItem><FormLabel>{t("repository")}</FormLabel><Select onValueChange={handleRepositoryChange} value={field.value ?? ""} disabled={!gitAccountId || repositoriesState === "loading" || repositoriesState === "empty" || repositoriesState === "error"}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("repositoryPlaceholder")} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{repositories.map((item) => <SelectItem key={item.full_name} value={item.full_name}>{item.full_name}</SelectItem>)}</SelectContent></Select><PickerStatus state={repositoriesState} messages={{ loading: t("form.repositoriesLoading"), empty: t("form.repositoriesEmpty"), error: t("form.repositoriesFailed") }} /><FormMessage /></FormItem>} />
              <FormField control={form.control} name="branch" render={({ field }) => <FormItem className="md:col-span-2"><FormLabel>{t("branch")}</FormLabel><Select onValueChange={field.onChange} value={field.value ?? ""} disabled={!repository || branchesState === "loading" || branchesState === "empty" || branchesState === "error"}><FormControl><SelectTrigger className="w-full"><SelectValue placeholder={t("branchPlaceholder")} /></SelectTrigger></FormControl><SelectContent className="max-h-64">{branches.map((item) => <SelectItem key={item.name} value={item.name}>{item.name}</SelectItem>)}</SelectContent></Select><PickerStatus state={branchesState} messages={{ loading: t("form.branchesLoading"), empty: t("form.branchesEmpty"), error: t("form.branchesFailed") }} />{branchesState === "ready" ? <FormDescription>{t("form.branchHint")}</FormDescription> : null}<FormMessage /></FormItem>} />
            </div> : <div className="grid gap-4 md:grid-cols-2"><FormField control={form.control} name="repository_url" render={({ field }) => <FormItem><FormLabel>{t("publicRepository")}</FormLabel><FormControl><Input type="url" placeholder="https://github.com/owner/repository.git" {...field} /></FormControl><FormDescription>{t("publicRepositoryHint")}</FormDescription><FormMessage /></FormItem>} /><FormField control={form.control} name="branch" render={({ field }) => <FormItem><FormLabel>{t("branch")}</FormLabel><FormControl><Input placeholder={t("branchPlaceholder")} {...field} /></FormControl><FormMessage /></FormItem>} /></div>}
          </div> : null}
          {standardFields.length ? <div className="grid items-start gap-4 md:grid-cols-2">{standardFields.map((config) => <ConfigField key={config.name} config={config} form={form} accounts={gitAccounts} phpVersions={phpVersions} phpVersionsFailed={phpVersionsFailed} nodeVersions={nodeVersions} nodeVersionsFailed={nodeVersionsFailed} />)}</div> : null}
          {advancedFields.length ? <Collapsible className="border-t pt-4"><CollapsibleTrigger asChild><Button type="button" variant="ghost" className="w-full justify-between rounded-lg px-3 hover:bg-muted/60 data-[state=open]:bg-muted/60"><span className="flex items-center gap-2"><Sparkles className="size-4 text-primary" />{t("advanced")}</span><ChevronDown className="size-4" /></Button></CollapsibleTrigger><CollapsibleContent className="grid items-start gap-4 pt-4 md:grid-cols-2">{advancedFields.map((config) => <ConfigField key={config.name} config={config} form={form} accounts={gitAccounts} phpVersions={phpVersions} phpVersionsFailed={phpVersionsFailed} nodeVersions={nodeVersions} nodeVersionsFailed={nodeVersionsFailed} />)}</CollapsibleContent></Collapsible> : null}
        </div> : <p id="application-configure-heading" className="rounded-lg border border-dashed bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground">{t("form.chooseTypeHint")}</p>}
      </section>

      <div className="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm text-muted-foreground">{selected ? t("guided.reviewHint") : t("form.chooseTypeHint")}</p><div className="flex gap-2"><Button type="button" variant="outline" asChild><Link href="/applications">{t("cancel")}</Link></Button><Button type="submit" disabled={!selected || form.formState.isSubmitting}>{form.formState.isSubmitting ? <Loader2 className="size-4 animate-spin" /> : <ArrowRight className="size-4" />}{form.formState.isSubmitting ? t("creating") : t("createAction")}</Button></div></div>
        </div>
        <aside className="lg:sticky lg:top-20">{selected ? <CreateReadinessPanel items={readinessItems} /> : null}</aside>
      </div>
    </form>
    <CreateSystemUserDialog
      open={systemUserDialogOpen}
      onOpenChange={setSystemUserDialogOpen}
      onCreated={(user) => {
        if (!user?.id) return;
        setCreatedSystemUsers((current) => [...current, user]);
        form.setValue("system_user_id", String(user.id), { shouldDirty: true, shouldValidate: true });
      }}
    />
  </Form>;
}
