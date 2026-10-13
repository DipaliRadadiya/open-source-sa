// Written once by the panel; yours to edit. The recipe's start command runs it on every start.
// TREK keeps its sign-up switches only in its database and leaves them on, so anyone who finds
// the site could create an account. This turns public sign-up off ONCE, on a fresh database:
// INSERT OR IGNORE never touches a value already there, so a choice made later in
// Admin > Settings (the same two keys) survives restarts. Exit 1 means "not yet" (the server has
// not created the database or its tables); the start command retries every 5 seconds, for up to
// 10 minutes. Delete this file to skip the step.
const Database = require(require.resolve('better-sqlite3', { paths: ['/app/server'] }));

try {
    const db = new Database('/app/data/travel.db', { fileMustExist: true, timeout: 5000 });
    const table = db.prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'app_settings'").get();

    if (!table) {
        process.exit(1);
    }

    const insert = db.prepare('INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?)');
    let changed = 0;

    for (const key of ['password_registration', 'oidc_registration']) {
        changed += insert.run(key, 'false').changes;
    }

    db.close();

    if (changed > 0) {
        console.log('[recipe] Public sign-up switched off; Admin > Settings turns it back on.');
    }
} catch (error) {
    process.exit(1);
}
