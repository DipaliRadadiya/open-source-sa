import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth/get-current-user";
import { signedOutPath } from "@/lib/auth/signed-out-path";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { Logo } from "@/components/logo";
import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { ThemeToggle } from "@/components/theme-toggle";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ErrorCopy } from "@/components/sections/error-copy";

export const dynamic = "force-dynamic";

export default async function SetupLayout({ children }) {
  const user = await getCurrentUser();
  if (!user) redirect(await signedOutPath());

  const permissions = await getPermissions();
  // A layout can't render an in-place refusal, so redirect.
  if (!can(permissions, "setting", "view")) redirect("/");

  return (
    <TooltipProvider delayDuration={300}>
      <ErrorCopy />
      <div className="flex min-h-svh flex-col bg-gradient-to-b from-muted/30 via-background to-muted/50">
        <header className="flex items-center justify-between px-4 py-4 sm:px-6">
          <Logo className="h-8 w-auto" />
          <div className="flex items-center gap-2">
            <LocaleSwitcher />
            <ThemeToggle />
          </div>
        </header>
        <main className="flex flex-1 justify-center px-4 pb-16 pt-2 sm:pt-6">
          <div className="w-full max-w-[840px]">{children}</div>
        </main>
      </div>
    </TooltipProvider>
  );
}
