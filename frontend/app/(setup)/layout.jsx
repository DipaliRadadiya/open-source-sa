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

/**
 * A focused shell for first-run setup: authenticated but without the app
 * sidebar. No session → login; no `setting` permission → home.
 */
export default async function SetupLayout({ children }) {
  const user = await getCurrentUser();
  if (!user) redirect(await signedOutPath());

  const permissions = await getPermissions();
  /*
   * A layout cannot render an in-place refusal (it IS the shell), so redirect
   * to "/", which `app/page.js` resolves from the caller's permissions;
   * /dashboard is not open to every role.
   */
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
          {/* 840px: narrower feels like a lost dialog; wider strands the
              one-line descriptions. */}
          <div className="w-full max-w-[840px]">{children}</div>
        </main>
      </div>
    </TooltipProvider>
  );
}
