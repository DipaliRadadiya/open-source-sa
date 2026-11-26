import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { signedOutPath } from "@/lib/auth/signed-out-path";
import { RememberPath } from "@/components/remember-path";
import { AuthProvider } from "@/components/auth-provider";
import { AdminSidebar } from "@/components/sections/admin-sidebar";
import { AdminBreadcrumb } from "@/components/sections/admin-breadcrumb";
import { AdminHeader } from "@/components/sections/admin-header";
import { SidebarProvider, SidebarInset } from "@/components/ui/sidebar";
import { SidebarAutoCollapse } from "@/components/sections/sidebar-auto-collapse";
import { TooltipProvider } from "@/components/ui/tooltip";
import { UnsavedProvider } from "@/components/ui/unsaved-guard";
import { PanelFocus } from "@/components/sections/panel-focus";
import { RateLimited } from "@/components/sections/rate-limited";
import { isRateLimited } from "@/lib/api/rate-limited";
import { PanelUnavailable } from "@/components/sections/panel-unavailable";
import { isPanelUnavailable } from "@/lib/api/unavailable";
import { RequestFailed } from "@/components/sections/request-failed";
import { isRequestFailed, requestFailureProps } from "@/lib/api/request-failed";
import { ErrorCopy } from "@/components/sections/error-copy";

export const dynamic = "force-dynamic";

// UI guard only; the real check is in Laravel policies.
export default async function AdminLayout({ children }) {
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
  if (!user.is_admin) redirect("/");

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={300}>
      <ErrorCopy />
      <RememberPath />
        <UnsavedProvider>
        <PanelFocus />
        <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
          <SidebarAutoCollapse />
          <AdminSidebar />
          <SidebarInset className="min-w-0">
            <div className="sticky top-0 z-20">
              <AdminHeader breadcrumb={<AdminBreadcrumb />} />
            </div>
            <main id="main-content" tabIndex={-1} className="flex flex-1 flex-col">
              <div className="@container/page mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
                {children}
              </div>
            </main>
          </SidebarInset>
        </SidebarProvider>
        </UnsavedProvider>
      </TooltipProvider>
    </AuthProvider>
  );
}
