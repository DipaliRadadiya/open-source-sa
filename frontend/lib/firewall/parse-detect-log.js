const LINE =
  /^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"]*?) (\S+)" (\d{3}) (\S+) "([^"]*)" "([^"]*)"/;

const MONTHS = {
  Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5,
  Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11,
};

function parseStamp(value) {
  const m = /^(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-]\d{4})$/.exec(value ?? "");
  if (!m || !(m[2] in MONTHS)) return null;
  const [, dd, mon, yyyy, hh, min, ss, tz] = m;
  const iso = `${yyyy}-${String(MONTHS[mon] + 1).padStart(2, "0")}-${dd}T${hh}:${min}:${ss}${tz.slice(0, 3)}:${tz.slice(3)}`;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

export function parseDetectLog(lines) {
  const list = Array.isArray(lines) ? lines : String(lines ?? "").split("\n");
  const out = [];
  for (const raw of list) {
    const m = LINE.exec(String(raw).trim());
    if (!m) continue;
    out.push({
      ip: m[1],
      at: parseStamp(m[2]),
      method: m[3],
      target: m[4],
      status: Number(m[6]),
      userAgent: m[9] === "-" ? "" : m[9],
    });
  }
  return out.reverse();
}
