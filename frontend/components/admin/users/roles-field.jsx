import { useTranslations } from "next-intl";
import { Checkbox } from "@/components/ui/checkbox";

/**
 * Multi-select for a user's permission roles. `roles` is the catalog
 * ([{ id, name, description? }]); `value` is an array of selected role ids.
 * Every user needs ≥1 role (enforced by the form schema).
 */
export function RolesField({ roles, value = [], onChange, failed = false }) {
  const t = useTranslations("users");

  function toggle(id, checked) {
    onChange(checked ? [...value, id] : value.filter((v) => v !== id));
  }

  // "None exist" and "we could not ask" are different, and the old copy said
  // the first for both: "No roles exist yet. Create a role first" sent an
  // administrator off to create something that was already there.
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
                {role.name}
              </div>
              {role.description ? (
                <p className="line-clamp-2 text-xs text-muted-foreground">
                  {role.description}
                </p>
              ) : null}
            </div>
          </label>
        );
      })}
    </div>
  );
}
