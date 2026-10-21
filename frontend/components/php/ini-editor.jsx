"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Info, Loader2, RotateCcw, TriangleAlert } from "lucide-react";
import Link from "@/components/ui/app-link";
import { readPhpIni, savePhpIni } from "@/lib/api/php";
import { phpIniResponseSchema } from "@/lib/schemas/php";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { apiMessage } from "@/lib/api/error-message";
import { Caution } from "@/components/ui/caution";

// Shared with the Settings tab (php-common-settings.jsx); a literal here keeps this file
// free of that component's imports.
const PHP_INI_CHANGED = "php-ini-changed";

async function fetchIni(version) {
  try {
    const { data } = await readPhpIni(version);
    const parsed = phpIniResponseSchema.safeParse(data);
    return parsed.success ? { file: parsed.data.php_ini } : { error: null };
  } catch (error) {
    return { error: apiMessage(error, null) };
  }
}

// The php.ini tab. A bad ini can stop FPM for every application on the version, so the
// API requires `acknowledged`; the backend restores the old file if `php-fpm -t` refuses.
// Mounted only once its tab is opened, and kept mounted after, so switching tabs keeps edits.
export function IniEditor({ version, canManage }) {
  const t = useTranslations("services");
  const tPhp = useTranslations("php");
  // Reading only needs view access (GET …/ini is `permission:php`).
  const readOnly = !canManage;
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [file, setFile] = useState(null);
  const [contents, setContents] = useState("");
  const [acknowledged, setAcknowledged] = useState(false);
  const [phpError, setPhpError] = useState(null);

  // State only changes once the answer is in, so the first read can run from an effect.
  const apply = useCallback(
    (result) => {
      if (result.file) {
        setFile(result.file);
        setContents(result.file.contents);
      } else {
        setLoadError(result.error ?? t("phpIni.loadFailed"));
      }
      setLoading(false);
    },
    [t],
  );

  useEffect(() => {
    let active = true;
    fetchIni(version).then((result) => active && apply(result));
    return () => {
      active = false;
    };
  }, [version, apply]);

  async function retry() {
    setStale(false);
    setLoading(true);
    setLoadError(null);
    setPhpError(null);
    setAcknowledged(false);
    apply(await fetchIni(version));
  }

  const dirty = file !== null && contents !== file.contents;
  // The Settings tab rewrote lines in this file. Untouched: reload. Mid-edit: say so,
  // because saving this copy would undo that change.
  const [stale, setStale] = useState(false);
  useEffect(() => {
    const onChange = (event) => {
      if (event.detail?.version !== version || event.detail?.source !== "settings") return;
      if (dirty) setStale(true);
      else fetchIni(version).then(apply);
    };
    window.addEventListener(PHP_INI_CHANGED, onChange);
    return () => window.removeEventListener(PHP_INI_CHANGED, onChange);
  }, [version, dirty, apply]);
  // Leaving the page or picking another version would drop the edit.
  useWatchUnsaved(`php-ini-${version}`, dirty);

  async function save() {
    setSaving(true);
    setPhpError(null);
    try {
      await savePhpIni(version, contents);
      toast.success(t("phpIni.saved", { version }));
      // What is on disk now; a second save needs a fresh acknowledgement.
      setFile((current) => ({ ...current, contents }));
      setAcknowledged(false);
      setStale(false);
      window.dispatchEvent(new CustomEvent(PHP_INI_CHANGED, { detail: { version, source: "ini" } }));
    } catch (error) {
      // A 422 is PHP refusing the file (old one already restored) or a form error.
      if (error.response?.status === 422) {
        setPhpError(apiMessage(error, t("phpIni.saveFailed")));
      } else {
        toast.error(apiMessage(error, t("phpIni.saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  function discard() {
    setContents(file?.contents ?? "");
    setPhpError(null);
    setAcknowledged(false);
  }

  // "Nothing changed" first: ticking the box would not help then.
  const blockedReason = !dirty
    ? t("phpIni.blockedNoChanges")
    : !acknowledged
      ? t("phpIni.blockedAcknowledge")
      : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">{t("phpIni.title", { version })}</CardTitle>
        <CardDescription>
          {readOnly ? t("phpIni.readOnlyDescription") : tPhp("ini.description")}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Most people arrive here to raise one application's limit, which belongs on its own page. */}
        <p className="flex items-start gap-2 rounded-lg border bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground">
          <Info className="mt-0.5 size-4 shrink-0" />
          <span>
            {tPhp("ini.perApp")}{" "}
            <Link href="/applications" prefetch={false} className="font-medium text-foreground underline underline-offset-4">
              {tPhp("ini.perAppLink")}
            </Link>
          </span>
        </p>

        {loadError ? (
          <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
            <span className="flex items-start gap-2">
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
              {loadError}
            </span>
            <Button variant="outline" size="sm" onClick={retry}>
              {tPhp("ini.retry")}
            </Button>
          </div>
        ) : (
          // Console surface, matching the log viewer and config-test output.
          <div className="flex h-[28rem] flex-col overflow-hidden rounded-lg border border-console-border bg-console">
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-console-border px-3 py-1.5">
              <span className="truncate font-mono text-xs text-console-muted">
                {file?.path ?? t("phpIni.pathUnknown")}
              </span>
              <CopyButton
                value={contents}
                label={t("phpIni.copy")}
                className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
              />
            </div>
            {loading ? (
              <div className="flex min-h-0 flex-1 items-center justify-center" aria-busy="true">
                <Loader2 className="size-5 animate-spin text-console-muted" />
              </div>
            ) : (
              <Textarea
                placeholder={t("iniPlaceholder")}
                value={contents}
                onChange={(e) => setContents(e.target.value)}
                readOnly={readOnly}
                spellCheck={false}
                // A plain textarea: a code-editor dependency is not worth the bundle here.
                className="console-scroll h-full min-h-0 flex-1 resize-none rounded-none border-0 bg-console font-mono text-xs leading-6 text-console-foreground caret-console-foreground shadow-none selection:bg-console-foreground/20 focus-visible:ring-0 dark:bg-console"
                aria-label={t("phpIni.title", { version })}
              />
            )}
          </div>
        )}

        {stale ? (
          <Caution size="md">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p>{tPhp("ini.stale")}</p>
              <Button type="button" variant="outline" size="sm" onClick={retry}>
                {tPhp("ini.reload")}
              </Button>
            </div>
          </Caution>
        ) : null}

        {phpError ? (
          <p
            role="alert"
            className="flex items-start gap-2.5 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
            <span>{phpError}</span>
          </p>
        ) : null}

        {readOnly ? (
          <p className="text-sm text-muted-foreground">{tPhp("noPermission")}</p>
        ) : dirty ? (
          // Asked only once there is something to save.
          <div className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 p-3">
            <Checkbox
              id={`ack-${version}`}
              checked={acknowledged}
              onCheckedChange={(v) => setAcknowledged(v === true)}
              className="mt-0.5"
            />
            <Label
              htmlFor={`ack-${version}`}
              className="text-sm font-normal leading-relaxed text-foreground"
            >
              {t("phpIni.acknowledge", { version })}
            </Label>
          </div>
        ) : null}
      </CardContent>

      {readOnly || loadError ? null : (
        <CardFooter className="justify-end gap-2 border-t py-3">
          <Button variant="outline" onClick={discard} disabled={!dirty || saving}>
            <RotateCcw className="size-4" />
            {tPhp("ini.discard")}
          </Button>
          {/* The button says which gate is blocking. */}
          <ReasonTooltip reason={blockedReason}>
            <Button onClick={save} disabled={Boolean(blockedReason) || saving || loading}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("phpIni.save")}
            </Button>
          </ReasonTooltip>
        </CardFooter>
      )}
    </Card>
  );
}
