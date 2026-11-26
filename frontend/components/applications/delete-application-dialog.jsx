import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { useRefresh } from "@/hooks/use-refresh";
import { TriangleAlert } from "lucide-react";
import { TypeToConfirm } from "@/components/ui/type-to-confirm";
import { deleteApplication } from "@/lib/api/applications";
import { getDatabasesForApplication } from "@/lib/api/databases";
import { z } from "zod";
import { databaseSchema } from "@/lib/schemas/database";
import { apiMessage } from "@/lib/api/error-message";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { getSystemUserDetail } from "@/lib/api/system-users";

export function DeleteApplicationDialog({ application, open, onOpenChange, afterDelete, redirectTo, closeWhenGone = false, canRemoveSystemUser = false, canManageDocker = false }) {
  const t = useTranslations("applications.delete");
  const router = useRouter();
  const { refreshThen, pushAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const [awaitingPage, setAwaitingPage] = useState(false);
  const [confirm, setConfirm] = useState("");
  const [removeFiles, setRemoveFiles] = useState(true);
  const orphaned = application?.system_user === null;
  const [removeDatabases, setRemoveDatabases] = useState(true);
  const [removeSystemUser, setRemoveSystemUser] = useState(false);
  const systemUsername = application?.system_user?.username ?? null;
  const systemUserId = application?.system_user?.id ?? null;
  const [othersOnUser, setOthersOnUser] = useState(null);
  const [removeDockerResources, setRemoveDockerResources] = useState(true);
  const [removeDockerImages, setRemoveDockerImages] = useState(true);
  const isDocker = application?.serving_profile === "docker";
  const [databases, setDatabases] = useState([]);

  const dockerResourceNames = [
    ...(application.volume_mounts ?? []).map((mount) => mount.volume),
    ...(application.docker_network ? [application.docker_network] : []),
  ].filter((name, index, all) => name && all.indexOf(name) === index);

  useEffect(() => {
    if (!open || !systemUserId || !canRemoveSystemUser) return undefined;
    let active = true;
    getSystemUserDetail(systemUserId)
      .then((res) => {
        const apps = res.data?.system_user?.applications;
        if (active && Array.isArray(apps)) setOthersOnUser(apps.filter((app) => app.id !== application.id).length);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [open, systemUserId, canRemoveSystemUser, application?.id]);

  useEffect(() => {
    if (!open || !application?.id) return undefined;

    const controller = new AbortController();
    getDatabasesForApplication(application.id, { signal: controller.signal })
      .then(({ data }) => {
        // Parse the rows, not the envelope, so a meta change can't hide the checkbox.
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
      setRemoveDockerResources(true);
      setRemoveDockerImages(true);
      setDatabases([]);
    }
    onOpenChange?.(next);
  }

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
      const { data } = await deleteApplication(application.id, {
        removeFiles: removeFiles && !orphaned,
        removeDatabases: databases.length > 0 && removeDatabases,
        removeDockerResources:
          canManageDocker && dockerResourceNames.length > 0 && removeDockerResources,
        removeDockerImages: canManageDocker && isDocker ? removeDockerImages : null,
        removeSystemUser: canRemoveSystemUser && Boolean(systemUsername) && !(othersOnUser > 0) && removeSystemUser,
      });

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
        const account = data?.system_user;
        if (account?.message) {
          if (account.outcome === "removed") toast.success(account.message);
          else toast.warning(account.message, { duration: 15000 });
        }
      };
      if (afterDelete) await afterDelete();
      finish(say);
    } catch (error) {
      if (error?.response?.status === 404) {
        if (afterDelete) await afterDelete();
        finish(() => toast.info(t("alreadyGone", { name: application.name })));
        return;
      }
      toast.error(apiMessage(error, t("failed")));
      setPending(false);
    }
  }

  function finish(say) {
    const done = () => {
      say();
      handleOpenChange(false);
      setPending(false);
    };
    if (closeWhenGone) {
      announce.current = say;
      router.refresh();
      setAwaitingPage(true);
      return;
    }
    if (redirectTo) {
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

        {canManageDocker && dockerResourceNames.length ? (
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
              <p className="text-xs leading-5 text-muted-foreground">
                {t(removeDockerResources ? "removeDockerOn" : "removeDockerOff", {
                  count: dockerResourceNames.length,
                  names: dockerResourceNames.join(", "),
                })}
              </p>
            </div>
          </div>
        ) : null}

        {canManageDocker && isDocker ? (
          <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
            <Checkbox
              id="delete-app-docker-images"
              checked={removeDockerImages}
              onCheckedChange={(value) => setRemoveDockerImages(value === true)}
              className="mt-0.5"
            />
            <div className="space-y-1">
              <Label htmlFor="delete-app-docker-images" className="text-sm font-medium">
                {t("removeDockerImages")}
              </Label>
              <p className="text-xs leading-5 text-muted-foreground">{t("removeDockerImagesHint")}</p>
            </div>
          </div>
        ) : null}

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
              <p className="text-xs leading-5 text-muted-foreground">
                {t(removeDatabases ? "removeDatabasesOn" : "removeDatabasesOff", {
                  count: databases.length,
                  databases: databases.map((row) => row.name).join(", "),
                })}
              </p>
            </div>
          </div>
        ) : null}

        {systemUsername && canRemoveSystemUser && othersOnUser > 0 ? (
          <p className="text-xs leading-5 text-muted-foreground">
            {t("systemUserShared", { username: systemUsername, count: othersOnUser })}
          </p>
        ) : systemUsername && canRemoveSystemUser ? (
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
                {t("removeSystemUserOn")}
              </p>
            </div>
          </div>
        ) : systemUsername ? (
          <p className="text-xs leading-5 text-muted-foreground">
            {t("systemUserStays", { username: systemUsername })}
          </p>
        ) : null}

        <TypeToConfirm
          id="delete-app-confirm"
          value={domain}
          copyLabel={t("copyDomain")}
          confirm={confirm}
          onChange={setConfirm}
        />
      </div>
    </ConfirmDialog>
  );
}
