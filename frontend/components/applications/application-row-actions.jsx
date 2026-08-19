"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { provisionStepLabel } from "@/lib/applications/provision-steps";
import { toast } from "sonner";
import {
  Archive,
  ExternalLink,
  FolderTree,
  Globe2,
  LayoutDashboard,
  Loader2,
  MoreHorizontal,
  Pencil,
  RotateCw,
  Ruler,
  Trash2,
} from "lucide-react";
import { measureApplicationSize, retryProvisioning } from "@/lib/api/applications";
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
import { WebRootDialog } from "@/components/applications/web-root-dialog";

/**
 * The screens worth reaching from the header menu, in the order a site is
 * usually worked on. Keyed rather than passed whole because the caller is a
 * server component: a Lucide component cannot cross that boundary, so the
 * server sends the keys it has permission for and the icon is resolved here.
 */
const SHORTCUT_ICONS = {
  files: FolderTree,
  domains: Globe2,
  backups: Archive,
};

/**
 * Row menu for one application. Open and Visit are reads and stay available to
 * view-only users; Retry and Delete are writes and need `manage`.
 *
 * Visit is only offered while the site is actually being served — a link that
 * lands on a connection error teaches people the panel is lying.
 */
export function ApplicationRowActions({
  application,
  canManage = false,
  // On the application's own dashboard page, "Open dashboard" points at the
  // current page and "Visit" duplicates the header button — hide both there.
  showNavigation = true,
  // Keys of the app screens this user may open, already permission-filtered by
  // the server. Only the detail header passes these — on the list, five extra
  // links per row would bury Delete under navigation nobody opens a row menu
  // for.
  shortcuts = [],
  // Called after a successful delete, before redirecting (if redirectTo is set).
  // Useful on the list page to refresh the table immediately.
  afterDelete,
  // Where to navigate after deleting from a page where staying doesn't make sense.
  // Passed through to DeleteApplicationDialog.
  redirectTo,
}) {
  const t = useTranslations("applications");
  const router = useRouter();
  const [retrying, setRetrying] = useState(false);
  const [measuring, setMeasuring] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [webRootOpen, setWebRootOpen] = useState(false);

  const href = `/applications/${application.id}`;
  const canVisit = application.status === "active";
  const canRetry = application.status === "failed";
  const showRetry = canManage && canRetry;

  // Nothing to offer (view-only, on the detail page) → no empty ⋯ trigger.
  if (!showNavigation && !showRetry && !canManage && shortcuts.length === 0) return null;

  // Walks every inode on the site, so it is the user's decision and never a
  // side effect of opening a screen. Lives here because it is a per-row action
  // like every other item in this menu — the Size cell shows the answer, not a
  // control repeated down the column.
  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(application.id);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  async function retry() {
    setRetrying(true);
    try {
      await retryProvisioning(application.id);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("details.failedAt", { step: provisionStepLabel(application.failed_step, t, "details.") })));
    } finally {
      setRetrying(false);
    }
  }

  return (
    <div className="text-right">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            {retrying || measuring ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <MoreHorizontal className="size-4" />
            )}
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-52" onCloseAutoFocus={(e) => e.preventDefault()}>
          {showNavigation ? (
            <>
              <DropdownMenuItem asChild>
                <Link href={href}>
                  <LayoutDashboard className="size-4" />
                  {t("actions.open")}
                </Link>
              </DropdownMenuItem>

              {/* `url`, never assembled from `domain`: the API serves http://
                  until the site has a servable certificate, which every site
                  lacks for the first few minutes of its life. An assumed
                  https:// is a connection refused, because a site with no
                  certificate has no TLS listener at all. */}
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

          {shortcuts.length > 0 ? (
            <>
              {shortcuts.map((key) => {
                const Icon = SHORTCUT_ICONS[key];
                return (
                  <DropdownMenuItem key={key} asChild>
                    <Link href={`${href}/${key}`} prefetch={false}>
                      <Icon className="size-4" />
                      {t(`actions.go.${key}`)}
                    </Link>
                  </DropdownMenuItem>
                );
              })}
              {/* The only property of an application the API can update. Sits
                  with the navigation because it is what "edit this app" means
                  here — there is no other editable field and no settings
                  screen to send anyone to. */}
              {canManage ? (
                <DropdownMenuItem onSelect={() => setWebRootOpen(true)}>
                  <Pencil className="size-4" />
                  {t("webRoot.title")}
                </DropdownMenuItem>
              ) : null}
              <DropdownMenuSeparator />
            </>
          ) : null}

          <DropdownMenuItem disabled={measuring} onSelect={measure}>
            <Ruler className="size-4" />
            {measuring ? t("size.measuring") : t("size.measure")}
          </DropdownMenuItem>

          {showRetry ? (
            <DropdownMenuItem disabled={retrying} onSelect={retry}>
              <RotateCw className="size-4" />
              {retrying ? t("details.retrying") : t("details.retry")}
            </DropdownMenuItem>
          ) : null}

          {canManage ? (
            <>
              {showNavigation || showRetry ? <DropdownMenuSeparator /> : null}
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleteOpen(true)}>
                <Trash2 className="size-4" />
                {t("actions.delete")}
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      <WebRootDialog
        application={application}
        open={webRootOpen}
        onOpenChange={setWebRootOpen}
      />

      <DeleteApplicationDialog
        application={application}
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        afterDelete={afterDelete}
        redirectTo={redirectTo}
      />
    </div>
  );
}
