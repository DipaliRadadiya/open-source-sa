"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Loader2,
  History,
  Undo2,
  TriangleAlert,
  CircleAlert,
  Wand2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { saveEnvironment } from "@/lib/api/environment";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ShortcutHint } from "@/components/ui/shortcut-hint";
import { Badge } from "@/components/ui/badge";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { RestoreBackupDialog } from "@/components/applications/environment/restore-backup-dialog";

// Rewrite (or append) a KEY's line to the suggested value — the one-click fix
// behind a check. Matches an optional `export ` and leading indent; leaves the
// rest of the file untouched.
function applySuggestion(text, key, suggested) {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const line = new RegExp(`^(\\s*)(export\\s+)?${escaped}\\s*=.*$`, "m");
  const replacement = `$1$2${key}=${suggested}`;
  if (line.test(text)) return text.replace(line, replacement);
  const sep = text.length && !text.endsWith("\n") ? "\n" : "";
  return `${text}${sep}${key}=${suggested}\n`;
}

// The API's own limit (`max:262144` on `raw`, counted in characters). Checked
// here so an oversized file is refused before it is sent, in words about size —
// the server's 422 arrived in the box titled "syntax error".
const MAX_CHARS = 262144;

function overLimit(text) {
  // `.length` counts UTF-16 units, never fewer than characters; only a file
  // that is over by that measure is worth counting properly.
  return text.length > MAX_CHARS && Array.from(text).length > MAX_CHARS;
}

// A file of only blank lines is shown as empty, so its placeholder says what
// to do with it. The API writes an emptied file back as a single newline, and
// a black box holding one invisible line said nothing.
function editable(raw) {
  return (raw ?? "").trim() ? raw : "";
}

