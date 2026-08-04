import { redirect } from "next/navigation";
import { getCurrentUser, getImpersonator } from "@/lib/auth/get-current-user";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { AuthProvider } from "@/components/auth-provider";
import { AppSidebar } from "@/components/sections/app-sidebar";
import { AppHeader } from "@/components/sections/app-header";
import { ImpersonationBanner } from "@/components/sections/impersonation-banner";
import { RebootRequiredBanner } from "@/components/sections/reboot-required-banner";
import { getRebootRequired } from "@/lib/server/get-reboot-required";
import { can } from "@/lib/permissions/can";
import { SidebarProvider, SidebarInset } from "@/components/ui/sidebar";
import { TooltipProvider } from "@/components/ui/tooltip";
import { PageCrumbProvider } from "@/components/sections/page-crumb";
import { ApplicationNavProvider } from "@/components/sections/application-nav";

export const dynamic = "force-dynamic";

export default async function AppLayout({ children }) {
  const user = await getCurrentUser();
  if (!user) redirect("/login");

  const permissions = await getPermissions();
  const impersonatedBy = await getImpersonator();
  const rebootRequired = await getRebootRequired();

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={0}>
        <PageCrumbProvider>
          <ApplicationNavProvider>
            <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
              <AppSidebar items={permissions} />
              {/* min-w-0: without it this flex child keeps min-width:auto and wide
              content (tables/charts) pushes the page into horizontal overflow. */}
              <SidebarInset className="min-w-0">
                {/* Banner + header ride together as one sticky cluster, so while
                impersonating the indicator and its escape never scroll away. */}
                <div className="sticky top-0 z-20">
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
                  <AppHeader
                    items={permissions}
                    impersonating={!!impersonatedBy}
                  />
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
      </TooltipProvider>
    </AuthProvider>
  );
}
