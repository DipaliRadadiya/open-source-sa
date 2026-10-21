"use client";

import { useRef, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, FolderLock, Loader2, Network, RefreshCw, RotateCcw, ShieldCheck } from "lucide-react";
import { saveContainerCompose } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";

/**
 * Edit a container site's compose file.
 *
 * The answer to "how do I change an environment variable", which had none: a
 * container gets no Environment screen — its variables live in this file — and the
 * file was writable exactly once, on the create form.
 *
 * **Why this is not a dialog any more.** It was, modelled on the php.ini editor,
 * and it was the wrong shape for this file. A compose file is sixty lines, so the
 * modal's body pushed the Save button below the fold on a desktop: the control you
 * opened it for was the one you could not reach. A page has no fold to fall off —
 * the editor takes the full width and the save row sits in normal document flow.
 *
 * The file also deserves the room. This is the site: its image, its ports, its
 * volumes and its variables are all in here, which is not a "settings dialog"
 * amount of consequence.
 *
 * **What makes it survivable is stated up front, not left to an error.** The server
 * checks the file with Docker's own parser, refuses one that publishes off loopback
 * or mounts outside the site's own tree, and if the new file will not come up it
 * puts the old one back and starts the site on that.
 *
 * The acknowledgement appears for one case only: a site still running the panel's
 * generated file, where saving is one-way. A confirmation on every save would be
 * friction with nothing behind it, since an ordinary bad save rolls back.
 */
export function ComposeEditor({
  application,
  canManage,
  initialCompose,
  initialGenerated,
}) {
  const t = useTranslations("applications.compose");
  const tc = useTranslations("common");
  const { refreshAndWait } = useRefresh();

  const [contents, setContents] = useState(initialCompose);
  const [saving, setSaving] = useState(false);
  const [acknowledged, setAcknowledged] = useState(false);
  // Field errors from the validator — read line by line and acted on, unlike a
  // toast that clears itself in four seconds.
  const [errors, setErrors] = useState([]);

  // Whether what is on screen came from the panel's template rather than a stored
  // file. Read from the server on load and held, because once a save succeeds the
  // site has a stored file and the warning must stop.
  const [generated, setGenerated] = useState(initialGenerated);

  const dirty = contents !== initialCompose;

  async function save() {
    setSaving(true);
    setErrors([]);
    try {
      await saveContainerCompose(application.id, contents);
      // Before the toast: the image and published port are server-rendered and a
      // compose save can move either, so a toast first uncovers stale state.
      await refreshAndWait();
      toast.success(t("saved"));
      setGenerated(false);
      setAcknowledged(false);
    } catch (error) {
      // A 422 carries either field errors from the validator or one message from a
      // failed apply. Both belong on the page, where the text can be fixed without
      // being retyped.
      const fieldErrors = error.response?.data?.errors?.compose;

      setErrors(
        Array.isArray(fieldErrors) && fieldErrors.length > 0
          ? fieldErrors
          : [apiMessage(error, t("failed"))],
      );
    } finally {
      setSaving(false);
    }
  }

  const lineCount = contents.split("\n").length;
  const gutter = useRef(null);

  // What the server checks, one short line each instead of a paragraph (Krishna, 20 Oct).
  const rules = [
    { key: "parser", icon: ShieldCheck },
    { key: "ports", icon: Network },
    { key: "mounts", icon: FolderLock },
  ];

  return (
    <Card>
      <CardHeader className="items-center border-b">
        <CardTitle as="h2" className="flex flex-wrap items-center gap-2">
          <FileCode2 className="size-4 text-muted-foreground" />
          <span className="font-mono text-[13px]">compose.yml</span>
          <span className="text-xs font-normal tabular-nums text-muted-foreground">
            {t("lines", { count: lineCount })}
          </span>
        </CardTitle>
        <CardAction className="row-span-1 self-center">
          <CopyButton value={contents} label={t("copy")} text />
        </CardAction>
      </CardHeader>

      <CardContent className="space-y-4">
        <ul className="flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-muted-foreground">
          {rules.map(({ key, icon: Icon }) => (
            <li key={key} className="flex items-center gap-1.5">
              <Icon className="size-3.5 shrink-0 text-primary" />
              {t(`rules.${key}`)}
            </li>
          ))}
        </ul>

        {generated ? (
          /* One-way, so a Caution. After this save the stored file is what runs and the
             Container page's settings stop driving it. */
          <Caution size="md">{t("takeover")}</Caution>
        ) : null}

        {/* An editor well: line numbers in a gutter, the file beside them. Full width
            and not wrapped -- a compose file's lines are long and wrapped YAML is
            unreadable. `font-mono` because indentation is syntax here. */}
        <div className="flex overflow-hidden rounded-lg border bg-muted/30 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 dark:bg-input/30">
          <div
            ref={gutter}
            aria-hidden
            className="shrink-0 overflow-hidden border-r bg-muted/50 py-3 ps-3 pe-2.5 text-right font-mono text-xs leading-relaxed text-muted-foreground/70 tabular-nums select-none"
          >
            {Array.from({ length: lineCount }, (_, index) => (
              <div key={index}>{index + 1}</div>
            ))}
          </div>
          <Textarea
            value={contents}
            onChange={(event) => setContents(event.target.value)}
            // Browsers without field-sizing scroll the box; keep the numbers level.
            onScroll={(event) => {
              if (gutter.current) gutter.current.scrollTop = event.currentTarget.scrollTop;
            }}
            readOnly={!canManage}
            rows={24}
            spellCheck={false}
            wrap="off"
            className="min-h-80 w-full flex-1 overflow-x-auto rounded-none border-0 bg-transparent px-3 py-3 font-mono text-xs leading-relaxed shadow-none focus-visible:ring-0 dark:bg-transparent"
            aria-label={t("title")}
          />
        </div>

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
              onCheckedChange={(checked) => setAcknowledged(checked === true)}
            />
            <Label
              htmlFor="compose-ack"
              className="text-xs leading-snug font-normal"
            >
              {t("acknowledge")}
            </Label>
          </div>
        ) : null}
      </CardContent>

      {/* What saving does, beside the button that does it. */}
      {canManage ? (
        <CardFooter className="flex-wrap items-center justify-between gap-3 border-t">
          <p className="flex max-w-prose items-start gap-2 text-xs leading-5 text-muted-foreground">
            <RefreshCw className="mt-0.5 size-3.5 shrink-0" />
            {t("safety")}
          </p>
          <div className="flex flex-wrap items-center gap-2">
            {dirty ? (
              <span className="text-xs text-muted-foreground">
                {t("unsaved")}
              </span>
            ) : null}

            {/* Back to what the page loaded with. Cheap to offer and the obvious
                thing to want after an edit that will not validate. */}
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={saving || !dirty}
              disabledReason={!dirty ? tc("nothingToRevert") : null}
              onClick={() => {
                setContents(initialCompose);
                setErrors([]);
              }}
            >
              <RotateCcw className="size-4" />
              {t("revert")}
            </Button>

            <Button
              type="button"
              size="sm"
              disabled={
                saving ||
                !dirty ||
                contents.trim() === "" ||
                (generated && !acknowledged)
              }
              // Said on hover, like every other disabled control.
              disabledReason={
                saving
                  ? null
                  : !dirty
                    ? tc("nothingToSave")
                    : contents.trim() === ""
                      ? tc("enterAValue")
                      : generated && !acknowledged
                        ? t("tickFirst")
                        : null
              }
              onClick={save}
            >
              {saving ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("save")}
            </Button>
          </div>
        </CardFooter>
      ) : null}
    </Card>
  );
}
