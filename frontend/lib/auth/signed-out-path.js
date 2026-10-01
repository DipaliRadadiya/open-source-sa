import { getBasicInfo } from "@/lib/basic-info/get-basic-info";

// /register while no admin exists, else /login (the default). Cannot carry the
// current path: server components get no path header (see `last-path.js`).
export async function signedOutPath() {
  const { registration_open: registrationOpen } = await getBasicInfo();
  return registrationOpen ? "/register" : "/login";
}
