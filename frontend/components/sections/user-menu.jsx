import { useState } from "react";
import { useRouter } from "next/navigation";
import { LogOut, LogIn, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { useUser } from "@/hooks/use-user";
import { logout, stopImpersonating } from "@/lib/auth/auth-actions";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { apiMessage } from "@/lib/api/error-message";
import { initials } from "@/lib/format/initials";
import { useUnsaved } from "@/components/ui/unsaved-guard";

/**
 * Avatar dropdown shared by both panels' headers. `extraItems` slots panel-
 * specific actions (e.g. the Admin Panel / Exit switch) above Log out.
 */
export function UserMenu({ extraItems, impersonating = false }) {
  const router = useRouter();
  const user = useUser();
  const t = useTranslations("common");
  const tImp = useTranslations("impersonation");
  const { guardAction } = useUnsaved();
  const [leaving, setLeaving] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  async function performLogout() {
    setSigningOut(true);
    try {
      await logout();
    } finally {
      // Deliberately not cleared: the menu should keep saying "signing out"
      // right up until the login page replaces it, rather than flicking back
      // to "Log out" for the length of the navigation.
      router.push("/login");
      router.refresh();
    }
  }

  function onLogout() {
    if (!guardAction(performLogout)) performLogout();
  }

  async function performBackToAccount() {
    setLeaving(true);
    try {
      await stopImpersonating();
      // Session identity changed — hard nav so SSR re-reads the admin session.
      window.location.href = "/admin/users";
    } catch (error) {
      toast.error(apiMessage(error, tImp("banner.failed")));
      setLeaving(false);
    }
  }

  function onBackToAccount() {
    if (!guardAction(performBackToAccount)) performBackToAccount();
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="rounded-full ring-2 ring-transparent transition-shadow hover:ring-border data-[state=open]:ring-primary/40"
          aria-label={t("openUserMenu")}
        >
          <Avatar className="size-8">
            <AvatarFallback className="bg-primary/10 text-xs font-medium text-primary">
              {initials(user?.name)}
            </AvatarFallback>
          </Avatar>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56 p-0">
        {/* Identity header */}
        <div className="flex items-center gap-2.5 p-2.5">
          <Avatar className="size-9">
            <AvatarFallback className="bg-primary/10 text-xs font-medium text-primary">
              {initials(user?.name)}
            </AvatarFallback>
          </Avatar>
          <div className="flex min-w-0 flex-col">
            <span className="truncate text-sm font-semibold">{user?.name}</span>
            <span className="truncate text-xs text-muted-foreground">
              @{user?.username}
            </span>
          </div>
        </div>

        <DropdownMenuSeparator className="my-0" />

        <div className="space-y-0.5 p-1 [&_[role=menuitem]]:py-1.5">
          {impersonating ? (
            <>
              <DropdownMenuItem
                disabled={leaving || signingOut}
                onSelect={(event) => {
                  // Held open: Radix closes on select, and a menu that vanishes
                  // for the length of the request is why this never looked like
                  // it was doing anything.
                  event.preventDefault();
                  onBackToAccount();
                }}
              >
                {leaving ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <LogIn className="size-4" />
                )}
                {leaving ? tImp("menu.backPending") : tImp("menu.back")}
              </DropdownMenuItem>
              <DropdownMenuSeparator className="mx-0 my-1" />
            </>
          ) : null}
          {extraItems ? (
            <>
              {extraItems}
              <DropdownMenuSeparator className="mx-0 my-1" />
            </>
          ) : null}
          <DropdownMenuItem
            variant="destructive"
            disabled={signingOut || leaving}
            onSelect={(event) => {
              // Same reason as above: without this the menu disappears and the
              // page sits unchanged until the login screen arrives.
              event.preventDefault();
              onLogout();
            }}
          >
            {signingOut ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <LogOut className="size-4" />
            )}
            {signingOut ? t("loggingOut") : t("logOut")}
          </DropdownMenuItem>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
