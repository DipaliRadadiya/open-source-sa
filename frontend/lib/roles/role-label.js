// The built-in Administrator role is seeded in English and cannot be renamed or edited,
// so its name and description are translated here. Every other role is the admin's
// own text and is shown as written. `t` is useTranslations("roles").
const isBuiltInAdministrator = (role) => Boolean(role?.is_system) && role?.slug === "administrator";

export function roleName(role, t) {
  return isBuiltInAdministrator(role) ? t("builtIn.administrator.name") : role?.name;
}

export function roleDescription(role, t) {
  return isBuiltInAdministrator(role) ? t("builtIn.administrator.description") : role?.description;
}
