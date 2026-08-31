// Liveness for THIS server, which is a different systemd unit from the API.
// The restart curtain needs to know the panel can serve a page again before it
// reloads into one — the API answering only means Laravel is back.
//
// A route handler rather than probing a page: this runs every few seconds
// during a restart, and re-rendering /login each time is real work for an
// answer that is only ever "yes".
export const dynamic = "force-dynamic";

export function GET() {
  return new Response(null, { status: 204 });
}
