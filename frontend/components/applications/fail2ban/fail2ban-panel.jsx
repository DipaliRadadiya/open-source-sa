"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  FileCode2,
  Loader2,
  ScrollText,
  ShieldCheck,
  ShieldOff,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { fail2banConfigFormSchema, missingPlaceholders } from "@/lib/schemas/application-fail2ban";
import { deleteApplicationFail2ban, saveApplicationFail2ban } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";

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

// Edits the two raw INI files the backend writes verbatim to `/etc/fail2ban/{jail,filter}.d/`.
export function Fail2banPanel({ appId, config: serverConfig, jailTemplate, filterTemplate, canManage }) {
  const t = useTranslations("applications.fail2ban");
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
        message: t(
          issue?.message === "max65535" ? "tooLong" : "invalidConfig",
        ),
        output: "",
      });
      return;
    }

    setSaving(true);
    setTestError(null);
    try {
      await saveApplicationFail2ban(appId, draft);
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
      await deleteApplicationFail2ban(appId);
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
    setTestError(null);
    // Otherwise the setup form opens with the deleted text; return to the
    // empty state instead.
    setEditing(false);
    setSavedBaseline(null);
    setDraft({ jail: jailTemplate, filter: filterTemplate });
    setOverride(null);
    toast.success(t("removed"));
    router.refresh();
  }

  if (!config && !editing) {
    return (
      <div className="max-w-4xl">
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
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
            ) : (
              <p className="mx-auto max-w-md text-xs text-muted-foreground">{t("noPermission")}</p>
            )}
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="max-w-4xl space-y-4">
      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="flex flex-wrap items-center gap-3 px-5 py-4">
          <span
            className={cn(
              "flex size-9 shrink-0 items-center justify-center rounded-lg",
              config ? "bg-success/10" : "bg-muted",
            )}
          >
            {config ? (
              <ShieldCheck className="size-4.5 text-success" />
            ) : (
              <ShieldOff className="size-4.5 text-muted-foreground" />
            )}
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
            <p className="text-sm text-muted-foreground">
              {config ? t("state.onBody") : t("state.setupBody")}
            </p>
          </div>
          {/* Destructive (tinted) variant: this tears down the active jail. The
              icon carries the meaning too, so it does not rely on colour. */}
          {config && canManage ? (
            <Button
              variant="destructive"
              className="shrink-0"
              onClick={() => setConfirmRemove(true)}
              // Not during a save: concurrent requests race on whether
              // protection ends up on.
              disabled={removing || saving}
            >
              {removing ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <ShieldOff className="size-4" />
              )}
              {t("removeAction")}
            </Button>
          ) : null}
        </CardContent>
      </Card>

      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <Tabs value={tab} onValueChange={setTab} className="gap-0">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
            {/* Scrolls rather than wraps, like the Settings tab bar; ScrollFade
                signals more content to the side. */}
            <ScrollFade className="-mx-1 px-1 pb-1">
              <TabsList className="!h-auto w-fit gap-1 p-1">
                {FILES.map(({ key, icon: Icon }) => (
                  <TabsTrigger key={key} value={key} className="gap-2 px-3 py-1.5">
                    <Icon className="size-4" />
                    {t(`files.${key}`)}
                    {draft[key] !== baseline[key] ? (
                      <span
                        className="size-1.5 rounded-full bg-warning"
                        aria-label={t("unsavedHere")}
                      />
                    ) : null}
                  </TabsTrigger>
                ))}
              </TabsList>
            </ScrollFade>
            <p className="text-xs text-muted-foreground">{t(`files.${tab}Hint`)}</p>
          </div>

          {/* `filter` names the other tab's file, and that filename is fixed
              by the server. */}
          <p className="border-b bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground">
            {t("placeholderNote")}
          </p>

          {FILES.map(({ key, filename }) => (
            <TabsContent key={key} value={key} forceMount hidden={tab !== key} className="mt-0">
              {/* `h-full` on the editor plus `overflow-hidden` on the box, as in
                  the file editor; otherwise CodeMirror sizes to its content. */}
              <div className="h-80 overflow-hidden border-b" aria-label={filename}>
                <CodeEditor
                  filename={filename}
                  value={draft[key]}
                  readOnly={!canManage || saving}
                  onChange={(value) => setDraft((current) => ({ ...current, [key]: value }))}
                  className="h-full"
                />
              </div>
            </TabsContent>
          ))}

          {lostPlaceholders.length > 0 ? (
            <p className="flex items-start gap-2.5 border-b bg-warning/10 px-5 py-3 text-sm">
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
            <div className="flex items-start gap-2.5 border-b border-destructive/30 bg-destructive/5 px-5 py-3.5">
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

          <CardSaveFooter
            saving={saving}
            dirty={dirty}
            onSave={save}
            // During setup, Discard returns to the empty state (there is no
            // saved template to reset to).
            onDiscard={() => {
              setDraft(saved);
              setTestError(null);
              if (isSetup) setEditing(false);
            }}
            saveReason={
              !canManage
                ? t("noPermission")
                : empty
                  ? t("bothRequired")
                  : !dirty
                    ? t("nothingToSave")
                    : null
            }
            saveLabel={isSetup ? t("createAction") : t("saveAction")}
            note={t("saveNote")}
            savingNote={t("savingNote")}
            showReason
          />
        </Tabs>
      </Card>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        icon={TriangleAlert}
        tone="destructive"
        title={t("removeTitle")}
        // Removing also discards unsaved edits, so the dialog says so.
        description={
          unsavedFiles > 0 ? t("removeBodyDirty", { count: unsavedFiles }) : t("removeBody")
        }
        confirmLabel={t("removeConfirm")}
        pending={removing}
        onConfirm={remove}
      />
    </div>
  );
}
