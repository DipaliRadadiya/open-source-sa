import { redirect } from "next/navigation";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { landingPath } from "@/lib/permissions/landing-path";

// Not every role can open /dashboard; only the server knows the caller's permissions.
export default async function Home() {
  redirect(landingPath(await getPermissions()));
}
