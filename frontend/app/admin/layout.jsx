import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { signedOutPath } from "@/lib/auth/signed-out-path";
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

// The Admin Panel: a self-contained shell (own sidebar + header) at /admin,
// gated purely on is_admin. Separate from the server (user) panel.
// Real enforcement is the Laravel Policies/Gates — this is the UX guard.
export default async function AdminLayout({ children }) {
  let user;
  try {
    user = await getCurrentUser();
  } catch (error) {
    // Same reason as the server panel: a throw in a layout escapes every
    // error.jsx below it.
    if (isRateLimited(error)) return <RateLimited />;
    if (isPanelUnavailable(error)) return <PanelUnavailable />;
    if (isRequestFailed(error)) return <RequestFailed {...requestFailureProps(error)} />;
    throw error;
  }
  if (!user) redirect(await signedOutPath());
  /*
   * Home, not /dashboard.
   *
   * The other 34 gates now refuse in place and name the screen that was
   * refused. These two cannot: a layout IS the shell, so there is no shell
   * left to render the refusal inside. A redirect is right here — it just has
   * to go somewhere the caller can actually open, which /dashboard is not for
   * every role. `app/page.js` picks that from their own permissions.
   */
  if (!user.is_admin) redirect("/");

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={300}>
      <ErrorCopy />
        <UnsavedProvider>
        <PanelFocus />
        <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
          <SidebarAutoCollapse />
          <AdminSidebar />
          <SidebarInset className="min-w-0">
            {/* Header + trail ride together as one sticky cluster, same as the
                server panel — the two shells stay identical. */}
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
