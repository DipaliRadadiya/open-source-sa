import { redirect } from "next/navigation";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { landingPath } from "@/lib/permissions/landing-path";

/*
 * The panel's front door and the only place that decides where "in" is: not
 * every role can open /dashboard, and only the server knows the caller's
 * permissions.
 */
export default async function Home() {
  redirect(landingPath(await getPermissions()));
}
