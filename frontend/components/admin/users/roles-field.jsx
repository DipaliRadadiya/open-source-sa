import { roleName, roleDescription } from "@/lib/roles/role-label";
import { useTranslations } from "next-intl";
import { Checkbox } from "@/components/ui/checkbox";

// `value` is an array of role ids; at least one is required (form schema).
export function RolesField({ roles, value = [], onChange, failed = false }) {
  const t = useTranslations("users");
  const tr = useTranslations("roles");

  function toggle(id, checked) {
    onChange(checked ? [...value, id] : value.filter((v) => v !== id));
  }

  // A failed load must not read as "no roles exist".
  if (failed) {
    return (
      <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive">
        {t("form.rolesLoadFailed")}
      </div>
    );
  }

  if (roles.length === 0) {
    return (
      <div className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
        {t("form.noRoles")}
      </div>
    );
  }

  return (
    <div className="max-h-52 overflow-y-auto rounded-lg border p-1.5">
      {roles.map((role) => {
        const checked = value.includes(role.id);
        return (
          <label
            key={role.id}
            className="flex cursor-pointer items-start gap-3 rounded-md px-3 py-2 transition-colors hover:bg-muted/50"
          >
            <Checkbox
              checked={checked}
              onCheckedChange={(c) => toggle(role.id, c === true)}
            />
            <div className="min-w-0 space-y-0.5">
              <div className="flex min-h-4 items-center text-sm font-medium leading-none">
                {roleName(role, tr)}
              </div>
              {roleDescription(role, tr) ? (
                <p className="line-clamp-2 text-xs text-muted-foreground">
                  {roleDescription(role, tr)}
                </p>
              ) : null}
            </div>
          </label>
        );
      })}
    </div>
  );
}
