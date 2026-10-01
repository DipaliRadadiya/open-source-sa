import { Fragment, useEffect, useRef, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { useRefresh } from "@/hooks/use-refresh";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { RotateCcw, FileCode2 } from "lucide-react";
import { deploySettingsFormSchema } from "@/lib/schemas/deploy-history";
import { updateDeploySettings } from "@/lib/api/deployment";
import { getBranches } from "@/lib/api/applications";
import { branchesResponseSchema } from "@/lib/schemas/git";
import {
  branchFieldMode,
  branchFieldNotice,
  branchOptions,
} from "@/lib/applications/branch-picker";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Row, Section, SectionActions } from "@/components/settings/setting-row";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Form,
  FormField,
} from "@/components/ui/form";

// `deploy_script` comes back filled from the build command even when unset;
// `deploy_script_customised` marks a real one and decides whether Reset is offered.
export function DeploySettingsCard({ applicationId, application, settings, canManage }) {
  const t = useTranslations("applications.deployment.settings");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);
  // Only resolved outcomes live in state; the rest is derived from props
  // (react-hooks/set-state-in-effect).
  const [resolved, setResolved] = useState(null);

  // Branches load on the client: most visits never need them, and a dead
  // credential must not fail the whole page.
  const accountId = application?.git_account_id;
  const repository = application?.repository;
  const linked = Boolean(accountId) && Boolean(repository) && !application?.git_account_missing;

  useEffect(() => {
    if (!linked) return undefined;

    let cancelled = false;
    getBranches(accountId, repository)
      .then(({ data }) => {
        if (cancelled) return;
        const parsed = branchesResponseSchema.safeParse(data);
        if (!parsed.success) {
          setResolved({ state: "error", branches: [] });
          return;
        }
        setResolved({
          state: parsed.data.branches.length ? "ready" : "empty",
          branches: parsed.data.branches,
        });
      })
      .catch(() => {
        if (!cancelled) setResolved({ state: "error", branches: [] });
      });

    return () => {
      cancelled = true;
    };
  }, [linked, accountId, repository]);

  const branchesState = linked ? (resolved?.state ?? "loading") : "idle";
  const branches = resolved?.branches ?? [];
  const mode = branchFieldMode({ application, state: branchesState, branches });
  const notice = branchFieldNotice({ application, state: branchesState, branches, current: settings.branch });

  const form = useForm({
    resolver: zodResolver(deploySettingsFormSchema),
    mode: "onBlur",
    defaultValues: {
      branch: settings.branch ?? "main",
      deploy_script: settings.deploy_script ?? "",
    },
  });

  // Without this a sidebar click silently discards the edit.
  useWatchUnsaved("app-deploy-settings", form.formState.isDirty);

  const script = useWatch({ control: form.control, name: "deploy_script" });
  const isDefault = script === (settings.default_deploy_script ?? "");

  // The branch as typed, not as saved: the placeholder list should describe the
  // deploy about to happen.
  const branchNow = useWatch({ control: form.control, name: "branch" });

  // `{path}` expands to the document root, not the site directory.
  const scriptRef = useRef(null);

  // Inserts a token at the cursor so exact values never need typing.
  function insertToken(token) {
    const el = scriptRef.current;
    const current = form.getValues("deploy_script") ?? "";
    if (!el) {
      form.setValue("deploy_script", `${current}${token}`, { shouldDirty: true });
      return;
    }
    const start = el.selectionStart ?? current.length;
    const end = el.selectionEnd ?? start;
    const next = `${current.slice(0, start)}${token}${current.slice(end)}`;
    form.setValue("deploy_script", next, { shouldDirty: true });
    // Place the caret after the inserted token.
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(start + token.length, start + token.length);
    });
  }

  const placeholderValues = {
    "{path}": application?.document_root,
    "{branch}": branchNow || "main",
    "{domain}": application?.domain,
    // The bare `php` command without a version; otherwise that version's binary,
    // whose path depends on the web server, so it is named rather than guessed.
    "{php}": application?.php_version ? `PHP ${application.php_version}` : "php",
  };

  async function save(values) {
    setSaving(true);
    try {
      await updateDeploySettings(applicationId, values);
      form.reset(values);
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      if (error.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(save)}>
          {/* Uses the shared settings `Section`/`Row` layout: label and hint left of a
              fixed control column, Save inside the card's action band. */}
          <Section
            icon={FileCode2}
            title={t("title")}
            description={t("subtitle")}
            readOnly={!canManage}
            actions={
              <SectionActions
                label={t("save")}
                isDirty={form.formState.isDirty}
                pending={saving}
                onDiscard={() =>
                  form.reset({
                    branch: settings.branch ?? "main",
                    deploy_script: settings.deploy_script ?? "",
                  })
                }
                canManage={canManage}
              />
            }
          >
            <FormField
              control={form.control}
              name="branch"
              render={({ field }) => (
                <Row
                  wide
                  label={t("branch")}
                  /* `unlinked`/`missing` must be fixed, so they use Row's destructive `error`
                     slot; the other notices describe a fallback already applied. */
                  hint={notice && notice !== "unlinked" && notice !== "missing" ? t(`branchNotice.${notice}`) : t("branchHint")}
                  error={notice === "unlinked" || notice === "missing" ? t(`branchNotice.${notice}`) : undefined}
                >
                  {/* Picker when the list is reliable, free text otherwise (see branch-picker.js);
                      never an empty disabled picker, which reads as "your branch is gone". */}
                  {mode === "picker" ? (
                    <Combobox
                      options={branchOptions(branches, field.value)}
                      value={field.value ?? ""}
                      onChange={field.onChange}
                      placeholder={t("branchPlaceholder")}
                      searchPlaceholder={t("branchSearch")}
                      disabled={!canManage || saving}
                    />
                  ) : (
                    <Input
                      {...field}
                      placeholder={t("branchPlaceholder")}
                      disabled={!canManage || saving}
                      className="font-mono text-sm"
                    />
                  )}
                </Row>
              )}
            />

            {/* `wide`: a multi-line script does not fit the 14rem control column. */}
            <FormField
              control={form.control}
              name="deploy_script"
              render={({ field }) => (
                <Row
                  wide
                  label={t("script")}
                  hint={settings.deploy_script_customised ? t("scriptHint") : t("scriptFallbackHint")}
                >
                  <div className="flex justify-end">
                    {canManage && settings.default_deploy_script && !isDefault ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 gap-1.5 px-2 text-xs"
                        onClick={() =>
                          form.setValue("deploy_script", settings.default_deploy_script, {
                            shouldDirty: true,
                          })
                        }
                      >
                        <RotateCcw className="size-3" />
                        {t("resetScript")}
                      </Button>
                    ) : null}
                  </div>
                  <Textarea
                    {...field}
                    ref={(el) => {
                      field.ref(el);
                      scriptRef.current = el;
                    }}
                    rows={10}
                    spellCheck={false}
                    disabled={!canManage || saving}
                    className="font-mono text-xs leading-relaxed"
                  />
                  {settings.placeholders?.length ? (
                    <TokenList
                      label={t("placeholders")}
                      tokens={settings.placeholders}
                      values={placeholderValues}
                      onInsert={canManage && !saving ? insertToken : null}
                    />
                  ) : null}
                </Row>
              )}
            />
          </Section>
        </form>
      </Form>
    </DisabledReasonProvider>
  );
}

// Each token inserts itself at the cursor; one the panel cannot resolve keeps its row.
function TokenList({ label, tokens, values, onInsert }) {
  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <p className="mb-2 text-xs font-medium text-muted-foreground">{label}</p>
      <dl className="grid gap-x-3 gap-y-1.5 sm:grid-cols-[auto_minmax(0,1fr)]">
        {tokens.map((token) => (
          <Fragment key={token}>
            <dt className="min-w-0">
              {onInsert ? (
                <button
                  type="button"
                  onClick={() => onInsert(token)}
                  title={token}
                  className="rounded bg-background px-1.5 py-0.5 font-mono text-xs ring-1 ring-inset ring-border transition-colors hover:bg-primary/10 hover:text-primary hover:ring-primary/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                  {token}
                </button>
              ) : (
                <code className="rounded bg-background px-1.5 py-0.5 font-mono text-xs ring-1 ring-inset ring-border">
                  {token}
                </code>
              )}
            </dt>
            {/* break-all: a long document root must wrap rather than widen the card. */}
            <dd className="min-w-0 self-center font-mono text-xs break-all text-muted-foreground">
              {values[token] ?? ""}
            </dd>
          </Fragment>
        ))}
      </dl>
    </div>
  );
}
