import { getBasicInfo } from "@/lib/basic-info/get-basic-info";

// Server components get no path header, so the current path can't be added here.
export async function signedOutPath() {
  const { registration_open: registrationOpen } = await getBasicInfo();
  return registrationOpen ? "/register" : "/login";
}
