"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2, TriangleAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import { readPhpIni, savePhpIni } from "@/lib/api/php";
import { phpIniResponseSchema } from "@/lib/schemas/php";
import { LEVEL_CLASS, lineLevel } from "@/lib/logs/severity";
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
 * This is the most dangerous control on the page: a bad ini can stop FPM
 * starting, which takes down every site on that version with no obvious cause.
 * The API requires an explicit `acknowledged` flag for exactly that reason, so
 * the checkbox here isn't decoration — it's the thing the API is asking for.
 *
 * What makes it survivable is the backend's sequence: back up → write →
 * `php-fpm -t` → reload, restoring the previous file if PHP refuses it. That
 * fact is the most reassuring thing we can tell someone about to edit this
 * file, so it's stated up front rather than buried in an error.
 */
export function IniEditor({ version, canManage, unavailableReason = null }) {
  const t = useTranslations("services");
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
      const data = error.response?.data;
      // PHP's own rejection, shown verbatim — it names the line. The previous
      // file is already back in place server-side, which is the first thing the
      // reader needs to know.
      const invalid = data?.errors?.["php.invalid_ini"];
      if (invalid) {
        setPhpError(Array.isArray(invalid) ? invalid.join("\n") : String(invalid));
      } else {
        toast.error(apiMessage(error, t("phpIni.saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  const dirty = file !== null && contents !== file.contents;

  // "Nothing changed" first: if there's nothing to save, ticking the box won't
  // help, so naming the checkbox would send the reader to the wrong control.
  const blockedReason = !dirty
    ? t("phpIni.blockedNoChanges")
    : !acknowledged
      ? t("phpIni.blockedAcknowledge")
      : null;

  return (
    <>
      {/* A button, not a card. Hand-editing the file is the rare expert route;
          it sits with the version's other actions instead of taking a section
          of its own alongside the things people actually came for. */}
      {/* There is no file to edit until the install finishes. */}
      <ReasonTooltip reason={unavailableReason ?? (canManage ? null : t("noPermission"))}>
        <Button
          variant="outline"
          disabled={!canManage || loading || Boolean(unavailableReason)}
          onClick={load}
        >
          {loading ? <Loader2 className="size-4 animate-spin" /> : <FileCode2 className="size-4" />}
          {t("phpIni.shortAction")}
        </Button>
      </ReasonTooltip>

      <Dialog open={open} onOpenChange={(next) => !saving && setOpen(next)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
          <DialogHeader>
            <div className="flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/15 text-warning">
                <TriangleAlert className="size-5" />
              </span>
              <DialogTitle>{t("phpIni.title", { version })}</DialogTitle>
            </div>
            <DialogDescription className="pt-1">{t("phpIni.description")}</DialogDescription>
          </DialogHeader>

          {/* Console surface, like the log viewer and the config-test output.
              It's the same category of thing — a machine's own file — and the
              panel already has one visual language for that. A light form field
              would also frame it as "an input", when it's really a file you're
              being trusted with. */}
          <div className="overflow-hidden rounded-lg border border-console-border bg-console">
            <div className="flex items-center justify-between gap-2 border-b border-console-border px-3 py-1.5">
              <span className="truncate font-mono text-[11px] text-console-muted">
                {file?.path ?? t("phpIni.pathUnknown")}
              </span>
              <CopyButton
                value={contents}
                label={t("phpIni.copy")}
                className="text-console-muted hover:bg-console-foreground/10 hover:text-console-foreground"
              />
            </div>
            {loading ? (
              <div className="flex h-[55vh] items-center justify-center">
                <Loader2 className="size-5 animate-spin text-console-muted" />
              </div>
            ) : (
              <Textarea
                placeholder={t("iniPlaceholder")}
                value={contents}
                onChange={(e) => setContents(e.target.value)}
                spellCheck={false}
                // The whole file, edited as a file. A code-editor dependency for
                // one textarea would be a lot of bundle for syntax colouring.
                className="console-scroll h-[55vh] resize-none rounded-none border-0 bg-console font-mono text-xs leading-6 text-console-foreground caret-console-foreground shadow-none selection:bg-console-foreground/20 focus-visible:ring-0 dark:bg-console"
                aria-label={t("phpIni.title", { version })}
              />
            )}
          </div>

          {/* PHP refused it. Say so in PHP's words, and say what that means:
              nothing was applied and the old file is already back. */}
          {phpError ? (
            <div className="overflow-hidden rounded-lg border border-console-border bg-console">
              <div className="border-b border-console-border px-3 py-1.5 text-[11px] uppercase tracking-wide text-console-muted">
                {t("phpIni.rejectedTitle")}
              </div>
              <pre className="console-scroll max-h-40 overflow-auto p-3 font-mono text-xs leading-6">
                {phpError.split("\n").map((line, i) => (
                  <div key={i} className={cn("text-console-foreground", LEVEL_CLASS[lineLevel(line)])}>
                    {line || " "}
                  </div>
                ))}
              </pre>
            </div>
          ) : null}

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

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={saving}>
              {t("phpIni.cancel")}
            </Button>
            {/* Three gates, and each is a different question: did anything
                change, is it ticked, and are we mid-save. The button says which
                one is holding it — a disabled control that won't explain itself
                is a dead end, and this is the last step of a risky edit. */}
            <ReasonTooltip reason={blockedReason}>
              <Button onClick={save} disabled={Boolean(blockedReason) || saving || loading}>
                {saving ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("phpIni.save")}
              </Button>
            </ReasonTooltip>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
