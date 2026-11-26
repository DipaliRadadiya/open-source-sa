import { Suspense } from "react";
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import { SIDEBAR_GROUPS_COOKIE, parseSidebarGroups } from "@/lib/navigation";
import { getCurrentUser, getImpersonator } from "@/lib/auth/get-current-user";
import { signedOutPath } from "@/lib/auth/signed-out-path";
import { RememberPath } from "@/components/remember-path";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { AuthProvider } from "@/components/auth-provider";
import { AppSidebar } from "@/components/sections/app-sidebar";
import { AppHeader } from "@/components/sections/app-header";
import { AppBreadcrumb } from "@/components/sections/app-breadcrumb";
import { ImpersonationBanner } from "@/components/sections/impersonation-banner";
import { RebootRequiredBanner } from "@/components/sections/reboot-required-banner";
import { getRebootRequired } from "@/lib/server/get-reboot-required";
import { can } from "@/lib/permissions/can";

const OPERATION_PERMISSIONS = ["backup", "application", "database", "php", "node", "sync", "setting"];
import { SidebarProvider, SidebarInset } from "@/components/ui/sidebar";
import { SidebarAutoCollapse } from "@/components/sections/sidebar-auto-collapse";
import { TooltipProvider } from "@/components/ui/tooltip";
import { PageCrumbProvider } from "@/components/sections/page-crumb";
import { RateLimited } from "@/components/sections/rate-limited";
import { isRateLimited } from "@/lib/api/rate-limited";
import { PanelUnavailable } from "@/components/sections/panel-unavailable";
import { isPanelUnavailable } from "@/lib/api/unavailable";
import { RequestFailed } from "@/components/sections/request-failed";
import { isRequestFailed, requestFailureProps } from "@/lib/api/request-failed";
import { ApplicationNavProvider } from "@/components/sections/application-nav";
import { UnsavedProvider } from "@/components/ui/unsaved-guard";
import { ServerRestartProvider } from "@/components/sections/server-restart-overlay";
import { AppChromeHeight } from "@/components/sections/app-chrome-height";
import { PanelFocus } from "@/components/sections/panel-focus";
import { ErrorCopy } from "@/components/sections/error-copy";
import { ReloadPendingProvider } from "@/components/applications/reload-pending-provider";
import { RELOAD_PENDING_COOKIE, parseReloadPending, recordsForUser } from "@/lib/applications/reload-pending";

export const dynamic = "force-dynamic";

export default async function AppLayout({ children }) {
  let user;
  try {
    user = await getCurrentUser();
  } catch (error) {
    if (isRateLimited(error)) return <RateLimited />;
    if (isPanelUnavailable(error)) return <PanelUnavailable />;
    if (isRequestFailed(error)) return <RequestFailed {...requestFailureProps(error)} />;
    throw error;
  }
  if (!user) redirect(await signedOutPath());

  let permissions, impersonatedBy;
  try {
    [permissions, impersonatedBy] = await Promise.all([getPermissions(), getImpersonator()]);
  } catch (error) {
    if (isRateLimited(error)) return <RateLimited />;
    if (isPanelUnavailable(error)) return <PanelUnavailable />;
    if (isRequestFailed(error)) return <RequestFailed {...requestFailureProps(error)} />;
    throw error;
  }

  const reloadPending = recordsForUser(parseReloadPending((await cookies()).get(RELOAD_PENDING_COOKIE)?.value), user.id);

  return (
    <AuthProvider user={user}>
      <ReloadPendingProvider userId={user.id} canManageServices={can(permissions, "service", "manage")} initial={reloadPending}>
      <TooltipProvider delayDuration={300}>
      <ErrorCopy />
      <RememberPath />
        <UnsavedProvider>
        {/* Above the shell so the restart overlay covers the sidebar and header too. */}
        <ServerRestartProvider>
        <PanelFocus />
        <PageCrumbProvider>
          <ApplicationNavProvider>
            <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
              <SidebarAutoCollapse />
              <AppSidebar items={permissions} initialGroups={parseSidebarGroups((await cookies()).get(SIDEBAR_GROUPS_COOKIE)?.value)} />
              {/* min-w-0 stops wide tables and charts from overflowing the page. */}
              <SidebarInset className="min-w-0">
                <div className="sticky top-0 z-20">
                  <AppChromeHeight />
                  {impersonatedBy ? (
                    <ImpersonationBanner
                      username={user.username}
                      admin={impersonatedBy.username}
                    />
                  ) : null}
                  <Suspense fallback={null}>
                    <RebootNotice canManage={can(permissions, "setting", "manage")} />
                  </Suspense>
                  <AppHeader
                    impersonating={!!impersonatedBy}
                    operations={
                      user?.is_admin || OPERATION_PERMISSIONS.some((name) => can(permissions, name, "view"))
                        ? { canOpenApplications: can(permissions, "application", "view") }
                        : null
                    }
                    breadcrumb={<AppBreadcrumb items={permissions} />}
                  />
                </div>
                <main id="main-content" tabIndex={-1} className="flex flex-1 flex-col">
                  <div className="@container/page mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
                    {children}
                  </div>
                </main>
              </SidebarInset>
            </SidebarProvider>
          </ApplicationNavProvider>
        </PageCrumbProvider>
        </ServerRestartProvider>
        </UnsavedProvider>
      </TooltipProvider>
      </ReloadPendingProvider>
    </AuthProvider>
  );
}

async function RebootNotice({ canManage }) {
  const required = await getRebootRequired();
  return required ? <RebootRequiredBanner canManage={canManage} /> : null;
}
