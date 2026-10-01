import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { useRefresh } from "@/hooks/use-refresh";
import { TriangleAlert } from "lucide-react";
import { CopyButton } from "@/components/ui/copy-button";
import { deleteApplication } from "@/lib/api/applications";
import { getDatabasesForApplication } from "@/lib/api/databases";
import { z } from "zod";
import { databaseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * The domain is what must be typed: it is what stops being served.
 *
 * Files and databases are separate choices, ticked by default; when unticked,
 * the dialog says what is left behind.
 *
 * `remove_files` also destroys this site's backup archives in the storage
 * destination, which is why the checkbox names them. Unticked, the backup rows
 * still cascade with the application; the archives survive but the panel can no
 * longer list or delete them. Both facts are stated.
 */
export function DeleteApplicationDialog({ application, open, onOpenChange, afterDelete, redirectTo, closeWhenGone = false }) {
  const t = useTranslations("applications.delete");
  const router = useRouter();
  const { refreshThen, pushAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const [awaitingPage, setAwaitingPage] = useState(false);
  const [confirm, setConfirm] = useState("");
  const [removeFiles, setRemoveFiles] = useState(true);
  // Null when the user is gone; absent (undefined) when it was not loaded.
  const orphaned = application?.system_user === null;
  const [removeDatabases, setRemoveDatabases] = useState(true);
  /*
   * Only used to name databases on the checkbox. The delete call sends a flag,
   * not these ids; the API resolves the list itself, so this cannot go stale.
   */
  const [databases, setDatabases] = useState([]);

  /*
   * Fetched on open, not mount: the dialog is rendered per row. A failure leaves
   * the list empty, which hides the checkbox.
   */
  useEffect(() => {
    if (!open || !application?.id) return undefined;

    const controller = new AbortController();
    getDatabasesForApplication(application.id, { signal: controller.signal })
      .then(({ data }) => {
        /*
         * Parse the rows, not the envelope: only names are needed, and requiring
         * `meta` would hide the checkbox if pagination changes shape.
         */
        const parsed = z.array(databaseSchema).safeParse(data?.databases);
        setDatabases(parsed.success ? parsed.data : []);
      })
      .catch(() => setDatabases([]));

    return () => controller.abort();
  }, [open, application?.id]);

  const domain = application?.domain ?? "";
  const matches = confirm.trim() === domain;

  function handleOpenChange(next) {
    if (!next) {
      setConfirm("");
      setRemoveFiles(true);
      setRemoveDatabases(true);
      setDatabases([]);
    }
    onOpenChange?.(next);
  }

  // With closeWhenGone the toast waits until the page drops this dialog.
  const announce = useRef(null);
  useEffect(() => () => announce.current?.(), []);

  useEffect(() => {
    if (!awaitingPage) return undefined;
    const timer = window.setTimeout(() => {
      announce.current?.();
      announce.current = null;
      setAwaitingPage(false);
      setPending(false);
      onOpenChange?.(false);
    }, 20000);
    return () => window.clearTimeout(timer);
  }, [awaitingPage, onOpenChange]);

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      // Databases only when listed and ticked: an unconditional remove_databases is
      // a 403 for roles without database manage, and would drop unlisted databases.
      const { data } = await deleteApplication(application.id, {
        removeFiles: removeFiles && !orphaned,
        removeDatabases: databases.length > 0 && removeDatabases,
      });

      /*
       * A 200 can still carry database failures. The site is gone, so not an error,
       * but a warning names what is left and links to finish it.
       */
      const failed = data?.databases?.failed ?? [];
      const say = () => {
        if (failed.length) {
          toast.warning(data?.message ?? t("databasesFailed", { databases: failed.map((row) => row.name).join(", ") }), {
            duration: 20000,
            action: { label: t("goToDatabases"), onClick: () => router.push("/databases") },
          });
        } else {
          toast.success(t("done", { name: application.name }));
        }
      };
      if (afterDelete) await afterDelete();
      finish(say);
    } catch (error) {
      /*
       * 404: already deleted (another tab or user). Treated as done with an
       * explanatory message, and the list is refreshed so the row leaves.
       */
      if (error?.response?.status === 404) {
        if (afterDelete) await afterDelete();
        finish(() => toast.info(t("alreadyGone", { name: application.name })));
        return;
      }
      toast.error(apiMessage(error, t("failed")));
      setPending(false);
    }
  }

  // Announced once the list no longer shows the row.
  function finish(say) {
    const done = () => {
      say();
      handleOpenChange(false);
      setPending(false);
    };
    // For callers that swap this dialog out once the application is gone (the
    // staging page): stay on "Deleting…" until then.
    if (closeWhenGone) {
      announce.current = say;
      router.refresh();
      setAwaitingPage(true);
      return;
    }
    if (redirectTo) {
      // Navigate first, then toast and close, so the deleted page is not left up.
      pushAndWait(redirectTo).then(done);
    } else {
      refreshThen(done);
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
        {/* No system user means no home directory: the API skips the files, so no
            choice is offered. */}
        {orphaned ? (
          <p className="rounded-lg border bg-muted/40 p-3 text-xs leading-5 text-muted-foreground">
            {t("filesKept")}
          </p>
        ) : (
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="delete-app-files"
              checked={removeFiles}
              onCheckedChange={(value) => setRemoveFiles(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="delete-app-files" className="text-sm font-medium" hint={t("removeFilesHint")}>
                {t("removeFiles")}
              </Label>
              <p className="text-xs leading-5 text-muted-foreground">
                {removeFiles ? t("removeFilesOn") : t("removeFilesOff")}
              </p>
            </div>
          </div>
        )}

        {/* Only when the site has a database. */}
        {databases.length ? (
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="delete-app-databases"
              checked={removeDatabases}
              onCheckedChange={(value) => setRemoveDatabases(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="delete-app-databases" className="text-sm font-medium">
                {t("removeDatabases", { count: databases.length })}
              </Label>
              {/* Databases are named, not counted, so the reader can check them. */}
              <p className="text-xs leading-5 text-muted-foreground">
                {/* `count` too, so the verb and pronoun agree with the list. */}
                {t(removeDatabases ? "removeDatabasesOn" : "removeDatabasesOff", {
                  count: databases.length,
                  databases: databases.map((row) => row.name).join(", "),
                })}
              </p>
            </div>
          </div>
        ) : null}

        {/* The generated Linux account is not removed: the API accepts only
            `remove_files` and `remove_databases`. Say so rather than stay silent. */}
        {application?.system_user?.username ? (
          <p className="text-xs leading-5 text-muted-foreground">
            {t("systemUserStays", { username: application.system_user.username })}
          </p>
        ) : null}

        <div className="space-y-2">
          {/* `Label` is display:flex; `block` keeps the sentence flowing as text. One
              key so word order can differ by language. */}
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
