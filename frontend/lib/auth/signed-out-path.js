import { getBasicInfo } from "@/lib/basic-info/get-basic-info";

// Where to send an unauthenticated visitor. A fresh panel with no admin yet
// (registration_open) goes to /register so the first account can be created;
// once a user exists the backend closes registration and it's the normal
// /login. Fail-safe: basic-info defaults to registration_open:false → /login.
//
// It does NOT carry the screen the reader was on, and cannot from here: a
// server component in this app sees only `host`, `user-agent`, `accept` and
// the `x-forwarded-*` set — Next sets no path header, and there is no proxy to
// add one (`proxy.js` was removed when locale routing went cookie-based).
// Returning someone to where their session expired needs either middleware or
// a client-side recorder; see `safe-next.js`, which is the validation half and
// is already written.
export async function signedOutPath() {
  const { registration_open: registrationOpen } = await getBasicInfo();
  return registrationOpen ? "/register" : "/login";
}