export function EnvironmentEditor({ appId, initialEnv, canManage = false }) {
  const t = useTranslations("applications.environment");
  const tc = useTranslations("common");
  const router = useRouter();
  const [env, setEnv] = useState(initialEnv);
  const [contents, setContents] = useState(editable(initialEnv.raw));
  const [saving, setSaving] = useState(false);
  const [syntaxError, setSyntaxError] = useState(null);
  const [restoreOpen, setRestoreOpen] = useState(false);

  // The file changed underneath this component.
  //
  // `useState(initialEnv)` reads its argument once and ignores it forever
  // after, so a restore from the history card below — which writes the file and
  // calls router.refresh() — re-rendered the page with the restored text and
  // left this editor showing the old one. The refresh worked; the editor was
  // not listening. Only a manual reload fixed it.
  //
  // Adjusted during render rather than in an effect: this is the sanctioned
  // React pattern for a prop-driven reset, and an effect here would be the
  // cascading render the lint rules refuse.
  //
  // `seenRaw` is the last value this prop carried, and it moves only when the
  // prop does. It used to be set to the SAVED text on save, while the prop
  // still held the old text until the refresh landed — so the very next render
  // saw a "change", copied the old file back in, and the editor showed the
  // pre-save text for a second or more after "Environment saved.", wiping
  // anything typed meanwhile.
  const propRaw = initialEnv.raw ?? "";
  const [seenRaw, setSeenRaw] = useState(propRaw);

  if (propRaw !== seenRaw) {
    setSeenRaw(propRaw);
    setEnv(initialEnv);
    // A refresh that only confirms what this editor already saved leaves the
    // text alone. Anything else is a write from elsewhere (a restore from the
    // history card), and the file on disk is the truth to show.
    if (propRaw !== (env.raw ?? "")) {
      setContents(editable(propRaw));
      setSyntaxError(null);
    }
  }

  const dirty = contents !== editable(env.raw);
  const tooLarge = overLimit(contents);

  // Registered with the panel's guard, which asks before the sidebar, header
  // or breadcrumb leave and covers reload/close too. A beforeunload of its own
  // covered only the last two: a click on another page in the sidebar threw the
  // edits away without a word.
  useWatchUnsaved("environment-editor", dirty);

  // The button must say what the save will actually do — otherwise a Node app
  // ignores the file until restart, or a cached config quietly overrides it.
  const sendRestart = Boolean(env.requires_restart);
  const saveLabel = env.requires_restart
    ? t("saveRestart")
    : env.requires_apply
      ? t("saveApply")
      : t("save");

  async function onSave() {
    if (!dirty || saving || tooLarge) return;
    const sent = contents;
    setSaving(true);
    setSyntaxError(null);
    try {
      const data = await saveEnvironment(appId, {
        raw: sent,
        restart: sendRestart,
      });
      const next = data?.environment;
      if (next) {
        setEnv(next);
        // Only if nothing was typed while the request ran; otherwise those
        // keystrokes stay, as unsaved changes on top of the saved file.
        setContents((current) => (current === sent ? editable(next.raw ?? sent) : current));
      }
      toast.success(
        data?.restarted
          ? t("savedRestarted")
          : data?.applied
            ? t("savedApplied")
            : t("saved"),
      );

      // The save just wrote a row this page renders from the server — the
      // change history below. Updating local state alone leaves that card
      // showing the file's past as of page load, missing the edit the user is
      // looking at the toast for. Cheap here: the editor keeps the response's
      // own copy, so the textarea does not flicker or lose the cursor.
      router.refresh();
    } catch (error) {
      // Syntax errors come back verbatim under errors.raw; nothing was written
      // (the previous file stands), which is what the reader needs to know.
      const raw = error.response?.data?.errors?.raw;
      if (raw) {
        setSyntaxError(Array.isArray(raw) ? raw.join("\n") : String(raw));
      } else {
        toast.error(apiMessage(error, t("saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  function revert() {
    setContents(editable(env.raw));
    setSyntaxError(null);
  }

  function onEditorKeyDown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "s") {
      e.preventDefault();
      if (canManage) onSave();
    }
  }

  const footerNote = dirty
    ? t("unsaved")
    : env.requires_restart
      ? t("restartHint")
      : env.requires_apply
        ? t("applyHint")
        : null;

  return (
    <Card>
      <CardContent className="space-y-4">
        {/* Checks — rendered verbatim (backend-localized), styled by severity,
            each with a one-click fix when it carries a suggested value. */}
        {env.checks?.length ? (
          <div className="space-y-2">
            {env.checks.map((check, i) => {
              const isError = check.severity === "error";
              const Icon = isError ? CircleAlert : TriangleAlert;
              return (
                <div
                  key={`${check.code}-${i}`}
                  className={cn(
                    "flex flex-wrap items-start gap-2.5 rounded-lg border p-3 text-sm",
                    isError
                      ? "border-destructive/30 bg-destructive/5"
                      : "border-warning/30 bg-warning/5",
                  )}
                >
                  <Icon
                    className={cn(
                      "mt-0.5 size-4 shrink-0",
                      isError ? "text-destructive" : "text-warning",
                    )}
                  />
                  <div className="min-w-0 flex-1">
                    <p
                      className={cn(
                        "font-medium",
                        isError ? "text-destructive" : "text-warning",
                      )}
                    >
                      {check.title}
                    </p>
                    {check.detail ? (
                      <p className="mt-0.5 text-muted-foreground">
                        {check.detail}
                      </p>
                    ) : null}
                  </div>
                  {canManage && check.key && check.suggested != null ? (
                    <Button
                      variant="outline"
                      size="sm"
                      className="shrink-0"
                      onClick={() =>
                        setContents((c) =>
                          applySuggestion(c, check.key, check.suggested),
                        )
                      }
                    >
                      <Wand2 className="size-3.5" />
                      {t("fixSet", { key: check.key, value: check.suggested })}
                    </Button>
                  ) : null}
                </div>
              );
            })}
          </div>
        ) : null}

        {/* Console surface — a machine's own file, same visual language as the
            log viewer and php.ini editor. */}
        <div className="overflow-hidden rounded-lg border border-console-border bg-console">
          <div className="flex items-center justify-between gap-2 border-b border-console-border px-3 py-1.5">
            <span className="flex min-w-0 items-center gap-2">
              <span className="truncate font-mono text-xs text-console-muted">
                {env.path ?? t("pathUnknown")}
              </span>
              {env.framework_title ? (
                <Badge
                  variant="outline"
                  className="border-console-border/60 font-normal text-console-muted"
                >
                  {env.framework_title}
                </Badge>
              ) : null}
            </span>
            <div className="flex shrink-0 items-center gap-1">
              {canManage && env.backups?.length ? (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setRestoreOpen(true)}
                  className="h-7 gap-1.5 px-2 text-xs text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                >
                  <History className="size-3.5" />
                  {t("restore.action")}
                </Button>
              ) : null}
              {/* Trimmed: CopyButton already hides itself on an empty string, but
                  a file holding only a newline is just as empty to the reader,
                  and offering to copy it ticks "copied" over nothing. */}
              <CopyButton
                value={contents.trim() ? contents : ""}
                label={t("copy")}
                className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
              />
            </div>
          </div>
          <Textarea
            value={contents}
            onChange={(e) => setContents(e.target.value)}
            onKeyDown={onEditorKeyDown}
            readOnly={!canManage}
            spellCheck={false}
            placeholder={env.exists ? t("emptyFilePlaceholder") : t("emptyPlaceholder")}
            className="console-scroll h-96 resize-none rounded-none border-0 bg-console font-mono text-xs leading-6 text-console-foreground caret-console-foreground shadow-none selection:bg-console-foreground/20 focus-visible:ring-0 dark:bg-console"
            aria-label={t("sectionTitle")}
          />
        </div>

        {tooLarge ? (
          <NotSaved title={t("tooLargeTitle")}>{t("tooLarge")}</NotSaved>
        ) : syntaxError ? (
          // The site's config was NOT changed — say so in the backend's words.
          <NotSaved title={t("syntaxTitle")}>{syntaxError}</NotSaved>
        ) : null}

        {!canManage ? (
          <p className="text-sm text-muted-foreground">{t("readOnly")}</p>
        ) : null}
      </CardContent>

      {canManage ? (
        <CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 py-4">
          <p className="text-xs text-muted-foreground">{footerNote}</p>
          <div className="flex items-center gap-2">
            <ReasonTooltip reason={!dirty && !saving ? tc("nothingToRevert") : null}>
              <Button variant="ghost" onClick={revert} disabled={!dirty || saving}>
                <Undo2 className="size-4" />
                {t("revert")}
              </Button>
            </ReasonTooltip>
            <ReasonTooltip reason={tooLarge ? t("tooLarge") : !dirty && !saving ? tc("nothingToSave") : null}>
            <Button onClick={onSave} disabled={!dirty || saving || tooLarge}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {saveLabel}
              {saving ? null : (
                <ShortcutHint letter="S" className="ms-1 border-primary-foreground/25 bg-primary-foreground/15 text-primary-foreground/80" />
              )}
            </Button>
            </ReasonTooltip>
          </div>
        </CardFooter>
      ) : null}

      {canManage && env.backups?.length ? (
        <RestoreBackupDialog
          appId={appId}
          backups={env.backups}
          requiresRestart={env.requires_restart}
          open={restoreOpen}
          onOpenChange={setRestoreOpen}
          onRestored={(next) => {
            if (next) {
              setEnv(next);
              setContents(editable(next.raw));
              setSyntaxError(null);
            }
            // A restore is a change to the file like any other and writes its
            // own history row. Same reason as the save above.
            router.refresh();
          }}
        />
      ) : null}
    </Card>
  );
}

function NotSaved({ title, children }) {
  return (
    <div className="overflow-hidden rounded-lg border border-destructive/30 bg-destructive/5">
      <div className="border-b border-destructive/20 px-3 py-1.5 text-xs uppercase tracking-wide text-destructive">
        {title}
      </div>
      <pre className="console-scroll max-h-40 overflow-auto p-3 font-mono text-xs leading-6 whitespace-pre-wrap text-destructive">
        {children}
      </pre>
    </div>
  );
}
