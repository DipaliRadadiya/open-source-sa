"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2, TriangleAlert } from "lucide-react";
import { readPhpIni, savePhpIni } from "@/lib/api/php";
import { phpIniResponseSchema } from "@/lib/schemas/php";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Edit a PHP version's FPM php.ini.
 *
 * A bad ini can stop FPM and take down every site on the version, so the API
 * requires an explicit `acknowledged` flag (the checkbox). The backend backs up,
 * writes, runs `php-fpm -t` and reloads, restoring the old file if PHP refuses;
 * that is stated up front.
 */
export function IniEditor({ version, canManage, unavailableReason = null }) {
  const t = useTranslations("services");
  const tPhp = useTranslations("php");
  // Reading only needs view access (GET …/ini is `permission:php`), so a
  // view-only user gets the file without the save path.
  const readOnly = !canManage;
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [file, setFile] = useState(null);
  const [contents, setContents] = useState("");
  const [acknowledged, setAcknowledged] = useState(false);
  const [phpError, setPhpError] = useState(null);

  async function load() {
    setOpen(true);
    setLoading(true);
    setPhpError(null);
    setAcknowledged(false);
    try {
      const { data } = await readPhpIni(version);
      const parsed = phpIniResponseSchema.safeParse(data);
      if (!parsed.success) throw new Error("unreadable");
      setFile(parsed.data.php_ini);
      setContents(parsed.data.php_ini.contents);
    } catch (error) {
      toast.error(apiMessage(error, t("phpIni.loadFailed")));
      setOpen(false);
    } finally {
      setLoading(false);
    }
  }

  async function save() {
    setSaving(true);
    setPhpError(null);
    try {
      await savePhpIni(version, contents);
      toast.success(t("phpIni.saved", { version }));
      setOpen(false);
    } catch (error) {
      // A 422 is PHP refusing the file (the old one is already restored) or a
      // form error; shown beside the editor rather than in a toast.
      if (error.response?.status === 422) {
        setPhpError(apiMessage(error, t("phpIni.saveFailed")));
      } else {
        toast.error(apiMessage(error, t("phpIni.saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  const dirty = file !== null && contents !== file.contents;

  // "Nothing changed" first: ticking the box would not help then.
  const blockedReason = !dirty
    ? t("phpIni.blockedNoChanges")
    : !acknowledged
      ? t("phpIni.blockedAcknowledge")
      : null;

  return (
    <>
      {/* There is no file to edit until the install finishes. */}
      <ReasonTooltip reason={unavailableReason}>
        {/* Not disabled while loading: a disabled button drops focus, so
            closing the dialog would not return focus here. */}
        <Button
          variant="outline"
          disabled={Boolean(unavailableReason)}
          onClick={load}
        >
          {loading ? <Loader2 className="size-4 animate-spin" /> : <FileCode2 className="size-4" />}
          {readOnly ? t("phpIni.viewAction") : t("phpIni.shortAction")}
        </Button>
      </ReasonTooltip>

      <Dialog open={open} onOpenChange={(next) => !saving && setOpen(next)}>
        {/* Header, body, footer with only the body scrolling, like the file
            editor. A fixed height (not max-height) keeps Save and Cancel
            pinned regardless of file length. */}
        <DialogContent className="grid-rows-[auto_minmax(0,1fr)_auto] h-[85vh] sm:max-w-5xl">
          <DialogHeader>
            <div className="flex min-w-0 items-center gap-3">
              <span
                className={
                  readOnly
                    ? "flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    : "flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning"
                }
              >
                {readOnly ? <FileCode2 className="size-5" /> : <TriangleAlert className="size-5" />}
              </span>
              <DialogTitle>{t("phpIni.title", { version })}</DialogTitle>
            </div>
            <DialogDescription className="pt-1">
              {readOnly ? t("phpIni.readOnlyDescription") : t("phpIni.description")}
            </DialogDescription>
          </DialogHeader>

        {/* The only scrolling part, so the acknowledgement below the editor
            stays reachable; the editor keeps a minimum height. */}
        <div className="flex min-h-0 flex-col gap-4 overflow-y-auto">
          {/* Console surface, matching the log viewer and config-test output. */}
          <div className="flex min-h-48 flex-1 flex-col overflow-hidden rounded-lg border border-console-border bg-console">
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
            {/* h-full, not a vh fraction: the editor fills whatever the dialog
                gives it, so the footer's position never depends on the file. */}
            {loading ? (
              <div className="flex min-h-0 flex-1 items-center justify-center">
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

          {phpError ? (
            <p
              role="alert"
              className="flex shrink-0 items-start gap-2.5 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
              <span>{phpError}</span>
            </p>
          ) : null}

            {readOnly ? (
              <p className="shrink-0 text-sm text-muted-foreground">{tPhp("noPermission")}</p>
            ) : (
            <div className="flex shrink-0 items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 p-3">
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
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={saving}>
              {readOnly ? t("phpIni.close") : t("phpIni.cancel")}
            </Button>
            {/* Three gates (changed, acknowledged, not saving); the button says
                which one is blocking. */}
            {readOnly ? null : (
            <ReasonTooltip reason={blockedReason}>
              <Button onClick={save} disabled={Boolean(blockedReason) || saving || loading}>
                {saving ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("phpIni.save")}
              </Button>
            </ReasonTooltip>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
