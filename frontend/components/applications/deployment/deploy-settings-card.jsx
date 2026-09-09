import { useEffect, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import { RotateCcw } from "lucide-react";
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
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * What a deploy actually does — and, until now, the part of it nobody could
 * change after the site was created.
 *
 * The create form accepts a deploy script; there was no screen to edit one
 * afterwards, so a script that turned out wrong meant recreating the site.
 *
 * `deploy_script` comes back filled even when the user has written nothing —
 * it falls back to the old build command. `deploy_script_customised` is what
 * separates "their script" from "the fallback", and it is the difference
 * between offering Reset and pretending someone else's text is theirs.
 */
export function DeploySettingsCard({ applicationId, application, settings, canManage }) {
  const t = useTranslations("applications.deployment.settings");
  const router = useRouter();
  const [saving, setSaving] = useState(false);
  // Only the resolved outcomes live in state. "loading" and "idle" are facts
  // about the props, so deriving them keeps the effect free of the synchronous
  // setState that would otherwise run on every render for a site with no
  // account — see the react-hooks/set-state-in-effect rule.
  const [resolved, setResolved] = useState(null);

  /*
   * Typing a branch name is guessing. The provider knows them, this account can
   * list them, and the create form has asked for exactly this list since the
   * day it was written — the edit screen simply never caught up.
   *
   * Fetched here rather than on the server: the deployment page renders for
   * every git site, and most visits never touch this field. One request that a
   * dead credential can fail is not worth putting in front of the whole screen.
   */
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
  const notice = branchFieldNotice({ application, state: branchesState });

  const form = useForm({
    resolver: zodResolver(deploySettingsFormSchema),
    mode: "onBlur",
    defaultValues: {
      branch: settings.branch ?? "main",
      deploy_script: settings.deploy_script ?? "",
    },
  });

  // Without this a sidebar click throws the edit away silently.
  useWatchUnsaved("app-deploy-settings", form.formState.isDirty);

  const script = useWatch({ control: form.control, name: "deploy_script" });
  const isDefault = script === (settings.default_deploy_script ?? "");

  // The branch as typed, not as saved: the placeholder list is read while
  // writing the script, and naming the old branch there is worse than naming
  // none — it describes a deploy that is about to stop being true.
  const branchNow = useWatch({ control: form.control, name: "branch" });

  /*
   * What each token expands to on THIS site.
   *
   * The list of tokens alone answered "what may I write" and left "what will
   * it become" to be guessed — and `{path}` is the one people get wrong,
   * because a site's document root is not its directory.
   *
   * Only tokens whose value is actually known get one. A token the backend
   * adds later still lists, without an invented value beside it.
   */
  const placeholderValues = {
    "{path}": application?.document_root,
    "{branch}": branchNow || "main",
    "{domain}": application?.domain,
  };

  async function save(values) {
    setSaving(true);
    try {
      await updateDeploySettings(applicationId, values);
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
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
        <form onSubmit={form.handleSubmit(save)}>
          <Card className="gap-0 overflow-hidden py-0 shadow-sm">
            <CardContent className="space-y-5 px-5 py-5">
              <FormField
                control={form.control}
                name="branch"
                render={({ field }) => (
                  <FormItem className="min-w-0">
                    <FormLabel>{t("branch")}</FormLabel>
                    <FormControl>
                      {/* The list when we can be sure of it, free text when we
                          cannot — see lib/applications/branch-picker.js. The
                          one thing never rendered is an empty disabled picker,
                          which reads as "your branch is gone". */}
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
                    </FormControl>
                    <FormDescription
                      className={notice === "error" || notice === "unlinked" ? "text-destructive" : undefined}
                    >
                      {notice ? t(`branchNotice.${notice}`) : t("branchHint")}
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
  
              <FormField
                control={form.control}
                name="deploy_script"
                render={({ field }) => (
                  <FormItem className="min-w-0">
                    <div className="flex min-h-6 flex-wrap items-center justify-between gap-2">
                      <FormLabel>{t("script")}</FormLabel>
                      {/* Only worth offering once it differs from the default —
                          otherwise it is a button that does nothing. */}
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
                    <FormControl>
                      <Textarea
                        {...field}
                        rows={10}
                        spellCheck={false}
                        disabled={!canManage || saving}
                        className="font-mono text-xs"
                      />
                    </FormControl>
                    <FormDescription>
                      {settings.deploy_script_customised ? t("scriptHint") : t("scriptFallbackHint")}
                      {settings.placeholders?.length ? (
                        <span className="mt-1.5 block space-y-0.5">
                          <span className="block">{t("placeholders")}</span>
                          {settings.placeholders.map((token) => (
                            <span key={token} className="flex flex-wrap items-baseline gap-1.5">
                              <code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">
                                {token}
                              </code>
                              {placeholderValues[token] ? (
                                <>
                                  <span aria-hidden>→</span>
                                  {/* Breaks anywhere: a document root is long
                                      and unbroken, and letting it push the card
                                      wide is worse than letting it wrap. */}
                                  <span className="min-w-0 font-mono text-[11px] break-all text-foreground">
                                    {placeholderValues[token]}
                                  </span>
                                </>
                              ) : null}
                            </span>
                          ))}
                        </span>
                      ) : null}
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
  
            <CardSaveFooter
              submit
              saving={saving}
              dirty={form.formState.isDirty}
              saveReason={
                !canManage ? t("noPermission") : !form.formState.isDirty ? t("nothingToSave") : null
              }
              onDiscard={() =>
                form.reset({
                  branch: settings.branch ?? "main",
                  deploy_script: settings.deploy_script ?? "",
                })
              }
              saveLabel={t("save")}
              note={t("saveNote")}
            />
          </Card>
        </form>
      </Form>
    </DisabledReasonProvider>
  );
}
