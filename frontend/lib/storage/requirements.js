/**
 * Which of endpoint and region a destination actually needs.
 *
 * Neither is required by the API — it has no provider concept and accepts a
 * blank endpoint from anyone. But a Wasabi or R2 bucket cannot work without
 * one: with the endpoint empty the client talks to AWS. The panel knows which
 * provider was picked, so it is the only layer that can say so.
 *
 * AWS is the exception in both directions: its bucket host carries the region,
 * so the endpoint stays blank and the region is the part that must be given.
 */
export function createRequirements(provider) {
  const isAws = provider === "aws";
  return { endpoint: !isAws, region: isAws };
}

/**
 * Editing is judged against what the destination already has, not against a
 * provider — the edit form has no provider field, and inferring one would let a
 * rename start demanding a region that was never needed. So this only prevents
 * an edit from REMOVING something the destination is already relying on.
 */
export function editRequirements(destination) {
  return {
    endpoint: Boolean(destination?.endpoint),
    region: Boolean(destination?.region),
  };
}
