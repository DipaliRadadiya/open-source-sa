"use client";

import { useEffect, useState } from "react";
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
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
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

export function EnvironmentEditor({ appId, initialEnv, canManage = false }) {
  const t = useTranslations("applications.environment");
  const [env, setEnv] = useState(initialEnv);
  const [contents, setContents] = useState(initialEnv.raw ?? "");
  const [saving, setSaving] = useState(false);
  const [syntaxError, setSyntaxError] = useState(null);
  const [restoreOpen, setRestoreOpen] = useState(false);

  const dirty = contents !== (env.raw ?? "");

  // Warn on reload/close with unsaved edits — the only guard the App Router
  // gives us for free. In-app navigation is a Link away and rare here.
  useEffect(() => {
    if (!dirty) return undefined;
    const onBeforeUnload = (e) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [dirty]);

  // The button must say what the save will actually do — otherwise a Node app
  // ignores the file until restart, or a cached config quietly overrides it.
  const sendRestart = Boolean(env.requires_restart);
  const saveLabel = env.requires_restart
    ? t("saveRestart")
    : env.requires_apply
      ? t("saveApply")
      : t("save");

  async function onSave() {
    if (!dirty || saving) return;
    setSaving(true);
    setSyntaxError(null);
    try {
      const data = await saveEnvironment(appId, {
        raw: contents,
        restart: sendRestart,
      });
      const next = data?.environment;
      if (next) {
        setEnv(next);
        setContents(next.raw ?? contents);
      }
      toast.success(
        data?.restarted
          ? t("savedRestarted")
          : data?.applied
            ? t("savedApplied")
            : t("saved"),
      );
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
    setContents(env.raw ?? "");
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
              <span className="truncate font-mono text-[11px] text-console-muted">
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
                  className="h-7 gap-1.5 px-2 text-[11px] text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
                >
                  <History className="size-3.5" />
                  {t("restore.action")}
                </Button>
              ) : null}
              <CopyButton
                value={contents}
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
            placeholder={env.exists ? undefined : t("emptyPlaceholder")}
            className="console-scroll h-96 resize-none rounded-none border-0 bg-console font-mono text-xs leading-6 text-console-foreground caret-console-foreground shadow-none selection:bg-console-foreground/20 focus-visible:ring-0 dark:bg-console"
            aria-label={t("sectionTitle")}
          />
        </div>

        {/* The site's config was NOT changed — say so in the backend's words. */}
        {syntaxError ? (
          <div className="overflow-hidden rounded-lg border border-destructive/30 bg-destructive/5">
            <div className="border-b border-destructive/20 px-3 py-1.5 text-[11px] uppercase tracking-wide text-destructive">
              {t("syntaxTitle")}
            </div>
            <pre className="console-scroll max-h-40 overflow-auto p-3 font-mono text-xs leading-6 text-destructive">
              {syntaxError}
            </pre>
          </div>
        ) : null}

        {!canManage ? (
          <p className="text-sm text-muted-foreground">{t("readOnly")}</p>
        ) : null}
      </CardContent>

      {canManage ? (
        <CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 py-4">
          <p className="text-xs text-muted-foreground">{footerNote}</p>
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={revert} disabled={!dirty || saving}>
              <Undo2 className="size-4" />
              {t("revert")}
            </Button>
            <Button onClick={onSave} disabled={!dirty || saving}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {saveLabel}
              {saving ? null : (
                <ShortcutHint letter="S" className="ms-1 border-primary-foreground/25 bg-primary-foreground/15 text-primary-foreground/80" />
              )}
            </Button>
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
              setContents(next.raw ?? "");
              setSyntaxError(null);
            }
          }}
        />
      ) : null}
    </Card>
  );
}
