import { api } from "@/lib/api/client";

// A domain hostname goes in the URL path; encode it so a value with dots (or an
// unexpected character the backend will still 422) can't break the request.
const seg = (domain) => encodeURIComponent(domain);

// ---- Domains ----------------------------------------------------------------

export async function addDomain(appId, body) {
  const res = await api.post(`/applications/${appId}/domains`, body);
  return res.data?.domain;
}

// Re-checks DNS for one name. Returns the refreshed domain.
export async function verifyDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/verify`);
  return res.data?.domain;
}

// Promotes a name to canonical; the old primary stays attached as an alias.
export async function makePrimaryDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/primary`);
  return res.data?.domains;
}

/*
 * Changes what an attached name DOES — `type`, `redirect_to`,
 * `redirect_status`. Not what it is called.
 *
 * The name is deliberately not editable server-side: a rename leaves the old
 * name in the certificate's lineage, and certbot re-validates every name in a
 * lineage and fails the WHOLE renewal when one cannot be validated. So it
 * would silently stop the certificate covering the site's remaining, perfectly
 * good names from renewing, and the first anyone hears of it is a browser
 * warning up to ninety days later. Renaming stays delete + add, which is
 * visibly two decisions.
 *
 * The primary is refused with a 422 — it names the vhost file and both log
 * files, so changing it is what the `primary` endpoint above is for.
 */
export async function updateDomain(appId, domain, body) {
  const res = await api.put(`/applications/${appId}/domains/${seg(domain)}`, body);
  return res.data?.domain;
}

export async function deleteDomain(appId, domain) {
  await api.delete(`/applications/${appId}/domains/${seg(domain)}`);
}

// ---- Certificate ------------------------------------------------------------

// letsencrypt/self_signed → 202 pending (poll GET); custom → 201 active.
export async function issueCertificate(appId, body) {
  const res = await api.post(`/applications/${appId}/certificate`, body);
  return res.data?.certificate;
}

// Rehearse the issuance: the panel's reachability check, then a real
// `certbot --dry-run` against Let's Encrypt's staging server. Queued and 202
// for the same reason issuing is — the second half is a round trip to the CA.
// Nothing is stored and no certificate is created either way.
export async function startCertificateDryRun(appId) {
  const res = await api.post(`/applications/${appId}/certificate/dry-run`);
  return res.data?.dry_run ?? null;
}

// Poll target while a dry run is running. `null` when this site has never had
// one — not an error, just a question nobody has asked yet.
export async function fetchCertificateDryRun(appId) {
  const res = await api.get(`/applications/${appId}/certificate/dry-run`);
  return res.data?.dry_run ?? null;
}

// Poll target while a certificate is pending/issuing.
export async function fetchCertificate(appId) {
  const res = await api.get(`/applications/${appId}/certificate`);
  return res.data?.certificate ?? null;
}

// Refused (422) unless a certificate is active — redirecting to HTTPS with
// nothing on 443 takes the site offline.
export async function setForceHttps(appId, forceHttps) {
  const res = await api.put(`/applications/${appId}/certificate/force-https`, {
    force_https: forceHttps,
  });
  return res.data?.certificate;
}

export async function deleteCertificate(appId) {
  await api.delete(`/applications/${appId}/certificate`);
}
