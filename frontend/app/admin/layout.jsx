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

// Admin Panel shell at /admin, gated on is_admin. This is the UX guard; real
// enforcement is Laravel Policies/Gates.
export default async function AdminLayout({ children }) {
  let user;
  try {
    user = await getCurrentUser();
  } catch (error) {
    // A throw in a layout escapes every error.jsx below it.
    if (isRateLimited(error)) return <RateLimited />;
    if (isPanelUnavailable(error)) return <PanelUnavailable />;
    if (isRequestFailed(error)) return <RequestFailed {...requestFailureProps(error)} />;
    throw error;
  }
  if (!user) redirect(await signedOutPath());
  /*
   * Home, not /dashboard: a layout is the shell, so it cannot render an in-place
   * refusal, and /dashboard is not open to every role. `app/page.js` picks the
   * destination from the caller's permissions.
   */
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
            {/* Header + trail form one sticky cluster, identical to the server panel. */}
            <div className="sticky top-0 z-20">
              <AdminHeader />
              <div className="border-b bg-muted/95 backdrop-blur supports-[backdrop-filter]:bg-muted/70">
                <div className="mx-auto w-full max-w-screen-xl px-4 py-2.5 sm:px-6 lg:px-8">
                  <AdminBreadcrumb />
                </div>
              </div>
            </div>
            <main id="main-content" tabIndex={-1} className="flex flex-1 flex-col">
              <div className="mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
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
