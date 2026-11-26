// Shared by the applications and databases backup tables so the two line up, and by the
// overview's loading skeleton. A plain module so a server component can read it. The fixed
// columns grow on wide cards; otherwise the name column takes all the spare room.
export const COVERAGE_WIDTHS = {
  kind: "w-28 @min-[1050px]:w-32 @min-[1180px]:w-36",
  schedule: "w-32 @min-[1050px]:w-36 @min-[1180px]:w-40",
  storage: "w-32 @min-[1050px]:w-40 @min-[1180px]:w-44",
  runs: "w-40 @min-[1050px]:w-48 @min-[1180px]:w-52",
  actions: "w-44",
};
