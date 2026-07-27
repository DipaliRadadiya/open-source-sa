"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { setSystemUserShell } from "@/lib/api/system-users";
import { SHELLS } from "@/lib/schemas/system-user";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

// Inline, editable login-shell picker used in the table. Read-only text when
// the caller can't manage.
export function ShellSelect({ user, canManage = true }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  if (!canManage) {
    return (
      <span className="font-mono text-xs text-muted-foreground">
        {user.shell}
      </span>
    );
  }

  async function onChange(v) {
    setBusy(true);
    try {
      await setSystemUserShell(user.id, v);
      toast.success(t("toast.shellChanged"));
      router.refresh();
    } catch (error) {
      toast.error(error.response?.data?.message || t("toast.failed"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Select value={user.shell} disabled={busy} onValueChange={onChange}>
      <SelectTrigger className="h-8 w-40 font-mono text-xs">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {SHELLS.map((s) => (
          <SelectItem key={s} value={s} className="font-mono text-xs">
            {s}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
