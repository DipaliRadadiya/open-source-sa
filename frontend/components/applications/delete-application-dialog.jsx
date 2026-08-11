"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { TriangleAlert } from "lucide-react";
import { CopyButton } from "@/components/ui/copy-button";
import { deleteApplication } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Deleting an application stops the domain being served, so the domain is what
 * has to be typed — the thing that goes dark, not the label.
 *
 * The files are a separate decision and default to being kept, which matches
 * the API. Two things people expect to happen and don't are said out loud: the
 * code on disk stays, and a database created for this site stays too.
 */
export function DeleteApplicationDialog({ application, open, onOpenChange, afterDelete, redirectTo }) {
  const t = useTranslations("applications.delete");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");
  const [removeFiles, setRemoveFiles] = useState(false);

  const domain = application?.domain ?? "";
  const matches = confirm.trim() === domain;

  function handleOpenChange(next) {
    if (!next) {
      setConfirm("");
      setRemoveFiles(false);
    }
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      await deleteApplication(application.id, { removeFiles });
      toast.success(t("done", { name: application.name }));
      handleOpenChange(false);
      if (afterDelete) await afterDelete();
      if (redirectTo) router.push(redirectTo);
      else router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={TriangleAlert}
      tone="destructive"
      title={t("title", { name: application?.name ?? "" })}
      description={t("description", { domain })}
      cancelLabel={t("cancel")}
      confirmLabel={pending ? t("deleting") : t("submit")}
      confirmDisabled={!matches}
      pending={pending}
      onConfirm={onConfirm}
    >
      <div className="space-y-4">
        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
          <Checkbox
            id="delete-app-files"
            checked={removeFiles}
            onCheckedChange={(value) => setRemoveFiles(value === true)}
            className="mt-0.5"
          />
          <div className="space-y-1">
            <Label htmlFor="delete-app-files" className="text-sm font-medium">
              {t("removeFiles")}
            </Label>
            <p className="text-xs leading-5 text-muted-foreground">
              {removeFiles ? t("removeFilesOn") : t("removeFilesOff")}
            </p>
          </div>
        </div>

        <p className="text-xs leading-5 text-muted-foreground">{t("databaseNote")}</p>

        <div className="space-y-2">
          {/* One sentence, not three fragments. `Label` is display:flex, so
              "Type", the domain and "to confirm" were laid out as flex items
              and a long domain pushed the trailing words into their own
              wrapped column. `block` makes them words again, and the whole
              sentence is one key so word order can differ by language. */}
          <div className="flex items-start justify-between gap-2">
            <Label
              htmlFor="delete-app-confirm"
              className="block text-sm leading-5 font-normal"
            >
              {t.rich("confirmLabel", {
                domain,
                code: (chunks) => (
                  <span className="font-mono font-medium break-all text-foreground">
                    {chunks}
                  </span>
                ),
              })}
            </Label>
            <CopyButton
              value={domain}
              label={t("copyDomain")}
              className="size-6 shrink-0"
            />
          </div>
          <Input
            placeholder={domain}
            id="delete-app-confirm"
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
            autoComplete="off"
            spellCheck={false}
            className="font-mono"
          />
        </div>
      </div>
    </ConfirmDialog>
  );
}
