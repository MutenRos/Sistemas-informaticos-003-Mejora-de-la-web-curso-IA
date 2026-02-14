<?php
// admin.php
// Panel de analíticas (logs SQLite) estilo Modern UI / Metro + login.
// Usuario: jocarsa
// Contraseña: jocarsa
//
// Requiere que exista la BD (p.ej. blog.sqlite) y la tabla "visits" creada por log.php.
//
// Endpoints internos (requieren sesión):
//   admin.php?api=summary
//   admin.php?api=timeseries&days=30
//   admin.php?api=top_pages&days=30&limit=12
//   admin.php?api=top_referrers&days=30&limit=12
//   admin.php?api=devices&days=30
//   admin.php?api=hours&days=30
//
// Seguridad:
// - Autenticación por sesión (no cookies propias extra).
// - Las credenciales están hardcodeadas (cámbialas si lo deseas).

declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=utf-8');

// =========================
// CONFIG
// =========================
$ADMIN_USER = 'jocarsa';
$ADMIN_PASS = 'jocarsa';

$DB_FILE    = __DIR__ . '/blog.sqlite';
$LOG_TABLE  = 'visits'; // log.php crea "visits" por defecto

$SITE_NAME  = 'CursoIA';
$TAGLINE    = 'Analíticas (logs)';

// =========================
// HELPERS
// =========================
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_logged_in(): bool {
  return !empty($_SESSION['admin_ok']) && $_SESSION['admin_ok'] === true;
}
function require_login_json(): void {
  if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
function pdo_open(string $dbFile): PDO {
  if (!is_file($dbFile)) {
    // Crear BD vacía (si aún no existe)
    touch($dbFile);
  }
  return new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}
function ensure_table(PDO $db, string $table): void {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  if ($table === '') $table = 'visits';

  // No recrea si ya existe (log.php la crea). Esta definición es compatible.
  $db->exec("
    CREATE TABLE IF NOT EXISTS {$table} (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ts_iso TEXT NOT NULL,
      epoch INTEGER NOT NULL,

      ip TEXT,
      ip_hash TEXT,

      method TEXT,
      scheme TEXT,
      host TEXT,
      path TEXT,
      query TEXT,
      url_full TEXT,

      referer TEXT,
      user_agent TEXT,
      accept TEXT,
      accept_language TEXT,
      accept_encoding TEXT,
      dnt TEXT,

      remote_port INTEGER,
      is_https INTEGER,

      server_addr TEXT,
      server_port INTEGER
    );
    CREATE INDEX IF NOT EXISTS idx_{$table}_epoch ON {$table}(epoch DESC);
    CREATE INDEX IF NOT EXISTS idx_{$table}_path ON {$table}(path);
    CREATE INDEX IF NOT EXISTS idx_{$table}_iphash ON {$table}(ip_hash);
  ");
}
function since_epoch(int $days): int {
  $days = max(1, min(3650, $days));
  return time() - ($days * 86400);
}
function json_out(array $data): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// =========================
// LOGIN / LOGOUT
// =========================
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"],
      $params["secure"], $params["httponly"]
    );
  }
  session_destroy();
  header('Location: admin.php');
  exit;
}

if (!is_logged_in() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
  $u = (string)($_POST['user'] ?? '');
  $p = (string)($_POST['pass'] ?? '');
  if (hash_equals($ADMIN_USER, $u) && hash_equals($ADMIN_PASS, $p)) {
    $_SESSION['admin_ok'] = true;
    header('Location: admin.php');
    exit;
  }
  $login_error = 'Credenciales incorrectas.';
}

