"use client";

import { useRef, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import {
  ExternalLink,
  LayoutDashboard,
  Loader2,
  KeyRound,
  MoreHorizontal,
  PauseCircle,
  PlayCircle,
  RotateCw,
  Trash2,
} from "lucide-react";
import { enableApplication, retryProvisioning } from "@/lib/api/applications";
import { pauseControl } from "@/lib/applications/pause-control";
import { containerRedeployInFlight } from "@/lib/applications/settled";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { DeleteApplicationDialog } from "@/components/applications/delete-application-dialog";
import { PauseApplicationDialog } from "@/components/applications/pause-application-dialog";
import { RestartContainersDialog } from "@/components/applications/restart-containers-dialog";
import { MagicLoginDialog } from "@/components/applications/magic-login-dialog";
import { useMagicLogin } from "@/components/applications/use-magic-login";

export function ApplicationRowActions({
  application,
  canManage = false,
  canMagicLogin = false,
  canRemoveSystemUser = false,
  canManageDocker = false,
  showNavigation = true,
  canOpen = true,
  afterDelete,
  redirectTo,
  triggerVariant = "ghost",
  triggerClassName = "size-8",
}) {
  const t = useTranslations("applications");
  const { refreshThen } = useRefresh();
  const [retrying, setRetrying] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const openingDialog = useRef(false);
  const magicLogin = useMagicLogin(application.id);
  const showMagicLogin =
    canMagicLogin &&
    application.site_type === "wordpress" &&
    application.status === "active";

  // Synced during render: an effect would paint the stale menu once first.
  const [seenStatus, setSeenStatus] = useState(application.status);
  if (seenStatus !== application.status) {
    setSeenStatus(application.status);
    if (menuOpen) setMenuOpen(false);
  }
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [pauseOpen, setPauseOpen] = useState(false);
  const [restartOpen, setRestartOpen] = useState(false);
  const [resuming, setResuming] = useState(false);

  const href = `/applications/${application.id}`;
  const canVisit = application.status === "active";
  const canRetry = application.status === "failed";
  const showRetry = canManage && canRetry;
  const pauseAction = pauseControl(application, { canManage });
  const showRestart = canManage && application.serving_profile === "docker";
  const redeploying = containerRedeployInFlight(application);
  const restartHint = application.is_disabled
    ? t("restart.pausedHint")
    : application.status !== "active"
      ? t("restart.notReadyHint")
      : redeploying
        ? t("redeploy.busy")
        : null;

  if (!showNavigation && !showRetry && !canManage) return null;

  async function resume() {
    setResuming(true);
    try {
      await enableApplication(application.id);
      refreshThen(() => {
        toast.success(t("pause.resumed", { name: application.name }));
        setResuming(false);
        setMenuOpen(false);
      });
    } catch (error) {
      toast.error(apiMessage(error, t("pause.resumeFailed")));
      setResuming(false);
    }
  }

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      refreshThen(() => setRetrying(false));
    } catch (error) {
      toast.error(apiMessage(error, t("details.failedAt", { step: provisionStepLabel(application.failed_step, t, "details.") })));
      setRetrying(false);
    }
  }

  return (
    <div className="text-right">
      <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
        <DropdownMenuTrigger asChild>
          <Button variant={triggerVariant} size="icon" className={triggerClassName}>
            {retrying || magicLogin.pending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <MoreHorizontal className="size-4" />
            )}
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-52"
          onCloseAutoFocus={(e) => {
            if (!openingDialog.current) return;
            openingDialog.current = false;
            e.preventDefault();
          }}
        >
          {showNavigation ? (
            <>
              {canOpen ? (
                <DropdownMenuItem asChild>
                  <Link href={href}>
                    <LayoutDashboard className="size-4" />
                    {t("actions.open")}
                  </Link>
                </DropdownMenuItem>
              ) : null}

              {/* Use url, not domain: the API serves http:// until the site has a certificate. */}
              <MenuItemHint hint={canVisit ? null : t("actions.visitHint")}>
                <DropdownMenuItem asChild={canVisit} disabled={!canVisit}>
                  {canVisit ? (
                    <a href={application.url ?? `https://${application.domain}`} target="_blank" rel="noreferrer">
                      <ExternalLink className="size-4" />
                      {t("actions.visit")}
                    </a>
                  ) : (
                    <>
                      <ExternalLink className="size-4" />
                      {t("actions.visit")}
                    </>
                  )}
                </DropdownMenuItem>
              </MenuItemHint>
            </>
          ) : null}

          {showMagicLogin ? (
            <DropdownMenuItem
              disabled={magicLogin.pending}
              onSelect={(event) => {
                event.preventDefault();
                openingDialog.current = true;
                magicLogin.start().then(() => setMenuOpen(false));
              }}
            >
              {magicLogin.pending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <KeyRound className="size-4" />
              )}
              {magicLogin.phase === "fetching"
                ? t("magicLogin.fetchingUsers")
                : magicLogin.phase === "signing"
                  ? t("magicLogin.redirecting")
                  : t("magicLogin.action")}
            </DropdownMenuItem>
          ) : null}

          {showRetry ? (
            <DropdownMenuItem
              disabled={retrying}
              onSelect={(event) => {
                // Radix closes the menu on select; hold it open so the item can show Retrying.
                event.preventDefault();
                retry();
              }}
            >
              {retrying ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <RotateCw className="size-4" />
              )}
              {retrying ? t("details.retrying") : t("details.retry")}
            </DropdownMenuItem>
          ) : null}

          {canManage ? (
            <>
              {showNavigation || showRetry || showMagicLogin ? <DropdownMenuSeparator /> : null}
              {showRestart ? (
                <MenuItemHint hint={restartHint}>
                  <DropdownMenuItem
                    disabled={Boolean(restartHint)}
                    onSelect={() => { openingDialog.current = true; setRestartOpen(true); }}
                  >
                    <RotateCw className="size-4" />
                    {t("restart.action")}
                  </DropdownMenuItem>
                </MenuItemHint>
              ) : null}
              {pauseAction === "resume" ? (
                <DropdownMenuItem
                  disabled={resuming}
                  onSelect={(event) => {
                    event.preventDefault();
                    resume();
                  }}
                >
                  {resuming ? (
                    <Loader2 className="size-4 animate-spin" />
                  ) : (
                    <PlayCircle className="size-4" />
                  )}
                  {resuming ? t("pause.resuming") : t("pause.resume")}
                </DropdownMenuItem>
              ) : pauseAction === "pause" ? (
                <DropdownMenuItem onSelect={() => { openingDialog.current = true; setPauseOpen(true); }}>
                  <PauseCircle className="size-4" />
                  {t("pause.action")}
                </DropdownMenuItem>
              ) : null}
              <MenuItemHint hint={redeploying ? t("redeploy.busy") : null}>
                <DropdownMenuItem
                  variant="destructive"
                  disabled={redeploying}
                  onSelect={() => { openingDialog.current = true; setDeleteOpen(true); }}
                >
                  <Trash2 className="size-4" />
                  {t("actions.delete")}
                </DropdownMenuItem>
              </MenuItemHint>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      {magicLogin.choice ? (
        <MagicLoginDialog
          appId={application.id}
          admins={magicLogin.choice.admins}
          open
          onOpenChange={(next) => !next && magicLogin.closeChoice()}
        />
      ) : null}

      <PauseApplicationDialog
        application={application}
        open={pauseOpen}
        onOpenChange={setPauseOpen}
      />

      {showRestart ? (
        <RestartContainersDialog
          application={application}
          open={restartOpen}
          onOpenChange={setRestartOpen}
        />
      ) : null}

      <DeleteApplicationDialog
        application={application}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        afterDelete={afterDelete}
        redirectTo={redirectTo}
        canRemoveSystemUser={canRemoveSystemUser}
        canManageDocker={canManageDocker}
      />
    </div>
  );
}
