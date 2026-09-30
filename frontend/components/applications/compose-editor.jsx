"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, Loader2 } from "lucide-react";
import { getContainerCompose, saveContainerCompose } from "@/lib/api/docker";
import { composeFileResponseSchema } from "@/lib/schemas/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import { Note } from "@/components/ui/note";
import { Caution } from "@/components/ui/caution";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Edit a container site's compose file.
 *
 * The answer to "how do I change an environment variable", which had no answer: a
 * container gets no Environment screen — its variables live in this file — and the
 * file was writable exactly once, on the create form.
 *
 * A dialog rather than a card, like the php.ini editor it is modelled on, because
 * a sixty-line YAML box on the application page would dominate a screen most
 * visits never need.
 *
 * **What makes it survivable is stated up front, not buried in an error.** The
 * server validates with Docker's own parser, refuses a file that publishes off
 * loopback or mounts outside the site's tree, and if the new file will not come up
 * it puts the old one back and brings the site up on that. Someone about to edit
 * this wants to know that before they start.
 *
 * The acknowledgement checkbox appears for one case only: a site still running the
 * panel's generated file, where saving is one-way. A confirmation on every save
 * would be friction with nothing behind it, since an ordinary bad save rolls back.
 */
export function ComposeEditor({ application, canManage }) {
  const t = useTranslations("applications.compose");
  const router = useRouter();

  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [contents, setContents] = useState("");
  // Whether the text on screen came from the panel's template rather than from a
  // stored file. Decides the warning and whether an acknowledgement is required.
  const [generated, setGenerated] = useState(false);
  const [acknowledged, setAcknowledged] = useState(false);
  // Field errors from the validator — the refusals worth reading line by line,
  // unlike a toast that clears itself in four seconds.
  const [errors, setErrors] = useState([]);

  async function load() {
    setOpen(true);
    setLoading(true);
    setErrors([]);
    setAcknowledged(false);
    try {
      const { data } = await getContainerCompose(application.id);
      const parsed = composeFileResponseSchema.safeParse(data);
      if (!parsed.success) throw new Error("unreadable");
      setContents(parsed.data.compose);
      setGenerated(parsed.data.generated);
    } catch (error) {
      toast.error(apiMessage(error, t("loadFailed")));
      setOpen(false);
    } finally {
      setLoading(false);
    }
  }

  async function save() {
    setSaving(true);
    setErrors([]);
    try {
      await saveContainerCompose(application.id, contents);
      toast.success(t("saved"));
      setOpen(false);
      // The site's own facts — its port, its image — are server-rendered, and a
      // compose save can move both.
      router.refresh();
    } catch (error) {
      // A 422 carries either field errors from the validator or a single message
      // from a failed apply. Both belong in the dialog: it stays open so the text
      // can be fixed without being retyped.
      const fieldErrors = error.response?.data?.errors?.compose;

      if (Array.isArray(fieldErrors) && fieldErrors.length > 0) {
        setErrors(fieldErrors);
      } else {
        setErrors([apiMessage(error, t("failed"))]);
      }
    } finally {
      setSaving(false);
    }
  }

  const blocked =
    saving || loading || contents.trim() === "" || (generated && !acknowledged);

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
        <div className="min-w-0">
          <p className="text-sm font-medium">{t("title")}</p>
          <p className="text-xs text-muted-foreground">{t("hint")}</p>
        </div>
        <Button type="button" size="sm" variant="outline" onClick={load}>
          <FileCode2 className="size-4" />
          {canManage ? t("edit") : t("view")}
        </Button>
      </div>

      <Dialog open={open} onOpenChange={(next) => !next && setOpen(false)}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>{t("title")}</DialogTitle>
            <DialogDescription>{t("description")}</DialogDescription>
          </DialogHeader>

          {loading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
              {t("loading")}
            </div>
          ) : (
            <div className="space-y-3">
              {generated ? (
                /* One-way, so it is a Caution and not a Note. After this save the
                   stored file is what runs and the Image and Container port fields
                   stop driving it — which is a reasonable thing to want, and an
                   unreasonable thing to discover afterwards. */
                <Caution size="md">{t("takeover")}</Caution>
              ) : null}

              <Note icon={FileCode2}>{t("safety")}</Note>

              <Textarea
                value={contents}
                onChange={(event) => setContents(event.target.value)}
                readOnly={!canManage}
                rows={18}
                spellCheck={false}
                className="font-mono text-xs"
                aria-label={t("title")}
              />

              {errors.length > 0 ? (
                <div className="space-y-1 rounded-lg border border-destructive/40 bg-destructive/[0.05] p-3">
                  {errors.map((message) => (
                    <p key={message} className="text-xs text-destructive">
                      {message}
                    </p>
                  ))}
                </div>
              ) : null}

              {canManage && generated ? (
                <div className="flex items-start gap-2">
                  <Checkbox
                    id="compose-ack"
                    checked={acknowledged}
                    onCheckedChange={(checked) =>
                      setAcknowledged(checked === true)
                    }
                  />
                  <Label
                    htmlFor="compose-ack"
                    className="text-xs leading-snug font-normal"
                  >
                    {t("acknowledge")}
                  </Label>
                </div>
              ) : null}
            </div>
          )}

          <DialogFooter className="gap-2 sm:justify-between">
            <CopyButton value={contents} label={t("copy")} />
            <div className="flex gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setOpen(false)}
              >
                {t("close")}
              </Button>
              {canManage ? (
                <Button
                  type="button"
                  size="sm"
                  disabled={blocked}
                  onClick={save}
                >
                  {saving ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("save")}
                </Button>
              ) : null}
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
