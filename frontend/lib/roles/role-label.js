// The built-in Administrator role is seeded in English and can't be edited, so translate it here.
const isBuiltInAdministrator = (role) => Boolean(role?.is_system) && role?.slug === "administrator";

export function roleName(role, t) {
  return isBuiltInAdministrator(role) ? t("builtIn.administrator.name") : role?.name;
}

export function roleDescription(role, t) {
  return isBuiltInAdministrator(role) ? t("builtIn.administrator.description") : role?.description;
}
