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
import { RateLimited } from "@/components/sections/rate-limited";
import { isRateLimited } from "@/lib/api/rate-limited";

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
    throw error;
  }
  if (!user) redirect(await signedOutPath());
  if (!user.is_admin) redirect("/dashboard");

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={0}>
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
            <main className="flex flex-1 flex-col">
              <div className="mx-auto w-full max-w-screen-xl flex-1 p-4 sm:p-6 lg:p-8">
                {children}
              </div>
            </main>
          </SidebarInset>
        </SidebarProvider>
      </TooltipProvider>
    </AuthProvider>
  );
}
