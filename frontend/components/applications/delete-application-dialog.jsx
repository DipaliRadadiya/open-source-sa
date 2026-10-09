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

// The domain is what must be typed: it is what stops being served.
// `remove_files` also destroys this site's backup archives in storage. Unticked, the backup rows
// still cascade with the application, leaving archives the panel can no longer list or delete.
export function DeleteApplicationDialog({ application, open, onOpenChange, afterDelete, redirectTo, closeWhenGone = false, canRemoveSystemUser = false }) {
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
  // Off by default: one account often serves several applications (the API keeps it then).
  const [removeSystemUser, setRemoveSystemUser] = useState(false);
  const systemUsername = application?.system_user?.username ?? null;
  // On by default, like files and databases: a volume holding a container site's
  // database is as unrecoverable as the database a LEMP site had.
  const [removeDockerResources, setRemoveDockerResources] = useState(true);
  // Only names databases on the checkbox; the API resolves the list itself.
  const [databases, setDatabases] = useState([]);

  // The site's own network and the volumes it mounts, from the payload. Not filtered by
  // what other sites use -- the server decides that at the moment it deletes.
  const dockerResourceNames = [
    ...(application.volume_mounts ?? []).map((mount) => mount.volume),
    ...(application.docker_network ? [application.docker_network] : []),
  ].filter((name, index, all) => name && all.indexOf(name) === index);

  // Fetched on open, not mount (rendered per row). A failure hides the checkbox.
  useEffect(() => {
    if (!open || !application?.id) return undefined;

    const controller = new AbortController();
    getDatabasesForApplication(application.id, { signal: controller.signal })
      .then(({ data }) => {
        // Parse the rows, not the envelope: a `meta` change must not hide the checkbox.
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
      setRemoveSystemUser(false);
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
      // Docker resources follow the same rule, for the same reason.
      const { data } = await deleteApplication(application.id, {
        removeFiles: removeFiles && !orphaned,
        removeDatabases: databases.length > 0 && removeDatabases,
        removeDockerResources:
          dockerResourceNames.length > 0 && removeDockerResources,
        removeSystemUser: canRemoveSystemUser && Boolean(systemUsername) && removeSystemUser,
      });

      // A 200 can still carry database failures: warn, naming what is left.
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
        // The server's own sentence: removed, or kept and why (still used, signed in).
        const account = data?.system_user;
        if (account?.message) {
          if (account.outcome === "removed") toast.success(account.message);
          else toast.warning(account.message, { duration: 15000 });
        }
      };
      if (afterDelete) await afterDelete();
      finish(say);
    } catch (error) {
      // 404: already deleted elsewhere; treat as done and refresh so the row leaves.
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
        {/* No system user, no home directory: the API skips files, so no choice is offered. */}
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

        {/* Only for a container site with something to remove, like the databases box. */}
        {dockerResourceNames.length ? (
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="delete-app-docker"
              checked={removeDockerResources}
              onCheckedChange={(value) => setRemoveDockerResources(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="delete-app-docker" className="text-sm font-medium">
                {t("removeDocker", { count: dockerResourceNames.length })}
              </Label>
              {/* Named, not counted. The caveat is stated while the box is still
                  unchecked -- it is what decides whether somebody ticks it. */}
              <p className="text-xs leading-5 text-muted-foreground">
                {t(removeDockerResources ? "removeDockerOn" : "removeDockerOff", {
                  count: dockerResourceNames.length,
                  names: dockerResourceNames.join(", "),
                })}
              </p>
            </div>
          </div>
        ) : null}

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

        {/* Only with the grant the API checks; otherwise say the account stays. */}
        {systemUsername && canRemoveSystemUser ? (
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="delete-app-system-user"
              checked={removeSystemUser}
              onCheckedChange={(value) => setRemoveSystemUser(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="delete-app-system-user" className="text-sm font-medium">
                {t("removeSystemUser", { username: systemUsername })}
              </Label>
              <p className="text-xs leading-5 text-muted-foreground">
                {removeSystemUser ? t("removeSystemUserOn") : t("systemUserStays", { username: systemUsername })}
              </p>
            </div>
          </div>
        ) : systemUsername ? (
          <p className="text-xs leading-5 text-muted-foreground">
            {t("systemUserStays", { username: systemUsername })}
          </p>
        ) : null}

        <div className="space-y-2">
          {/* `block` keeps the sentence flowing (Label is flex); one key so word order can vary. */}
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
