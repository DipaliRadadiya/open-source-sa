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
import { checkEnvironment, saveEnvironment } from "@/lib/api/environment";
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
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";

// Don't change these: a new N8N_ENCRYPTION_KEY makes every saved n8n credential unreadable. The API only checks syntax.
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

function applySuggestion(text, key, suggested) {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const line = new RegExp(`^(\\s*)(export\\s+)?${escaped}\\s*=.*$`, "m");
  const replacement = `$1$2${key}=${suggested}`;
  if (line.test(text)) return text.replace(line, replacement);
  const sep = text.length && !text.endsWith("\n") ? "\n" : "";
  return `${text}${sep}${key}=${suggested}\n`;
}

const MAX_CHARS = 262144;

function overLimit(text) {
  return text.length > MAX_CHARS && Array.from(text).length > MAX_CHARS;
}

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
  // errors.raw also carries refusals for protected keys, not just syntax errors.
  const [rawRefusal, setRawRefusal] = useState(false);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [safetyCopy, setSafetyCopy] = useState(true);
  const [guarded, setGuarded] = useState([]);
  const [preflight, setPreflight] = useState([]);

  // useState ignores later props. Track only the prop in seenRaw, never the saved text.
  const propRaw = initialEnv.raw ?? "";
  const [seenRaw, setSeenRaw] = useState(propRaw);

  if (propRaw !== seenRaw) {
    setSeenRaw(propRaw);
    setEnv(initialEnv);
    if (propRaw !== (env.raw ?? "")) {
      setContents(editable(propRaw));
      setSyntaxError(null);
    }
  }

  const dirty = contents !== editable(env.raw);
  const tooLarge = overLimit(contents);

  useWatchUnsaved("environment-editor", dirty);

  const sendRestart = Boolean(env.requires_restart);
  const saveLabel = env.requires_restart
    ? t("saveRestart")
    : env.requires_apply
      ? t("saveApply")
      : t("save");

  async function onSave({ confirmed = false, checked = false } = {}) {
    if (!dirty || saving || tooLarge) return;
    const touched = guardedChanges(env.raw, contents);
    if (touched.length && !confirmed) {
      setGuarded(touched);
      return;
    }
    setGuarded([]);
    setPreflight([]);
    const sent = contents;
    setSaving(true);
    setSyntaxError(null);
    if (!checked) {
      const result = await checkEnvironment(appId, sent).catch(() => null);
      if (result && result.saveable === false) {
        const text = (result.refused ?? []).join("\n");
        setRawRefusal(guardedChanges(env.raw, sent).some((key) => text.includes(key)));
        setSyntaxError(text || t("saveFailed"));
        setSaving(false);
        return;
      }
      const known = new Set((env.checks ?? []).map((check) => `${check.code}:${check.key ?? ""}`));
      const fresh = (result?.checks ?? []).filter(
        (check) => check.severity !== "info" && !known.has(`${check.code}:${check.key ?? ""}`),
      );
      if (fresh.length) {
        setPreflight(fresh);
        setSaving(false);
        return;
      }
    }
    try {
      const data = await saveEnvironment(appId, {
        raw: sent,
        restart: sendRestart,
        safetyCopy: !env.exists || safetyCopy,
      });
      const next = data?.environment;
      if (next) {
        setEnv(next);
        setContents((current) => (current === sent ? editable(next.raw ?? sent) : current));
      }
      toast.success(
        data?.restarted
          ? t("savedRestarted")
          : data?.applied
            ? t("savedApplied")
            : t("saved"),
      );

      router.refresh();
    } catch (error) {
      const raw = error.response?.data?.errors?.raw;
      if (raw) {
        const text = Array.isArray(raw) ? raw.join("\n") : String(raw);
        setRawRefusal(guardedChanges(env.raw, sent).some((key) => text.includes(key)));
        setSyntaxError(text);
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
    : env.requires_apply
        ? t("applyHint")
        : null;

  return (
    <Card>
      <CardContent className="space-y-4">
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

        {canManage && env.exists ? (
          <div className="space-y-2">
            <SafetyCopySwitch
              id="env-safety-copy"
              compact
              checked={safetyCopy}
              onCheckedChange={setSafetyCopy}
              disabled={saving}
              label={tc("keepFileCopy")}
            />
            {!safetyCopy ? (
              <Caution tone="destructive">
                <p>{tc("keepFileCopyOff")}</p>
              </Caution>
            ) : null}
          </div>
        ) : null}

        {tooLarge ? (
          <NotSaved title={t("tooLargeTitle")}>{t("tooLarge")}</NotSaved>
        ) : syntaxError ? (
          <NotSaved title={t(rawRefusal ? "protectedTitle" : "syntaxTitle")}>{syntaxError}</NotSaved>
        ) : null}

      </CardContent>

      {canManage ? (
        <CardFooter className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 py-4">
          <p className="text-xs text-muted-foreground">{footerNote}</p>
          <div className="flex min-w-0 flex-wrap items-center gap-2">
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

      <ConfirmDialog
        open={preflight.length > 0}
        onOpenChange={(next) => !next && setPreflight([])}
        icon={TriangleAlert}
        tone="warning"
        title={t("preflight.title")}
        description={t("preflight.description")}
        cancelLabel={t("preflight.cancel")}
        confirmLabel={t("preflight.confirm")}
        onConfirm={() => onSave({ confirmed: true, checked: true })}
      >
        <ul className="space-y-2 text-sm">
          {preflight.map((check, i) => (
            <li key={`${check.code}-${i}`} className="rounded-lg border border-warning/30 bg-warning/5 p-3">
              <p className="font-medium">{check.title}</p>
              {check.detail ? <p className="mt-0.5 text-muted-foreground">{check.detail}</p> : null}
            </li>
          ))}
        </ul>
      </ConfirmDialog>

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
      <div className="border-b border-destructive/20 px-3 py-1.5 text-xs text-destructive">
        {title}
      </div>
      <pre className="console-scroll max-h-40 overflow-auto p-3 font-mono text-xs leading-6 whitespace-pre-wrap text-destructive">
        {children}
      </pre>
    </div>
  );
}
