"use client";

import { useState } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
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

// CodeMirror and its language packs are a large chunk that only this screen
// and the file editor need — loaded on demand so a site that never opens
// attack protection does not pay for it.
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

const FILES = [
  { key: "jail", icon: ScrollText, filename: "jail.conf" },
  { key: "filter", icon: FileCode2, filename: "filter.conf" },
];

/**
 * One site's brute-force protection.
 *
 * This screen used to be a dashboard — a switch, two jails, a banned list,
 * counters. The backend replaced all of it with two raw INI files written
 * verbatim to `/etc/fail2ban/{jail,filter}.d/`, so it is an editor now. None
 * of the old surface has an equivalent: there is no enable flag, no jail
 * state, and no way to see or clear a ban.
 *
 * Two states, and the difference matters: a site that has never been set up
 * gets an empty state with one action, not a wall of INI it did not ask for.
 *
 * The save is also the config test. `fail2ban-client` gets the pair first and
 * its own error text comes back on rejection — so that output is the most
 * important thing this screen can render, and it is shown verbatim next to
 * the editor rather than in a toast that takes the reason away with it.
 */
export function Fail2banPanel({ appId, config, jailTemplate, filterTemplate, canManage }) {
  const t = useTranslations("applications.fail2ban");
  const router = useRouter();

  const [editing, setEditing] = useState(Boolean(config));
  const [tab, setTab] = useState("jail");
  const [saving, setSaving] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  // The config test's own words, kept until the next attempt. A rejected
  // regex is read, not glanced at.
  const [testError, setTestError] = useState(null);

  const saved = {
    jail: config?.jail_content ?? jailTemplate,
    filter: config?.filter_content ?? filterTemplate,
  };

  const [draft, setDraft] = useState(saved);

  // What "unchanged" is measured against: the server's copy at page load, or
  // whatever we last saved successfully.
  //
  // It cannot just be `saved`. Laravel trims every incoming string
  // (`TrimStrings` is in the default global middleware and this app does not
  // disable it), and both templates end in a newline — so the server stores a
  // copy that differs from what was sent by exactly that character. The draft
  // then never matched the reloaded config again and the row kept its "not
  // saved yet" dot forever, over a config that had saved perfectly.
  //
  // Any server-side normalisation does the same thing, so this is keyed on
  // "the server accepted this" rather than on guessing what it did to it.
  const [savedBaseline, setSavedBaseline] = useState(null);
  const baseline = savedBaseline ?? saved;

  // Nothing is saved yet during setup, so there is nothing to have changed
  // FROM. Comparing the draft against the template made an untouched setup
  // form read as "nothing has changed" and disabled the only button on it —
  // accepting the defaults, which is the common case, was impossible.
  const isSetup = !config;
  const unsavedFiles = FILES.filter(({ key }) => draft[key] !== baseline[key]).length;
  const changed = unsavedFiles > 0;
  const dirty = isSetup || changed;
  const empty = !draft.jail.trim() || !draft.filter.trim();

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
    if (!parsed.success) return;

    setSaving(true);
    setTestError(null);
    try {
      await saveApplicationFail2ban(appId, draft);
      // The server took it, so this is the saved state from here on — whatever
      // it stored after trimming.
      setSavedBaseline({ ...draft });
      toast.success(t("saved"));
      router.refresh();
    } catch (error) {
      // A config the daemon refuses comes back as a body, not a status worth
      // reading: the backend answers 500 for what is really a validation
      // failure, so the payload decides which of the two this was.
      const data = error.response?.data;
      if (data?.testOk === false) {
        // No toast: the panel it renders into sits directly above the button
        // that was just pressed, so a toast would say the same thing twice and
        // then take the half of it that matters away again.
        setTestError({ message: data.message ?? t("testFailed"), output: data.output ?? "" });
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
      setConfirmRemove(false);
      setTestError(null);
      // `editing` was true because a config existed. Leaving it set drops the
      // user into the setup form the moment the config disappears — still
      // holding the text they just deleted. Back to the empty state, with the
      // shipped templates ready if they change their mind.
      setEditing(false);
      setSavedBaseline(null);
      setDraft({ jail: jailTemplate, filter: filterTemplate });
      toast.success(t("removed"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("removeFailed")));
    } finally {
      setRemoving(false);
    }
  }

  // Never set up, and not being set up right now: one thing to read, one
  // thing to press.
  if (!config && !editing) {
    return (
      <div className="max-w-4xl">
        <Card className="overflow-hidden shadow-sm">
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
          <div className="min-w-0 flex-1 space-y-1">
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
          {/* Destructive, not outline. This tears down the jail that is
              currently banning attackers, and as a plain bordered button it
              read like "Manage" — the same weight as every harmless control on
              the page. The variant is deliberately the tinted one the design
              system ships (bg-destructive/10, not solid red): unmistakably
              dangerous without shouting at somebody whose site is fine.
              The icon carries the meaning too, so it does not rest on colour —
              red/green is the one pair a colour-blind reader cannot separate. */}
          {config && canManage ? (
            <Button
              variant="destructive"
              className="shrink-0"
              onClick={() => setConfirmRemove(true)}
              disabled={removing}
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
            {/* Scrolls rather than wraps, same as the Settings tab bar: a bar that
                reflows to two rows stops reading as one control. ScrollFade is what
                says there is more to the side. */}
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

          {/* Said before they edit, because the placeholders look like
              something to fill in and are the one thing that must be left. */}
          <p className="border-b bg-muted/30 px-5 py-2.5 text-xs text-muted-foreground">
            {t("placeholderNote")}
          </p>

          {FILES.map(({ key, filename }) => (
            <TabsContent key={key} value={key} forceMount hidden={tab !== key} className="mt-0">
              {/* `h-full` on the editor, `overflow-hidden` on the box — the
                  same pair the file editor uses. Without it CodeMirror sizes
                  to its content and leaves the rest of the box blank, so a
                  short file sat in a dark strip above a white gap. */}
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

          {/* fail2ban's own words about what it refused, kept on screen
              because the next edit is based on them.

              Not a console slab: given the black editor directly above it, a
              second dark monospaced box read as another file rather than as
              the reason the first one was rejected. It is quoted inside the
              message — mono for fidelity, but plainly part of the alert. */}
          {testError ? (
            <div className="flex items-start gap-2.5 border-b border-destructive/30 bg-destructive/5 px-5 py-3.5">
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
              <div className="min-w-0 space-y-1.5">
                <p className="text-sm font-medium text-destructive">{testError.message}</p>
                {testError.output ? (
                  <p className="max-h-40 overflow-auto whitespace-pre-wrap border-l-2 border-destructive/30 pl-3 font-mono text-xs leading-relaxed text-destructive/90">
                    {testError.output}
                  </p>
                ) : null}
              </div>
            </div>
          ) : null}

          <CardSaveFooter
            saving={saving}
            dirty={dirty}
            onSave={save}
            // During setup the way out is back to the empty state, not a
            // reset to a template that was never saved — otherwise the form
            // has no exit but the browser's back button.
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
            quietWhenClean
          />
        </Tabs>
      </Card>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        icon={TriangleAlert}
        tone="destructive"
        title={t("removeTitle")}
        // Removing throws away unsaved edits as well as the saved config, and
        // the two losses are not obviously the same action — so the dialog
        // counts what else is about to go rather than letting it go quietly.
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
