import { redirect } from "next/navigation";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { landingPath } from "@/lib/permissions/landing-path";

export default async function Home() {
  redirect(landingPath(await getPermissions()));
}
