import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
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
 * Deleting an application stops the domain being served, so the domain is what
 * has to be typed — the thing that goes dark, not the label.
 *
 * The files are a separate decision and default to being kept, which matches
 * the API. Two things people expect to happen and don't are said out loud: the
 * code on disk stays, and a database created for this site stays too.
 *
 * `remove_files` also destroys this site's backups — every row AND the archives
 * in the storage destination. That is the whole reason the checkbox names them:
 * "delete the files" reads as recoverable, and it is the one decision here that
 * is not.
 *
 * Leaving it unticked is not "keep your backups" either. The backup rows cascade
 * with the application, so they leave the panel regardless; only the archives
 * survive, and nothing in the panel can list or delete them afterwards. Both
 * halves of that are said, because a half-truth here is what leaves somebody
 * paying for buckets they cannot find.
 */
export function DeleteApplicationDialog({ application, open, onOpenChange, afterDelete, redirectTo }) {
  const t = useTranslations("applications.delete");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [confirm, setConfirm] = useState("");
  const [removeFiles, setRemoveFiles] = useState(false);
  const [removeDatabases, setRemoveDatabases] = useState(false);
  /*
   * Only to NAME them on the checkbox. "Also delete the database" is a
   * different decision from "also delete shop_live", and the second is the one
   * somebody can check against what they believe the site owns.
   *
   * The delete call sends a flag, never these ids — the API resolves the list
   * itself as it deletes, so a database attached since this opened is still
   * taken and this cannot go stale in a way that loses data.
   */
  const [databases, setDatabases] = useState([]);

  /*
   * On open, not on mount: this dialog is rendered per row on the list, so
   * mounting would fetch once per site for a question nobody asked.
   * A failure leaves the list empty, which hides the checkbox — the site
   * deletes exactly as it did before, rather than offering a choice the panel
   * cannot describe.
   */
  useEffect(() => {
    if (!open || !application?.id) return undefined;

    const controller = new AbortController();
    getDatabasesForApplication(application.id, { signal: controller.signal })
      .then(({ data }) => {
        /*
         * The rows, not the envelope. `databasesResponseSchema` also requires
         * `meta`, and this only needs names — parsing the whole response would
         * hide the checkbox the day pagination changes shape. Same shape as
         * `get-server-processes`.
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
      setRemoveFiles(false);
      setRemoveDatabases(false);
      setDatabases([]);
    }
    onOpenChange?.(next);
  }

  async function onConfirm() {
    if (!matches) return;
    setPending(true);
    try {
      const { data } = await deleteApplication(application.id, { removeFiles, removeDatabases });

      /*
       * 200 with a failure inside it. The site really is gone — a red toast
       * would say nothing happened when nearly all of it did — but a green one
       * would bury a database still sitting on the server, and the dialog this
       * would have been reported in is about to close on a site that no longer
       * exists. So: a warning that names what is left, and a way to go and
       * finish it, held long enough to read.
       */
      const failed = data?.databases?.failed ?? [];
      if (failed.length) {
        toast.warning(data?.message ?? t("databasesFailed", { databases: failed.map((row) => row.name).join(", ") }), {
          duration: 20000,
          action: { label: t("goToDatabases"), onClick: () => router.push("/databases") },
        });
      } else {
        toast.success(t("done", { name: application.name }));
      }
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

        {/* Only when there is one. The old note said a database "is kept" on
            every site, including those that never had one. */}
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
              {/* Named, not counted. "Also delete 1 database" is a promise the
                  reader cannot check; the name is. */}
              <p className="text-xs leading-5 text-muted-foreground">
                {/* `count` as well as the names: the verb and the pronoun have
                    to agree with a list, and "shop_live, shop_reports stays …
                    Remove it" is what one shared sentence gives you. */}
                {t(removeDatabases ? "removeDatabasesOn" : "removeDatabasesOff", {
                  count: databases.length,
                  databases: databases.map((row) => row.name).join(", "),
                })}
              </p>
            </div>
          </div>
        ) : null}

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
