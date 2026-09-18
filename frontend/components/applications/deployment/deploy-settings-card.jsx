import { Fragment, useEffect, useRef, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { useRouter } from "next/navigation";
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
  const scriptRef = useRef(null);

  /*
   * Drop a token where the cursor is.
   *
   * They were a read-only list, so using one meant reading `{path}` off the
   * screen and typing it out by hand — a chance to mistype the one thing on
   * this card that has to be exact.
   */
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
    // Put the caret after what was just inserted, so typing continues there.
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(start + token.length, start + token.length);
    });
  }

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
          {/*
           * The panel's settings card, not a hand-rolled one.
           *
           * `Section`/`Row` is the locked convention five settings forms
           * already use: header band with a tinted mark, rows whose label and
           * hint sit LEFT of a fixed control column, and an action band that
           * puts the Save inside the same box as the rows it saves. This card
           * had ignored all of it and stacked label-over-control down the full
           * width of the page — which is why a four-character branch name got
           * an input elevenhundred pixels wide, and why the card read as a
           * flat form rather than as settings.
           */}
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
                  /*
                   * Two different things wearing two different tones.
                   *
                   * `loading`, `empty` and `error` describe a fallback the card
                   * has already applied — the field still works, you just type
                   * the name. As red text they read as though the save had
                   * failed. They are hints.
                   *
                   * `unlinked` is the one the reader must go and fix: no
                   * working Git account, so nothing here will deploy. That
                   * belongs in Row's `error` slot, which is the destructive
                   * one. Rewriting this card onto Row dropped the distinction
                   * entirely and made all four muted.
                   */
                  hint={notice && notice !== "unlinked" ? t(`branchNotice.${notice}`) : t("branchHint")}
                  error={notice === "unlinked" ? t("branchNotice.unlinked") : undefined}
                >
                  {/* The list when we can be sure of it, free text when we
                      cannot — see lib/applications/branch-picker.js. The one
                      thing never rendered is an empty disabled picker, which
                      reads as "your branch is gone". */}
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

            {/* `wide`: a deploy script is many lines of code and has no business
                in the 14rem control column a branch name belongs in. */}
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

/**
 * The tokens a deploy script may use, and what each expands to.
 *
 * This was four rows of mono text inside the field's FormDescription — a
 * reference table wearing a caption's clothes, which is why it read as debug
 * output left on the page. It is a small surface of its own now, and the
 * tokens are buttons: clicking one drops it at the cursor, so the one string
 * on this card that has to be exact never has to be typed.
 *
 * A token the panel cannot resolve (`{php}` on a site with no PHP) keeps its
 * row and simply has no value beside it. In the old stacked layout that left a
 * dangling arrow pointing at nothing.
 */
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
                  className="rounded bg-background px-1.5 py-0.5 font-mono text-[11px] ring-1 ring-inset ring-border transition-colors hover:bg-primary/10 hover:text-primary hover:ring-primary/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                  {token}
                </button>
              ) : (
                <code className="rounded bg-background px-1.5 py-0.5 font-mono text-[11px] ring-1 ring-inset ring-border">
                  {token}
                </code>
              )}
            </dt>
            {/* break-all: a document root is long and unbroken, and letting it
                push the card wide is worse than letting it wrap. */}
            <dd className="min-w-0 self-center font-mono text-[11px] break-all text-muted-foreground">
              {values[token] ?? ""}
            </dd>
          </Fragment>
        ))}
      </dl>
    </div>
  );
}
