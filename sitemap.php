<?php
// sitemap.php
// Genera sitemap.xml en disco al ser incluido.
// Uso típico:
//   include __DIR__ . '/sitemap.php';
//
// No imprime nada en pantalla.
// Sobrescribe sitemap.xml en cada ejecución.

declare(strict_types=1);

// =========================
// CONFIGURACIÓN
// =========================
$SITE_BASE = 'https://cursoia.es/';   // SIEMPRE con barra final
$OUTPUT_FILE = __DIR__ . '/sitemap.xml';
$DB_FILE = __DIR__ . '/blog.sqlite';

$INCLUDE_LEGAL_PAGES = true;

// =========================
// HELPERS
// =========================
function xml_escape(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_base(string $base): string {
  return rtrim(trim($base), '/') . '/';
}

$SITE_BASE = normalize_base($SITE_BASE);
$now = gmdate('Y-m-d\TH:i:s\Z');

// =========================
// DB (posts del blog)
// =========================
$posts = [];

if (file_exists($DB_FILE)) {
  try {
    $db = new PDO('sqlite:' . $DB_FILE, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $db->exec("
      CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        category TEXT NOT NULL
      )
    ");

    $posts = $db
      ->query("SELECT id, date FROM posts ORDER BY date DESC, id DESC")
      ->fetchAll();

  } catch (Throwable $e) {
    // Si falla la BD, seguimos con sitemap mínimo
    $posts = [];
  }
}

// =========================
// CONSTRUIR XML
// =========================
$xml = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$addUrl = function (
  string $loc,
  ?string $lastmod = null,
  ?string $changefreq = null,
  ?string $priority = null
) use (&$xml) {
  $xml[] = '  <url>';
  $xml[] = '    <loc>' . xml_escape($loc) . '</loc>';
  if ($lastmod)    $xml[] = '    <lastmod>' . xml_escape($lastmod) . '</lastmod>';
  if ($changefreq) $xml[] = '    <changefreq>' . xml_escape($changefreq) . '</changefreq>';
  if ($priority)   $xml[] = '    <priority>' . xml_escape($priority) . '</priority>';
  $xml[] = '  </url>';
};

// =========================
// URLs PRINCIPALES
// =========================
$addUrl($SITE_BASE, $now, 'daily', '1.0');
$addUrl($SITE_BASE . 'blog.php', $now, 'daily', '0.9');

if ($INCLUDE_LEGAL_PAGES) {
  $addUrl($SITE_BASE . 'privacy.html', $now, 'monthly', '0.3');
  $addUrl($SITE_BASE . 'terms.html',   $now, 'monthly', '0.3');
}

// =========================
// POSTS DEL BLOG
// =========================
foreach ($posts as $p) {
  $id = (int)($p['id'] ?? 0);
  if ($id <= 0) continue;

  $date = (string)($p['date'] ?? '');
  $ts = strtotime($date);
  $lastmod = $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : $now;

  $addUrl(
    $SITE_BASE . 'blog.php?id=' . $id,
    $lastmod,
    'weekly',
    '0.7'
  );
}

$xml[] = '</urlset>';

// =========================
// ESCRIBIR ARCHIVO
// =========================
file_put_contents(
  $OUTPUT_FILE,
  implode("\n", $xml) . "\n",
  LOCK_EX
);

// No output, no exit → seguro para include

