"use client";

import { useRef, useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FileCode2, FolderLock, Loader2, Network, RefreshCw, RotateCcw, ShieldCheck } from "lucide-react";
import { saveContainerCompose } from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { containerRedeployInFlight } from "@/lib/applications/settled";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";

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
  const [errors, setErrors] = useState([]);

  // Once a save succeeds the site has a stored file, so hold this value.
  const [generated, setGenerated] = useState(initialGenerated);

  const dirty = contents !== initialCompose;
  const redeploying = containerRedeployInFlight(application);
  const tr = useTranslations("applications.redeploy");

  async function save() {
    setSaving(true);
    setErrors([]);
    try {
      await saveContainerCompose(application.id, contents);
      // Refresh before the toast: a compose save can change the image and port shown above.
      await refreshAndWait();
      toast.success(t("saved"));
      setGenerated(false);
      setAcknowledged(false);
    } catch (error) {
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

  const rules = [
    { key: "parser", icon: ShieldCheck },
    { key: "ports", icon: Network },
    { key: "mounts", icon: FolderLock },
  ];

  return (
    <Card>
      <CardHeader className="items-center border-b">
        <CardTitle icon={FileCode2} as="h2" className="flex flex-wrap items-center gap-2">
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
          <Caution size="md">{t("takeover")}</Caution>
        ) : null}

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
                redeploying ||
                !dirty ||
                contents.trim() === "" ||
                (generated && !acknowledged)
              }
              disabledReason={
                saving
                  ? null
                  : redeploying
                    ? tr("busy")
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
