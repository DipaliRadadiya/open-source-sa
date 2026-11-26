"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ChevronDown,
  FileCode2,
  Loader2,
  ScrollText,
  ShieldAlert,
  ShieldCheck,
  ShieldOff,
  SlidersHorizontal,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { fail2banConfigFormSchema, missingPlaceholders } from "@/lib/schemas/application-fail2ban";
import { deleteApplicationFail2ban, saveApplicationFail2ban } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { loginFilter, parseLoginRule, usesWordPressRule, validLoginPath } from "@/lib/applications/fail2ban-login-rule";
import { JAIL_FIELDS, readJailValue, wholeNumberIn } from "@/lib/applications/fail2ban-jail-fields";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { DENIED, DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { BanRuleFields } from "@/components/applications/fail2ban/ban-rule-fields";

// CodeMirror is large and only needed here and in the file editor, so it is loaded on demand.
const CodeEditor = dynamic(
  () => import("@/components/applications/files/code-editor").then((m) => m.CodeEditor),
  {
    ssr: false,
    loading: () => (
      <div className="flex h-full items-center justify-center bg-console">
        <Loader2 className="size-5 animate-spin text-console-muted" />
      </div>
    ),
  },
);

// fail2ban's output starts with an unrelated `allowipv6` warning; keep only ERROR lines when present.
function errorLines(output) {
  const errors = output.split("\n").filter((line) => /\bERROR\b/.test(line));
  return errors.length > 0 ? errors.join("\n") : output;
}

const FILES = [
  { key: "jail", icon: ScrollText, filename: "jail.conf" },
  { key: "filter", icon: FileCode2, filename: "filter.conf" },
];

/**
 * One status card and one "Ban rules" card: plain fields first, the two raw INI files
 * (written verbatim to `/etc/fail2ban/{jail,filter}.d/`) folded under them. The fields
 * edit the same text the files show (the page was too much at once).
 */
export function Fail2banPanel({ appId, config: serverConfig, jailTemplate, filterTemplate, canManage, loginRuleOffered = false }) {
  const t = useTranslations("applications.fail2ban");
  const tc = useTranslations("common");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  // `null` = removed here, object = created here; covers the gap until `router.refresh()` lands.
  const [override, setOverride] = useState(undefined);
  if (override !== undefined && (override === null ? !serverConfig : Boolean(serverConfig))) {
    setOverride(undefined);
  }
  const config = override === undefined ? serverConfig : override;

  const [editing, setEditing] = useState(Boolean(config));
  const [tab, setTab] = useState("jail");
  const [saving, setSaving] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  // Copy the jail and filter aside while the reload is checked. On again after each save or turn-off.
  const [safetyCopy, setSafetyCopy] = useState(true);
  // The config test's output, kept until the next attempt.
  const [testError, setTestError] = useState(null);
  const [fullOutput, setFullOutput] = useState(false);

  const saved = {
    jail: config?.jail_content ?? jailTemplate,
    filter: config?.filter_content ?? filterTemplate,
  };

  const [draft, setDraft] = useState(saved);

  // Not `saved`: Laravel's TrimStrings strips the trailing newline, so the reloaded config never matches the draft.
  const [savedBaseline, setSavedBaseline] = useState(null);
  const baseline = savedBaseline ?? saved;

  // Typed here; it becomes the filter only once it is a usable path.
  const savedRule = parseLoginRule(baseline.filter);
  const [loginPath, setLoginPath] = useState(savedRule?.path ?? "");
  const [loginAnswer, setLoginAnswer] = useState(savedRule?.answer ?? "error");

  // A jail without these keys (hand-written, or kept from v7) is edited as a file only.
  const fieldsReadable = JAIL_FIELDS.every((key) => readJailValue(baseline.jail, key) !== null);
  const [filesOpen, setFilesOpen] = useState(!fieldsReadable);

  // Nothing is saved during setup, so an untouched form must still be
  // submittable (accepting the defaults is the common case).
  const isSetup = !config;
  const unsavedFiles = FILES.filter(({ key }) => draft[key] !== baseline[key]).length;
  const changed = unsavedFiles > 0;
  const dirty = isSetup || changed;
  const empty = !draft.jail.trim() || !draft.filter.trim();
  useWatchUnsaved("app-fail2ban", canManage && changed);

  // Warned about, not blocked: the backend fills these in when it writes the
  // files, but a config that names a second logpath outright is legitimate.
  const lostPlaceholders = [
    ...missingPlaceholders(saved.jail, draft.jail),
    ...missingPlaceholders(saved.filter, draft.filter),
  ];

  // Empty is only a problem while the filter is still WordPress's, which bans nobody here.
  const pathProblem = !loginRuleOffered
    ? null
    : loginPath.trim()
      ? validLoginPath(loginPath.trim()) ? null : "invalid"
      : usesWordPressRule(draft.filter) ? "required" : null;
  // Checked once changed here: a hand-written "10m" that fail2ban accepts must not block Save.
  const badNumber = (key, min, max) => {
    const value = readJailValue(draft.jail, key);
    return value !== readJailValue(baseline.jail, key) && !wholeNumberIn(value, min, max);
  };
  const numberProblem = !fieldsReadable
    ? null
    : badNumber("maxretry", 1, 100)
      ? "maxretry"
      : badNumber("findtime", 30, 86400)
        ? "findtime"
        : null;

  function setLogin(path, answer) {
    setLoginPath(path);
    setLoginAnswer(answer);
    if (validLoginPath(path.trim())) {
      setDraft((current) => ({ ...current, filter: loginFilter(path.trim(), answer) }));
    }
  }

  function resetLogin(filter) {
    const rule = parseLoginRule(filter);
    setLoginPath(rule?.path ?? "");
    setLoginAnswer(rule?.answer ?? "error");
  }

  async function save() {
    const parsed = fail2banConfigFormSchema.safeParse({
      jail_config_content: draft.jail,
      filter_config_content: draft.filter,
    });
    if (!parsed.success) {
      // Over-length is the reachable failure (empty is blocked by the disabled
      // button); show it in the same panel as the daemon's refusal.
      const issue = parsed.error.issues[0];
      setTestError({
        message: t(issue?.message === "max65535" ? "tooLong" : "invalidConfig"),
        output: "",
      });
      return;
    }

    setSaving(true);
    setTestError(null);
    try {
      // A first setup replaces no files, so it keeps the default.
      await saveApplicationFail2ban(appId, draft, { safetyCopy: isSetup || safetyCopy });
      setSafetyCopy(true);
      // The server accepted it, so this is the saved state (whatever it trimmed).
      setSavedBaseline({ ...draft });
      if (!config) {
        setOverride({ jail_name: null, jail_content: draft.jail, filter_content: draft.filter });
      }
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      // The backend answers 500 for a config the daemon refuses, so the payload
      // decides whether this was a validation failure.
      const data = error.response?.data;
      if (data?.testOk === false) {
        // No toast: the panel renders directly above the button.
        setTestError({ message: data.message ?? t("testFailed"), output: data.output ?? "" });
        setFullOutput(false);
      } else {
        toast.error(apiMessage(error, t("saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    setRemoving(true);
    try {
      await deleteApplicationFail2ban(appId, { safetyCopy });
      removed();
    } catch (error) {
      // 422 means "already disabled" (removed elsewhere), which is the goal.
      if (error.response?.status === 422) removed();
      else toast.error(apiMessage(error, t("removeFailed")));
    } finally {
      setRemoving(false);
    }
  }

  function removed() {
    setConfirmRemove(false);
    setSafetyCopy(true);
    setTestError(null);
    // Otherwise the setup form opens with the deleted text; return to the empty state instead.
    setEditing(false);
    setSavedBaseline(null);
    setDraft({ jail: jailTemplate, filter: filterTemplate });
    resetLogin(filterTemplate);
    setOverride(null);
    toast.success(t("removed"));
    router.refresh();
  }

  if (!config && !editing) {
    return (
      <Card className="gap-0 overflow-hidden py-0">
        <CardContent className="flex flex-col items-center gap-3 px-6 py-10 text-center">
          <span className="flex size-11 items-center justify-center rounded-lg bg-muted">
            <ShieldOff className="size-5 text-muted-foreground" />
          </span>
          <div className="space-y-1.5">
            <p className="font-medium">{t("empty.title")}</p>
            <p className="mx-auto max-w-md text-sm text-muted-foreground">{t("empty.body")}</p>
          </div>
          {canManage ? (
            <Button className="mt-1" onClick={() => setEditing(true)}>
              {t("empty.action")}
            </Button>
          ) : null}
        </CardContent>
      </Card>
    );
  }

  // What the saved filter watches, in words. On another site type the WordPress
  // default bans nobody: the one thing on this page that needs attention.
  const watching = parseLoginRule(baseline.filter);
  const noRule = loginRuleOffered && usesWordPressRule(baseline.filter);
  const stateBody = !config
    ? t("state.setupBody")
    : noRule
      ? t("state.onNoRule")
      : watching
        ? t("state.onPath", { path: watching.path })
        : usesWordPressRule(baseline.filter)
          ? t("state.onWordPress")
          : t("state.onCustom");
  const tone = !config ? "muted" : noRule ? "warning" : "success";
  const StateIcon = !config ? ShieldOff : noRule ? ShieldAlert : ShieldCheck;

  return (
    <div className="space-y-4">
      <Card className="gap-0 overflow-hidden py-0">
        <CardContent className="flex flex-wrap items-center gap-3 px-5 py-4">
          <span
            className={cn(
              "flex size-9 shrink-0 items-center justify-center rounded-lg",
              tone === "success" && "bg-success/10 text-success",
              tone === "warning" && "bg-warning/10 text-warning",
              tone === "muted" && "bg-muted text-muted-foreground",
            )}
          >
            <StateIcon className="size-4.5" />
          </span>
          {/* min-w-48, not min-w-0: beside the Remove button on a phone the
              text shrank to one word per line instead of the button wrapping. */}
          <div className="min-w-48 flex-1 space-y-1">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
              {config ? t("state.on") : t("state.setup")}
              {config?.jail_name ? (
                <Badge variant="outline" className="font-mono text-xs font-normal">
                  {config.jail_name}
                </Badge>
              ) : null}
            </p>
            <p className={cn("text-sm", tone === "warning" ? "text-warning" : "text-muted-foreground")}>{stateBody}</p>
          </div>
          {config && canManage ? (
            <Button
              variant="destructive"
              className="shrink-0"
              onClick={() => setConfirmRemove(true)}
              // Not during a save: concurrent requests race on whether protection ends up on.
              disabled={removing || saving}
            >
              {removing ? <Loader2 className="size-4 animate-spin" /> : <ShieldOff className="size-4" />}
              {t("removeAction")}
            </Button>
          ) : null}
        </CardContent>
      </Card>

      <Card className="gap-0 overflow-hidden py-0">
        <CardHeader className="border-b px-5 py-4">
          <CardTitle as="h2" icon={SlidersHorizontal}>{t("rules.title")}</CardTitle>
          <CardDescription>{t("rules.description")}</CardDescription>
        </CardHeader>

        {fieldsReadable || loginRuleOffered ? (
          <DisabledReasonProvider denied={!canManage}>
            <BanRuleFields
              jail={draft.jail}
              numbers={fieldsReadable}
              onJailChange={(jail) => setDraft((current) => ({ ...current, jail }))}
              loginOffered={loginRuleOffered}
              loginPath={loginPath}
              loginAnswer={loginAnswer}
              onLoginChange={setLogin}
              pathProblem={pathProblem}
              numberProblem={numberProblem}
              disabled={!canManage || saving}
            />
          </DisabledReasonProvider>
        ) : null}

        {/* The raw files, for rules the fields cannot express. */}
        <Collapsible open={filesOpen} onOpenChange={setFilesOpen} className="border-t first:border-t-0">
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex w-full items-center gap-2.5 px-5 py-3 text-left text-sm font-medium transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            >
              <FileCode2 className="size-4 text-muted-foreground" />
              <span className="flex-1">{t("files.toggle")}</span>
              <ChevronDown className={cn("size-4 text-muted-foreground transition-transform", filesOpen && "rotate-180")} />
            </button>
          </CollapsibleTrigger>
          <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
            <Tabs value={tab} onValueChange={setTab} className="gap-0 border-t">
              <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                <ScrollFade className="-mx-1 px-1 pb-1">
                  <TabsList className="!h-auto w-fit gap-1 p-1">
                    {FILES.map(({ key, icon: Icon }) => (
                      <TabsTrigger key={key} value={key} className="gap-2 px-3 py-1.5">
                        <Icon className="size-4" />
                        {t(`files.${key}`)}
                        {draft[key] !== baseline[key] ? (
                          <span className="size-1.5 rounded-full bg-warning" aria-label={t("unsavedHere")} />
                        ) : null}
                      </TabsTrigger>
                    ))}
                  </TabsList>
                </ScrollFade>
                <p className="text-xs text-muted-foreground">{t("placeholderNote")}</p>
              </div>

              {FILES.map(({ key, filename }) => (
                <TabsContent key={key} value={key} forceMount hidden={tab !== key} className="mt-0">
                  {/* `h-full` on the editor plus `overflow-hidden` on the box, as in
                      the file editor; otherwise CodeMirror sizes to its content. */}
                  <div className="h-72 overflow-hidden" aria-label={filename}>
                    <CodeEditor
                      filename={filename}
                      value={draft[key]}
                      readOnly={!canManage || saving}
                      onChange={(value) => {
                        setDraft((current) => ({ ...current, [key]: value }));
                        // A hand-edited filter is shown as it is, never re-written by the fields.
                        if (key === "filter") resetLogin(value);
                      }}
                      className="h-full"
                    />
                  </div>
                </TabsContent>
              ))}
            </Tabs>
          </CollapsibleContent>
        </Collapsible>

        {lostPlaceholders.length > 0 ? (
          <p className="flex items-start gap-2.5 border-t bg-warning/10 px-5 py-3 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>
              {t("placeholderLost", {
                tokens: [...new Set(lostPlaceholders)].join(", "),
                count: new Set(lostPlaceholders).size,
              })}
            </span>
          </p>
        ) : null}

        {/* fail2ban's refusal, kept on screen for the next edit. */}
        {testError ? (
          <div className="flex items-start gap-2.5 border-t border-destructive/30 bg-destructive/5 px-5 py-3.5">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div className="min-w-0 space-y-1.5">
              <p className="text-sm font-medium text-destructive">{testError.message}</p>
              {testError.output ? (
                <>
                  <p className="max-h-40 overflow-auto whitespace-pre-wrap border-l-2 border-destructive/30 pl-3 font-mono text-xs leading-relaxed text-destructive/90">
                    {fullOutput ? testError.output : errorLines(testError.output)}
                  </p>
                  {errorLines(testError.output) !== testError.output ? (
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-xs"
                      onClick={() => setFullOutput((open) => !open)}
                    >
                      {fullOutput ? t("outputErrorsOnly") : t("outputShowAll")}
                    </Button>
                  ) : null}
                </>
              ) : null}
            </div>
          </div>
        ) : null}

        {/* Asked only once a saved jail would be replaced. */}
        {canManage && !isSetup && changed ? (
          <div className="space-y-2 border-t px-5 py-3">
            <SafetyCopySwitch
              id="fail2ban-safety-copy"
              compact
              checked={safetyCopy}
              onCheckedChange={setSafetyCopy}
              disabled={saving}
              label={tc("keepSettingsCopy")}
            />
            {!safetyCopy ? (
              <Caution tone="destructive">
                <p>{tc("keepSettingsCopyOff")}</p>
              </Caution>
            ) : null}
          </div>
        ) : null}

        <CardSaveFooter
          saving={saving}
          dirty={dirty}
          onSave={save}
          // During setup, Discard returns to the empty state (there is no saved config to reset to).
          onDiscard={() => {
            setDraft(baseline);
            resetLogin(baseline.filter);
            setTestError(null);
            if (isSetup) setEditing(false);
          }}
          saveReason={
            // "Nothing has changed" first: the login field already says what it needs.
            !canManage
              ? DENIED
              : !dirty
                ? t("nothingToSave")
                : empty
                  ? t("bothRequired")
                  : pathProblem
                    ? t(`loginRule.errors.${pathProblem}`)
                    : numberProblem
                      ? t(`rules.errors.${numberProblem}`)
                      : null
          }
          saveLabel={isSetup ? t("createAction") : t("saveAction")}
          note={t("saveNote")}
          savingNote={t("savingNote")}
          showReason
        />
      </Card>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={(next) => {
          setConfirmRemove(next);
          if (!next) setSafetyCopy(true);
        }}
        icon={TriangleAlert}
        tone="destructive"
        title={t("removeTitle")}
        // Removing also discards unsaved edits, so the dialog says so.
        description={unsavedFiles > 0 ? t("removeBodyDirty", { count: unsavedFiles }) : t("removeBody")}
        confirmLabel={t("removeConfirm")}
        pending={removing}
        onConfirm={remove}
      >
        <SafetyCopySwitch
          id="fail2ban-remove-safety-copy"
          checked={safetyCopy}
          onCheckedChange={setSafetyCopy}
          disabled={removing}
          label={tc("keepSettingsCopy")}
        />
        {!safetyCopy ? (
          <Caution tone="destructive" size="md">
            <p>{tc("keepSettingsCopyOff")}</p>
          </Caution>
        ) : null}
      </ConfirmDialog>
    </div>
  );
}
