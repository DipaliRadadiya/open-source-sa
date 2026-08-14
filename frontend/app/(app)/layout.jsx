import { redirect } from "next/navigation";
import { getCurrentUser, getImpersonator } from "@/lib/auth/get-current-user";
import { signedOutPath } from "@/lib/auth/signed-out-path";
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
import { ApplicationNavProvider } from "@/components/sections/application-nav";
import { UnsavedProvider } from "@/components/ui/unsaved-guard";
import { AppChromeHeight } from "@/components/sections/app-chrome-height";

export const dynamic = "force-dynamic";

/**
 * This layout sits above every error.jsx in the panel, so a throw here escapes
 * to Next's own unstyled error page — which is exactly what a rate-limited
 * session looked like. Caught by identity and answered with a screen that says
 * so; anything else still reaches the boundary.
 *
 * The session must be resolved before the redirect, but the other three are
 * independent of each other, so they go together rather than in a waterfall.
 */
export default async function AppLayout({ children }) {
  let user;
  try {
    user = await getCurrentUser();
  } catch (error) {
    if (isRateLimited(error)) return <RateLimited />;
    throw error;
  }
  if (!user) redirect(await signedOutPath());

  let permissions, impersonatedBy, rebootRequired;
  try {
    [permissions, impersonatedBy, rebootRequired] = await Promise.all([
      getPermissions(),
      getImpersonator(),
      getRebootRequired(),
    ]);
  } catch (error) {
    if (isRateLimited(error)) return <RateLimited />;
    throw error;
  }

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={0}>
        {/* Panel-wide, not settings-only. Any screen with its own Save can
            lose an edit to a sidebar click, and every one of them did. */}
        <UnsavedProvider>
        <PageCrumbProvider>
          <ApplicationNavProvider>
            <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
              <SidebarAutoCollapse />
              <AppSidebar items={permissions} />
              {/* min-w-0: without it this flex child keeps min-width:auto and wide
              content (tables/charts) pushes the page into horizontal overflow. */}
              <SidebarInset className="min-w-0">
                {/* Banner + header ride together as one sticky cluster, so while
                impersonating the indicator and its escape never scroll away. */}
                <div className="sticky top-0 z-20">
                  {/* Publishes this cluster's measured height as `--app-chrome`
                      so anything else that sticks can clear it. Its height is
                      conditional — see the component. */}
                  <AppChromeHeight />
                  {impersonatedBy ? (
                    <ImpersonationBanner
                      username={user.username}
                      admin={impersonatedBy.username}
                    />
                  ) : null}
                  {rebootRequired ? (
                    <RebootRequiredBanner
                      canManage={can(permissions, "setting", "manage")}
                    />
                  ) : null}
                  <AppHeader impersonating={!!impersonatedBy} />
                  {/* Its own band under the header rather than a line of text
                      above the h1: the trail is chrome, and sharing the page's
                      background made it read as a stray first line of the
                      heading. Full-bleed so the rule actually divides, with the
                      crumb held to the content column so it still lines up.

                      Inside the sticky cluster, not `top-16` in <main>: the
                      banners above it are conditional, so any fixed offset is
                      wrong the moment someone is impersonating. Frosted rather
                      than a flat tint because a translucent band would show the
                      page scrolling through it. */}
                  <div className="border-b bg-muted/95 backdrop-blur supports-[backdrop-filter]:bg-muted/70">
                    <div className="mx-auto w-full max-w-screen-xl px-4 py-2.5 sm:px-6 lg:px-8">
                      <AppBreadcrumb items={permissions} />
                    </div>
                  </div>
                </div>
                <main className="flex flex-1 flex-col">
                  <div className="mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
                    {children}
                  </div>
                </main>
              </SidebarInset>
            </SidebarProvider>
          </ApplicationNavProvider>
        </PageCrumbProvider>
        </UnsavedProvider>
      </TooltipProvider>
    </AuthProvider>
  );
}
