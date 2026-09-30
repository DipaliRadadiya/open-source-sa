import { apiMessage } from "../api/error-message.js";

/**
 * A log read the server refused, with the server's own reason (and reference)
 * when it gave one. Dropping it left the page saying "the server did not
 * answer" about a server that had answered and said why.
 */
export async function failedRead(res) {
  let data = null;
  try {
    data = await res.json();
  } catch {
    // Not JSON: nothing to quote, the generic sentence stands.
  }
  return { status: "failed", log: null, message: apiMessage({ response: { data } }, null) || null };
}
