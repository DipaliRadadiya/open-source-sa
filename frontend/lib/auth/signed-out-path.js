import { getBasicInfo } from "@/lib/basic-info/get-basic-info";

// Where to send an unauthenticated visitor. A fresh panel with no admin yet
// (registration_open) goes to /register so the first account can be created;
// once a user exists the backend closes registration and it's the normal
// /login. Fail-safe: basic-info defaults to registration_open:false → /login.
export async function signedOutPath() {
  const { registration_open: registrationOpen } = await getBasicInfo();
  return registrationOpen ? "/register" : "/login";
}
