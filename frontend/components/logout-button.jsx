"use client";

import { useRouter } from "next/navigation";
import { logout } from "@/lib/auth/auth-actions";
import { Button } from "@/components/ui/button";

export function LogoutButton() {
  const router = useRouter();

  async function onLogout() {
    try {
      await logout();
    } finally {
      router.push("/login");
      router.refresh();
    }
  }

  return (
    <Button variant="outline" onClick={onLogout}>
      Log out
    </Button>
  );
}
