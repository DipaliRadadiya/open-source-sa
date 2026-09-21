import { redirect } from "next/navigation";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { landingPath } from "@/lib/permissions/landing-path";

/*
 * The panel's front door, and the only place that decides where "in" is.
 *
 * It sent everyone to /dashboard, which a role without `dashboard.view`
 * cannot open — so a restricted user's first screen after signing in was a
 * refusal. The login form now pushes here instead of guessing, because the
 * browser does not know the caller's permissions and this does.
 */
export default async function Home() {
  redirect(landingPath(await getPermissions()));
}
