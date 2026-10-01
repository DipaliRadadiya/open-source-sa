import { getBasicInfo } from "@/lib/basic-info/get-basic-info";

// Where to send an unauthenticated visitor: /register while no admin exists
// (registration_open), otherwise /login. Fail-safe: basic-info defaults to
// registration_open:false → /login.
//
// It cannot carry the current path: server components here get no path
// header. The client-side recorder handles that (see `last-path.js`).
export async function signedOutPath() {
  const { registration_open: registrationOpen } = await getBasicInfo();
  return registrationOpen ? "/register" : "/login";
}