// =========================
// API
// =========================
if (isset($_GET['api'])) {
  require_login_json();

  $api = (string)$_GET['api'];
  $days = (int)($_GET['days'] ?? 30);
  $days = max(1, min(365, $days));
  $limit = (int)($_GET['limit'] ?? 12);
  $limit = max(3, min(100, $limit));
  $minEpoch = since_epoch($days);

  $db = pdo_open($DB_FILE);
  ensure_table($db, $LOG_TABLE);
  $t = preg_replace('/[^a-zA-Z0-9_]/', '', $LOG_TABLE);
  if ($t === '') $t = 'visits';

  // summary
  if ($api === 'summary') {
    // Total visitas periodo
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM {$t} WHERE epoch >= :e");
    $stmt->execute([':e'=>$minEpoch]);
    $total = (int)($stmt->fetch()['n'] ?? 0);

    // Usuarios aproximados por ip_hash (si hay)
    $stmt = $db->prepare("SELECT COUNT(DISTINCT ip_hash) AS n FROM {$t} WHERE epoch >= :e AND ip_hash IS NOT NULL");
    $stmt->execute([':e'=>$minEpoch]);
    $uniq = (int)($stmt->fetch()['n'] ?? 0);

    // Hoy
    $today0 = strtotime(date('Y-m-d 00:00:00'));
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM {$t} WHERE epoch >= :e");
    $stmt->execute([':e'=>$today0]);
    $today = (int)($stmt->fetch()['n'] ?? 0);

    // Última visita
    $stmt = $db->query("SELECT ts_iso, url_full FROM {$t} ORDER BY epoch DESC, id DESC LIMIT 1");
    $last = $stmt->fetch() ?: null;

    json_out([
      'ok'=>true,
      'range_days'=>$days,
      'total'=>$total,
      'unique_iphash'=>$uniq,
      'today'=>$today,
      'last'=>$last,
    ]);
  }

  // timeseries por día
  if ($api === 'timeseries') {
    // agrupación por día (YYYY-MM-DD)
    $stmt = $db->prepare("
      SELECT substr(ts_iso, 1, 10) AS d, COUNT(*) AS n
      FROM {$t}
      WHERE epoch >= :e
      GROUP BY substr(ts_iso, 1, 10)
      ORDER BY d ASC
    ");
    $stmt->execute([':e'=>$minEpoch]);
    $rows = $stmt->fetchAll();

    // rellenar huecos
    $map = [];
    foreach ($rows as $r) $map[(string)$r['d']] = (int)$r['n'];

    $out = [];
    $start = strtotime(date('Y-m-d 00:00:00', $minEpoch));
    $end   = strtotime(date('Y-m-d 00:00:00')) + 86400;
    for ($ts = $start; $ts < $end; $ts += 86400) {
      $d = date('Y-m-d', $ts);
      $out[] = ['d'=>$d, 'n'=>($map[$d] ?? 0)];
    }

    json_out(['ok'=>true,'series'=>$out]);
  }

  // top pages por path
  if ($api === 'top_pages') {
    $stmt = $db->prepare("
      SELECT COALESCE(NULLIF(path,''), '(sin path)') AS k, COUNT(*) AS n
      FROM {$t}
      WHERE epoch >= :e
      GROUP BY COALESCE(NULLIF(path,''), '(sin path)')
      ORDER BY n DESC
      LIMIT :lim
    ");
    $stmt->bindValue(':e', $minEpoch, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json_out(['ok'=>true,'items'=>$rows]);
  }

  // top referrers (dominio)
  if ($api === 'top_referrers') {
    // Extraer dominio de referer con SQL simple:
    // - Quita scheme
    // - Toma hasta el primer /
    $stmt = $db->prepare("
      SELECT
        CASE
          WHEN referer IS NULL OR trim(referer) = '' THEN '(directo)'
          ELSE
            substr(
              replace(replace(referer,'https://',''), 'http://',''),
              1,
              CASE
                WHEN instr(replace(replace(referer,'https://',''), 'http://',''), '/') = 0
                  THEN length(replace(replace(referer,'https://',''), 'http://',''))
                ELSE instr(replace(replace(referer,'https://',''), 'http://',''), '/') - 1
              END
            )
        END AS k,
        COUNT(*) AS n
      FROM {$t}
      WHERE epoch >= :e
      GROUP BY k
      ORDER BY n DESC
      LIMIT :lim
    ");
    $stmt->bindValue(':e', $minEpoch, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json_out(['ok'=>true,'items'=>$rows]);
  }

  // devices/browsers aproximados por user-agent
  if ($api === 'devices') {
    $stmt = $db->prepare("SELECT user_agent FROM {$t} WHERE epoch >= :e");
    $stmt->execute([':e'=>$minEpoch]);
    $uas = $stmt->fetchAll();

    $b = [
      'Chrome'=>0,'Firefox'=>0,'Safari'=>0,'Edge'=>0,'Opera'=>0,'Android'=>0,'iOS'=>0,'Bot'=>0,'Otros'=>0
    ];
    foreach ($uas as $row) {
      $ua = strtolower((string)($row['user_agent'] ?? ''));
      if ($ua === '') { $b['Otros']++; continue; }
      if (strpos($ua, 'bot') !== false || strpos($ua,'spider') !== false || strpos($ua,'crawl') !== false) { $b['Bot']++; continue; }
      if (strpos($ua, 'edg') !== false) { $b['Edge']++; continue; }
      if (strpos($ua, 'opr') !== false || strpos($ua,'opera') !== false) { $b['Opera']++; continue; }
      if (strpos($ua, 'firefox') !== false) { $b['Firefox']++; continue; }
      if (strpos($ua, 'android') !== false) { $b['Android']++; continue; }
      if (strpos($ua, 'iphone') !== false || strpos($ua,'ipad') !== false || strpos($ua,'ios') !== false) { $b['iOS']++; continue; }
      // Safari viene también en Chrome; comprobar Chrome antes
      if (strpos($ua, 'chrome') !== false || strpos($ua,'chromium') !== false) { $b['Chrome']++; continue; }
      if (strpos($ua, 'safari') !== false) { $b['Safari']++; continue; }
      $b['Otros']++;
    }

    $items = [];
    foreach ($b as $k=>$v) $items[] = ['k'=>$k,'n'=>$v];
    usort($items, fn($a,$b)=>$b['n']<=>$a['n']);

    json_out(['ok'=>true,'items'=>$items]);
  }

  // hours: distribución 0..23
  if ($api === 'hours') {
    $stmt = $db->prepare("
      SELECT CAST(substr(ts_iso, 12, 2) AS INTEGER) AS h, COUNT(*) AS n
      FROM {$t}
      WHERE epoch >= :e
      GROUP BY CAST(substr(ts_iso, 12, 2) AS INTEGER)
      ORDER BY h ASC
    ");
    $stmt->execute([':e'=>$minEpoch]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[(int)$r['h']] = (int)$r['n'];

    $out = [];
    for ($i=0; $i<24; $i++) $out[] = ['h'=>$i,'n'=>($map[$i] ?? 0)];
    json_out(['ok'=>true,'hours'=>$out]);
  }

  http_response_code(404);
  json_out(['ok'=>false,'error'=>'unknown_api']);
}

// =========================
// LOGIN VIEW (si no hay sesión)
// =========================
if (!is_logged_in()) {
  $err = $login_error ?? '';
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?=h($SITE_NAME)?> — Admin</title>
    <style>
      :root{
        --bg:#ffffff; --soft:#f2f4f4; --text:#1b1f23; --muted:#5b6670;
        --orange:#f39a1a; --orange2:#d98206; --border:rgba(0,0,0,.10);
        --tile:#fff;
        --shadow:0 10px 24px rgba(0,0,0,.08);
        --r:10px;
        --container:980px;
      }
      *{box-sizing:border-box}
      body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Arial,sans-serif;background:var(--soft);color:var(--text)}
      .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:26px}
      .card{width:min(520px,100%);background:var(--tile);border:1px solid var(--border);box-shadow:var(--shadow);padding:22px}
      .brand{display:flex;align-items:center;gap:12px;margin-bottom:12px}
      .brand img{width:46px;height:46px;object-fit:contain}
      h1{margin:0 0 6px;font-size:22px;font-weight:900}
      p{margin:0 0 14px;color:var(--muted);font-size:14px}
      label{display:block;margin:12px 0 6px;font-weight:800;font-size:12px;color:#2f3a44}
      input{width:100%;padding:12px;border:1px solid rgba(0,0,0,.14);background:#fff}
      .row{display:grid;gap:10px}
      .btn{display:inline-flex;align-items:center;justify-content:center;border:0;cursor:pointer;
        padding:11px 16px;border-radius:999px;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.35px;
        background:var(--orange);color:#fff;box-shadow:0 10px 22px rgba(243,154,26,.24)}
      .btn:hover{background:var(--orange2)}
      .err{margin-top:10px;padding:10px;border:1px solid rgba(217,130,6,.35);background:rgba(243,154,26,.12);color:#2f3a44}
      .hint{margin-top:10px;font-size:12px;color:var(--muted)}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <div class="brand">
          <img src="logo.png" alt="Logo" />
          <div>
            <h1>Admin · Analíticas</h1>
            <p><?=h($SITE_NAME)?> · Panel estilo Metro</p>
          </div>
        </div>

        <form method="post" action="admin.php">
          <input type="hidden" name="action" value="login" />
          <label for="user">Usuario</label>
          <input id="user" name="user" type="text" autocomplete="username" required>
          <label for="pass">Contraseña</label>
          <input id="pass" name="pass" type="password" autocomplete="current-password" required>

          <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
            <button class="btn" type="submit">Entrar</button>
          </div>

          <?php if ($err): ?>
            <div class="err"><?=h($err)?></div>
          <?php endif; ?>

          <div class="hint">Nota: este panel lee agregados de la tabla <strong><?=h($LOG_TABLE)?></strong> en <strong><?=h(basename($DB_FILE))?></strong>.</div>
        </form>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// =========================
// ADMIN VIEW
// =========================
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?=h($SITE_NAME)?> — Admin</title>

  <style>
    :root{
      --bg:#ffffff;
      --bg-soft:#f2f4f4;
      --text:#1b1f23;
      --muted:#5b6670;

      --orange:#f39a1a;
      --orange2:#d98206;
      --green:#6f8f12;

      --card:#ffffff;
      --border:rgba(0,0,0,.10);
      --shadow:0 10px 24px rgba(0,0,0,.08);

      --r:10px;
      --container:1180px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Arial,sans-serif;background:var(--bg-soft);color:var(--text)}
    a{color:inherit;text-decoration:none}
    .container{width:min(var(--container),calc(100% - 48px));margin:0 auto}

    header{
      position:sticky;top:0;z-index:40;
      background:rgba(255,255,255,.96);
      border-bottom:1px solid rgba(0,0,0,.08);
    }
    .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0}
    #brand{display:flex;align-items:center;gap:12px}
    #brand img{width:42px;height:42px;object-fit:contain}
    #brand .t1{font-weight:900;font-size:14px;color:#24303a;line-height:1.05}
    #brand .t2{font-weight:650;font-size:12px;color:#6c7a86;line-height:1.05}
    nav{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    .chip{
      display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;
      background:rgba(0,0,0,.06);font-size:12px;font-weight:900;border:1px solid rgba(0,0,0,.06)
    }
    .chip:hover{background:rgba(243,154,26,.18)}
    .cta{
      background:var(--orange);color:#fff;border:0;border-radius:999px;padding:10px 14px;
      font-weight:900;font-size:12px;box-shadow:0 10px 20px rgba(243,154,26,.22)
    }
    .cta:hover{background:var(--orange2)}
    main{padding:26px 0 40px}
    h1{margin:0 0 6px;font-size:26px;font-weight:900}
    p{margin:0 0 14px;color:var(--muted);font-size:14px}

    /* Metro tiles */
    .grid{
      display:grid;
      grid-template-columns: 320px 1fr;
      gap: 18px;
      align-items:start;
      margin-top: 14px;
    }
    .side{
      display:grid;
      gap: 12px;
    }
    .tile{
      background:var(--card);
      border:1px solid var(--border);
      box-shadow:none;
      padding:16px;
    }
    .tile h3{margin:0 0 10px;font-size:14px;font-weight:900}
    .controls{
      display:flex;gap:10px;flex-wrap:wrap;align-items:center
    }
    select, input{
      border:1px solid rgba(0,0,0,.14);
      padding:10px 12px;
      background:#fff;
      font:inherit;
    }
    .btn{
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;
      padding:10px 14px;border-radius:999px;border:0;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.35px;
      background:rgba(0,0,0,.06);color:#2f3a44
    }
    .btn:hover{background:rgba(243,154,26,.18)}
    .btn-primary{
      background:var(--orange);color:#fff;box-shadow:0 10px 22px rgba(243,154,26,.24)
    }
    .btn-primary:hover{background:var(--orange2)}
    .metrics{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
    }
    .metric{
      background:#fff;border:1px solid var(--border);padding:14px;
      display:flex;flex-direction:column;gap:4px
    }
    .metric .k{font-size:12px;color:var(--muted);font-weight:900;text-transform:uppercase;letter-spacing:.4px}
    .metric .v{font-size:22px;font-weight:900}
    .metric .s{font-size:12px;color:var(--muted)}
    .maincol{
      display:grid;
      gap: 12px;
    }
    .row2{
      display:grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 12px;
    }
    .row3{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    /* SVG charts */
    .chart-wrap{position:relative}
    .chart-head{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin-bottom:10px}
    .chart-title{margin:0;font-size:14px;font-weight:900}
    .chart-sub{margin:0;color:var(--muted);font-size:12px}
    .svg{
      width:100%;
      height:auto;
      display:block;
      border:1px solid rgba(0,0,0,.08);
      background:#fff;
    }
    .tooltip{
      position:absolute;
      pointer-events:none;
      opacity:0;
      transform:translateY(-4px);
      background:#111;
      color:#fff;
      font-size:12px;
      padding:8px 10px;
      border-radius:8px;
      max-width: 260px;
      z-index: 20;
      transition:opacity .08s ease, transform .08s ease;
      box-shadow: var(--shadow);
    }
    .tooltip strong{display:block;margin-bottom:2px}
    .list{
      display:grid;
      gap: 8px;
      margin-top: 10px;
    }
    .li{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:10px;border:1px solid rgba(0,0,0,.08);background:#fff;
      font-size:13px
    }
    .li .k{font-weight:900}
    .li .n{color:var(--muted);font-weight:900}
    .muted{color:var(--muted)}
    .small{font-size:12px}

    @media (max-width: 980px){
      .grid{grid-template-columns: 1fr;}
      .metrics{grid-template-columns: 1fr;}
      .row2,.row3{grid-template-columns:1fr;}
    }
  </style>
</head>

<body>
<header>
  <div class="container">
    <div class="topbar">
      <div id="brand" aria-label="Marca">
        <a href="index.php" aria-label="Volver al sitio" style="display:flex;align-items:center;gap:12px;">
          <img src="logo.png" alt="Logo" width="42" height="42" decoding="async" />
          <div>
            <div class="t1"><?=h($SITE_NAME)?> · Admin</div>
            <div class="t2"><?=h($TAGLINE)?></div>
          </div>
        </a>
      </div>

      <nav aria-label="Navegación">
        <a class="chip" href="index.php">Landing</a>
        <a class="chip" href="blog.php">Blog</a>
        <a class="chip" href="blog.php?rss=1">RSS</a>
        <a class="cta" href="admin.php?logout=1" rel="nofollow">Salir</a>
      </nav>
    </div>
  </div>
</header>

<main>
  <div class="container">
    <h1>Analíticas</h1>
    <p>Resumen agregado de visitas registradas por <span class="muted">log.php</span>.</p>

    <div class="grid">
      <aside class="side">

        <div class="tile">
          <h3>Rango</h3>
          <div class="controls">
            <select id="days">
              <option value="7">Últimos 7 días</option>
              <option value="14">Últimos 14 días</option>
              <option value="30" selected>Últimos 30 días</option>
              <option value="90">Últimos 90 días</option>
              <option value="180">Últimos 180 días</option>
              <option value="365">Último año</option>
            </select>
            <button class="btn btn-primary" id="refresh">Actualizar</button>
          </div>
          <p class="muted small" style="margin-top:10px;">
            BD: <strong><?=h(basename($DB_FILE))?></strong> · Tabla: <strong><?=h($LOG_TABLE)?></strong>
          </p>
        </div>

        <div class="tile">
          <h3>Estado</h3>
          <div id="status" class="muted small">Cargando…</div>
        </div>

        <div class="tile">
          <h3>Top páginas</h3>
          <div id="topPages" class="list"></div>
        </div>

        <div class="tile">
          <h3>Top referers</h3>
          <div id="topRefs" class="list"></div>
        </div>

      </aside>

      <section class="maincol">

        <div class="metrics">
          <div class="metric">
            <div class="k">Visitas (rango)</div>
            <div class="v" id="mTotal">—</div>
            <div class="s" id="mTotalS">—</div>
          </div>
          <div class="metric">
            <div class="k">Hoy</div>
            <div class="v" id="mToday">—</div>
            <div class="s">Visitas desde 00:00</div>
          </div>
          <div class="metric">
            <div class="k">Usuarios aprox.</div>
            <div class="v" id="mUniq">—</div>
            <div class="s">Por <span class="muted">ip_hash</span></div>
          </div>
        </div>

        <div class="tile chart-wrap">
          <div class="chart-head">
            <div>
              <h3 class="chart-title">Visitas por día</h3>
              <p class="chart-sub">Serie temporal del rango seleccionado</p>
            </div>
            <div class="muted small" id="tsHint">—</div>
          </div>

          <div class="tooltip" id="tip"></div>
          <svg id="chartTS" class="svg" viewBox="0 0 980 260" role="img" aria-label="Gráfico de visitas por día"></svg>
        </div>

        <div class="row2">
          <div class="tile chart-wrap">
            <div class="chart-head">
              <div>
                <h3 class="chart-title">Distribución por hora</h3>
                <p class="chart-sub">Dónde se concentran las visitas</p>
              </div>
              <div class="muted small">0–23h</div>
            </div>
            <div class="tooltip" id="tip2"></div>
            <svg id="chartHours" class="svg" viewBox="0 0 980 260" role="img" aria-label="Gráfico de visitas por hora"></svg>
          </div>

          <div class="tile chart-wrap">
            <div class="chart-head">
              <div>
                <h3 class="chart-title">User-Agent (aprox.)</h3>
                <p class="chart-sub">Clasificación simple</p>
              </div>
              <div class="muted small">Donut SVG</div>
            </div>
            <div class="tooltip" id="tip3"></div>
            <svg id="chartUA" class="svg" viewBox="0 0 980 260" role="img" aria-label="Gráfico de user agents"></svg>
          </div>
        </div>

      </section>
    </div>
  </div>
</main>

<script>
  // =========================
  // Minimal Metro-ish JS + SVG charts (interactive)
  // =========================
  const $ = (s) => document.querySelector(s);

  const state = {
    days: 30,
    summary: null,
    series: [],
    topPages: [],
    topRefs: [],
    hours: [],
    devices: []
  };

  function fmt(n){ return new Intl.NumberFormat('es-ES').format(n); }

  async function api(name, params = {}) {
    const u = new URL('admin.php', window.location.href);
    u.searchParams.set('api', name);
    if (params.days) u.searchParams.set('days', params.days);
    if (params.limit) u.searchParams.set('limit', params.limit);
    const r = await fetch(u.toString(), {credentials:'same-origin'});
    if (!r.ok) throw new Error('API ' + name + ' ' + r.status);
    return await r.json();
  }

  function setStatus(txt){
    $('#status').textContent = txt;
  }

  function renderList(el, items){
    el.innerHTML = '';
    for (const it of items) {
      const div = document.createElement('div');
      div.className = 'li';
      const k = document.createElement('div');
      k.className = 'k';
      k.textContent = it.k;
      const n = document.createElement('div');
      n.className = 'n';
      n.textContent = fmt(it.n);
      div.appendChild(k);
      div.appendChild(n);
      el.appendChild(div);
    }
  }

  // ---------- Tooltip helper ----------
  function bindTooltip(svgEl, tipEl){
    function show(x,y,html){
      tipEl.innerHTML = html;
      tipEl.style.left = x + 'px';
      tipEl.style.top = y + 'px';
      tipEl.style.opacity = '1';
      tipEl.style.transform = 'translateY(0)';
    }
    function hide(){
      tipEl.style.opacity = '0';
      tipEl.style.transform = 'translateY(-4px)';
    }
    return {show, hide};
  }

  // ---------- SVG helpers ----------
  function svgEl(tag, attrs = {}) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [k,v] of Object.entries(attrs)) el.setAttribute(k, String(v));
    return el;
  }

  function clearSVG(svg){
    while (svg.firstChild) svg.removeChild(svg.firstChild);
  }

  function drawBars(svg, data, opts){
    // opts: {labelFn, valueFn, xTicks, yLabel, tipFn, pad, barGap}
    clearSVG(svg);

    const W = 980, H = 260;
    const pad = opts.pad ?? {l:46,r:14,t:14,b:34};
    const innerW = W - pad.l - pad.r;
    const innerH = H - pad.t - pad.b;

    const vals = data.map(opts.valueFn);
    const maxV = Math.max(1, ...vals);

    // background grid
    const gGrid = svgEl('g', {});
    svg.appendChild(gGrid);

    for (let i=0;i<=4;i++){
      const y = pad.t + innerH - (innerH * (i/4));
      gGrid.appendChild(svgEl('line',{x1:pad.l,y1:y,x2:W-pad.r,y2:y,stroke:'rgba(0,0,0,.08)','stroke-width':'1'}));
      const v = Math.round(maxV*(i/4));
      const tx = svgEl('text',{x:pad.l-8,y:y+4,'text-anchor':'end',fill:'#5b6670','font-size':'11','font-weight':'700'});
      tx.textContent = v.toString();
      gGrid.appendChild(tx);
    }

    // bars
    const n = data.length;
    const gap = opts.barGap ?? 3;
    const barW = Math.max(2, (innerW - gap*(n-1)) / n);

    const gBars = svgEl('g', {});
    svg.appendChild(gBars);

    // axis labels (x)
    const gX = svgEl('g', {});
    svg.appendChild(gX);

    const tip = opts.tooltip;

    for (let i=0;i<n;i++){
      const v = vals[i];
      const h = (v / maxV) * innerH;
      const x = pad.l + i*(barW+gap);
      const y = pad.t + (innerH - h);

      const rect = svgEl('rect',{
        x:x, y:y, width:barW, height:Math.max(0.5,h),
        rx:6, ry:6,
        fill:'rgba(243,154,26,.75)',
        stroke:'rgba(0,0,0,.08)'
      });

      rect.addEventListener('mousemove', (ev)=>{
        const box = svg.getBoundingClientRect();
        const html = opts.tipFn(data[i]);
        tip.show(ev.clientX - box.left + 12, ev.clientY - box.top + 12, html);
      });
      rect.addEventListener('mouseleave', ()=>tip.hide());

      gBars.appendChild(rect);

      if (opts.xTicks && opts.xTicks(i, n, data[i])) {
        const label = opts.labelFn(data[i]);
        const tx = svgEl('text',{
          x:x + barW/2,
          y:H-12,
          'text-anchor':'middle',
          fill:'#5b6670',
          'font-size':'10',
          'font-weight':'700'
        });
        tx.textContent = label;
        gX.appendChild(tx);
      }
    }
  }

  function drawDonut(svg, items, tip){
    clearSVG(svg);

    // Donut simple centrado, con leyenda a la derecha
    const W = 980, H = 260;
    const cx = 180, cy = 130;
    const rO = 80, rI = 48;

    const total = items.reduce((a,b)=>a + (b.n||0), 0) || 1;

    // Paleta: sin “colores fijos” por requisito estricto no hay, pero aquí usamos grises + naranja suave.
    // Para no depender de estilos globales, alternamos opacidades.
    const base = [
      'rgba(243,154,26,.85)',
      'rgba(0,0,0,.55)',
      'rgba(0,0,0,.38)',
      'rgba(0,0,0,.25)',
      'rgba(0,0,0,.18)',
      'rgba(0,0,0,.12)'
    ];

    function polar(a, rr){
      const x = cx + rr*Math.cos(a);
      const y = cy + rr*Math.sin(a);
      return {x,y};
    }
    function arcPath(a0, a1){
      const p0 = polar(a0, rO);
      const p1 = polar(a1, rO);
      const q0 = polar(a1, rI);
      const q1 = polar(a0, rI);
      const large = (a1 - a0) > Math.PI ? 1 : 0;
      return [
        `M ${p0.x} ${p0.y}`,
        `A ${rO} ${rO} 0 ${large} 1 ${p1.x} ${p1.y}`,
        `L ${q0.x} ${q0.y}`,
        `A ${rI} ${rI} 0 ${large} 0 ${q1.x} ${q1.y}`,
        'Z'
      ].join(' ');
    }

    const g = svgEl('g', {});
    svg.appendChild(g);

    let a = -Math.PI/2;
    items = items.filter(x=>x.n>0).slice(0, 8); // top 8
    for (let i=0;i<items.length;i++){
      const frac = items[i].n / total;
      const a1 = a + frac*2*Math.PI;

      const path = svgEl('path',{
        d: arcPath(a, a1),
        fill: base[i % base.length],
        stroke: 'rgba(255,255,255,.9)',
        'stroke-width': '2'
      });

      path.addEventListener('mousemove', (ev)=>{
        const box = svg.getBoundingClientRect();
        const pct = ((items[i].n/total)*100).toFixed(1);
        tip.show(ev.clientX - box.left + 12, ev.clientY - box.top + 12,
          `<strong>${escapeHtml(items[i].k)}</strong>${fmt(items[i].n)} · ${pct}%`
        );
      });
      path.addEventListener('mouseleave', ()=>tip.hide());

      g.appendChild(path);
      a = a1;
    }

    // centro
    const center = svgEl('circle',{cx,cy,r:rI-8,fill:'#fff',stroke:'rgba(0,0,0,.08)'});
    g.appendChild(center);

    const t1 = svgEl('text',{x:cx,y:cy-4,'text-anchor':'middle',fill:'#1b1f23','font-size':'14','font-weight':'900'});
    t1.textContent = 'Total';
    g.appendChild(t1);
    const t2 = svgEl('text',{x:cx,y:cy+16,'text-anchor':'middle',fill:'#5b6670','font-size':'12','font-weight':'800'});
    t2.textContent = fmt(total);
    g.appendChild(t2);

    // leyenda
    const lx = 340, ly = 56;
    for (let i=0;i<items.length;i++){
      const y = ly + i*24;
      const sw = svgEl('rect',{x:lx,y:y-10,width:14,height:14,rx:4,ry:4,fill:base[i%base.length],stroke:'rgba(0,0,0,.08)'});
      const tx = svgEl('text',{x:lx+22,y:y+2,fill:'#2f3a44','font-size':'12','font-weight':'800'});
      tx.textContent = `${items[i].k} · ${fmt(items[i].n)}`;
      svg.appendChild(sw);
      svg.appendChild(tx);
    }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));
  }

  // ---------- Build charts ----------
  function renderAll(){
    // Summary
    if (state.summary) {
      $('#mTotal').textContent = fmt(state.summary.total || 0);
      $('#mToday').textContent = fmt(state.summary.today || 0);
      $('#mUniq').textContent  = fmt(state.summary.unique_iphash || 0);

      const last = state.summary.last;
      $('#mTotalS').textContent = `Rango: ${state.summary.range_days} días`;
      if (last && last.ts_iso) {
        $('#status').innerHTML = `Última visita: <strong>${escapeHtml(last.ts_iso)}</strong><br><span class="muted small">${escapeHtml(last.url_full || '')}</span>`;
      } else {
        $('#status').textContent = 'Sin datos aún.';
      }
    }

    // Side lists
    renderList($('#topPages'), state.topPages.map(x=>({k:x.k,n:x.n})));
    renderList($('#topRefs'), state.topRefs.map(x=>({k:x.k,n:x.n})));

    // Timeseries bars
    const tip = bindTooltip($('#chartTS'), $('#tip'));
    const ts = state.series || [];
    $('#tsHint').textContent = `${ts.length} días`;

    drawBars($('#chartTS'), ts, {
      valueFn: (d)=>d.n,
      labelFn: (d)=>d.d.slice(5), // MM-DD
      xTicks: (i,n,di)=>{
        if (n <= 10) return true;
        if (n <= 20) return (i%2===0);
        if (n <= 60) return (i%7===0);
        return (i%14===0);
      },
      tipFn: (d)=>`<strong>${escapeHtml(d.d)}</strong>${fmt(d.n)} visitas`,
      tooltip: tip
    });

    // Hours
    const tip2 = bindTooltip($('#chartHours'), $('#tip2'));
    drawBars($('#chartHours'), state.hours || [], {
      valueFn: (d)=>d.n,
      labelFn: (d)=>String(d.h),
      xTicks: (i)=> (i%3===0),
      tipFn: (d)=>`<strong>${String(d.h).padStart(2,'0')}:00</strong>${fmt(d.n)} visitas`,
      tooltip: tip2,
      barGap: 4
    });

    // Devices donut
    const tip3 = bindTooltip($('#chartUA'), $('#tip3'));
    drawDonut($('#chartUA'), (state.devices||[]).map(x=>({k:x.k,n:x.n})), tip3);
  }

  async function load(){
    try {
      setStatus('Cargando datos…');
      const days = state.days;

      const [summary, ts, pages, refs, hours, devs] = await Promise.all([
        api('summary', {days}),
        api('timeseries', {days}),
        api('top_pages', {days, limit: 10}),
        api('top_referrers', {days, limit: 10}),
        api('hours', {days}),
        api('devices', {days})
      ]);

      state.summary = summary;
      state.series  = ts.series || [];
      state.topPages = pages.items || [];
      state.topRefs  = refs.items || [];
      state.hours    = hours.hours || [];
      state.devices  = devs.items || [];

      renderAll();
      setStatus('OK');
    } catch (e) {
      console.error(e);
      setStatus('Error cargando datos. Revisa la BD y la tabla de logs.');
    }
  }

  // UI events
  $('#days').addEventListener('change', (e)=>{
    state.days = parseInt(e.target.value,10) || 30;
  });
  $('#refresh').addEventListener('click', ()=>{
    load();
  });

  load();
</script>

</body>
</html>

