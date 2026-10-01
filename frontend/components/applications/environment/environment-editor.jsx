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
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

// Must not change: a new N8N_ENCRYPTION_KEY makes every saved n8n credential
// unreadable; port and folder must match the service and proxy. The API only checks syntax.
const GUARDED_KEYS = ["N8N_ENCRYPTION_KEY", "N8N_PORT", "N8N_USER_FOLDER"];

function envValues(text) {
  const values = new Map();
  for (const line of String(text ?? "").split("\n")) {
    const match = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/.exec(line);
    if (match) values.set(match[1], match[2].replace(/^(["'])(.*)\1$/, "$2"));
  }
  return values;
}

function guardedChanges(saved, next) {
  const before = envValues(saved);
  const after = envValues(next);
  return GUARDED_KEYS.filter((key) => before.has(key) && before.get(key) !== after.get(key));
}

// Keeps any indent and `export ` prefix.
function applySuggestion(text, key, suggested) {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const line = new RegExp(`^(\\s*)(export\\s+)?${escaped}\\s*=.*$`, "m");
  const replacement = `$1$2${key}=${suggested}`;
  if (line.test(text)) return text.replace(line, replacement);
  const sep = text.length && !text.endsWith("\n") ? "\n" : "";
  return `${text}${sep}${key}=${suggested}\n`;
}

// The API's `max:262144` on `raw`, checked here so the error talks about size, not syntax.
const MAX_CHARS = 262144;

function overLimit(text) {
  // `.length` (UTF-16 units) is a cheap upper bound; count code points only past it.
  return text.length > MAX_CHARS && Array.from(text).length > MAX_CHARS;
}

// The API saves an emptied file as a single newline; show it as empty.
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
  const [guarded, setGuarded] = useState([]);

  // Picks up a file changed elsewhere (useState ignores later props). `seenRaw` must
  // track only the prop, never the saved text, or the stale prop is copied back in.
  const propRaw = initialEnv.raw ?? "";
  const [seenRaw, setSeenRaw] = useState(propRaw);

  if (propRaw !== seenRaw) {
    setSeenRaw(propRaw);
    setEnv(initialEnv);
    // A refresh confirming this editor's own save leaves the text alone.
    if (propRaw !== (env.raw ?? "")) {
      setContents(editable(propRaw));
      setSyntaxError(null);
    }
  }

  const dirty = contents !== editable(env.raw);
  const tooLarge = overLimit(contents);

  // The panel guard covers in-app navigation as well as reload/close.
  useWatchUnsaved("environment-editor", dirty);

  // Says whether the save also restarts, since otherwise the app may ignore the new file.
  const sendRestart = Boolean(env.requires_restart);
  const saveLabel = env.requires_restart
    ? t("saveRestart")
    : env.requires_apply
      ? t("saveApply")
      : t("save");

  async function onSave({ confirmed = false } = {}) {
    if (!dirty || saving || tooLarge) return;
    const touched = guardedChanges(env.raw, contents);
    if (touched.length && !confirmed) {
      setGuarded(touched);
      return;
    }
    setGuarded([]);
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
        // Keep anything typed while the request ran as unsaved changes.
        setContents((current) => (current === sent ? editable(next.raw ?? sent) : current));
      }
      toast.success(
        data?.restarted
          ? t("savedRestarted")
          : data?.applied
            ? t("savedApplied")
            : t("saved"),
      );

      // The textarea keeps its own state, so it does not flicker.
      router.refresh();
    } catch (error) {
      // Syntax errors come back verbatim under errors.raw; nothing was written.
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
        {/* Checks are backend-localised; shown verbatim. */}
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
              {/* Whitespace-only counts as empty so CopyButton hides itself. */}
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
          // Nothing was written; show the backend's own message.
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
            <Button onClick={() => onSave()} disabled={!dirty || saving || tooLarge}>
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

      <ConfirmDialog
        open={guarded.length > 0}
        onOpenChange={(next) => !next && setGuarded([])}
        icon={TriangleAlert}
        tone="destructive"
        title={t("guarded.title")}
        description={t("guarded.description", { keys: guarded.join(", ") })}
        cancelLabel={t("guarded.cancel")}
        confirmLabel={t("guarded.confirm")}
        onConfirm={() => onSave({ confirmed: true })}
      />

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
            // A restore also writes a history row.
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
