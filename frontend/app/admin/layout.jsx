import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { AuthProvider } from "@/components/auth-provider";
import { AdminSidebar } from "@/components/sections/admin-sidebar";
import { AdminHeader } from "@/components/sections/admin-header";
import { SidebarProvider, SidebarInset } from "@/components/ui/sidebar";
import { TooltipProvider } from "@/components/ui/tooltip";

export const dynamic = "force-dynamic";

// The Admin Panel: a self-contained shell (own sidebar + header) at /admin,
// gated purely on is_admin. Separate from the server (user) panel.
// Real enforcement is the Laravel Policies/Gates — this is the UX guard.
export default async function AdminLayout({ children }) {
  const user = await getCurrentUser();
  if (!user) redirect("/login");
  if (!user.is_admin) redirect("/dashboard");

  return (
    <AuthProvider user={user}>
      <TooltipProvider delayDuration={0}>
        <SidebarProvider style={{ "--sidebar-width-icon": "3.5rem" }}>
          <AdminSidebar />
          <SidebarInset className="min-w-0">
            <AdminHeader />
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
