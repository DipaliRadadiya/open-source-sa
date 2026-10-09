import { Suspense } from "react";
import { redirect } from "next/navigation";
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

export const dynamic = "force-dynamic";

// A throw here reaches Next's unstyled error page; rate-limit errors get their own screen.
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

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={300}>
      <ErrorCopy />
      <RememberPath />
        {/* Panel-wide: any screen with a Save can lose an edit to a sidebar click. */}
        <UnsavedProvider>
        {/* Above the shell so the restart curtain covers sidebar and header too. */}
        <ServerRestartProvider>
        <PanelFocus />
        <PageCrumbProvider>
          <ApplicationNavProvider>
            <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
              <SidebarAutoCollapse />
              <AppSidebar items={permissions} />
              {/* min-w-0: otherwise wide tables/charts push the page into horizontal overflow. */}
              <SidebarInset className="min-w-0">
                {/* One sticky cluster so the impersonation banner never scrolls away. */}
                <div className="sticky top-0 z-20">
                  {/* Publishes this cluster's height as `--app-chrome` for other sticky elements. */}
                  <AppChromeHeight />
                  {impersonatedBy ? (
                    <ImpersonationBanner
                      username={user.username}
                      admin={impersonatedBy.username}
                    />
                  ) : null}
                  {/* Streamed: answering it runs commands on the server (~0.7 s), and every
                      page waited on it before showing anything (Krishna, 7 Oct). */}
                  <Suspense fallback={null}>
                    <RebootNotice canManage={can(permissions, "setting", "manage")} />
                  </Suspense>
                  {/* One bar: the trail sits beside the controls rather than in a second band. */}
                  <AppHeader
                    impersonating={!!impersonatedBy}
                    breadcrumb={<AppBreadcrumb items={permissions} />}
                  />
                </div>
                <main id="main-content" tabIndex={-1} className="flex flex-1 flex-col">
                  <div className="mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
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
    </AuthProvider>
  );
}

async function RebootNotice({ canManage }) {
  const required = await getRebootRequired();
  return required ? <RebootRequiredBanner canManage={canManage} /> : null;
}
