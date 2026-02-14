<?php
// index.php
// SEO-friendly simple PHP templating + basic sitemap/robots endpoints.
// - Loads template HTML + JSON data and replaces {{placeholders}}
// - Adds gzip (if supported), ETag + cache headers
// - Optional: serve /robots.txt and /sitemap.xml from this same file
//   (use rewrite rules or call with ?asset=robots|sitemap)
//
// Files expected:
// - 004-soloplantilla.html
// - datos.json

declare(strict_types=1);

// -------------------------
// Tiny router for assets
// -------------------------
$asset = $_GET['asset'] ?? '';
if ($asset === 'robots') {
  header('Content-Type: text/plain; charset=utf-8');
  $host = current_host();
  $canonical = "https://{$host}/";
  echo "User-agent: *\n";
  echo "Allow: /\n";
  echo "Sitemap: {$canonical}sitemap.xml\n";
  exit;
}
if ($asset === 'sitemap') {
  header('Content-Type: application/xml; charset=utf-8');
  $host = current_host();
  $canonical = "https://{$host}/";
  $today = gmdate('Y-m-d');
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
  echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
  echo "  <url>\n";
  echo "    <loc>" . xml($canonical) . "</loc>\n";
  echo "    <lastmod>{$today}</lastmod>\n";
  echo "    <changefreq>weekly</changefreq>\n";
  echo "    <priority>1.0</priority>\n";
  echo "  </url>\n";
  echo "</urlset>\n";
  exit;
}

// -------------------------
// Output headers (HTML)
// -------------------------
header('Content-Type: text/html; charset=utf-8');

// Enable gzip if possible (improves Core Web Vitals in many setups)
if (!headers_sent() && extension_loaded('zlib')) {
  @ini_set('zlib.output_compression', '1');
}

// -------------------------
// Load files
// -------------------------
$templateFile = __DIR__ . '/principal.html';
$jsonFile     = __DIR__ . '/datos.json';

if (!is_file($templateFile)) {
  http_response_code(500);
  echo "ERROR: Missing template file: 004-soloplantilla.html";
  exit;
}
if (!is_file($jsonFile)) {
  http_response_code(500);
  echo "ERROR: Missing json file: datos.json";
  exit;
}

$template = file_get_contents($templateFile);
if ($template === false) {
  http_response_code(500);
  echo "ERROR: Cannot read 004-soloplantilla.html";
  exit;
}

$raw = file_get_contents($jsonFile);
if ($raw === false) {
  http_response_code(500);
  echo "ERROR: Cannot read datos.json";
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(500);
  echo "ERROR: Invalid JSON in datos.json";
  exit;
}

// -------------------------
// Canonical auto-fill (if you prefer not to hardcode domain in JSON)
// -------------------------
if (empty($data['canonical'])) {
  $data['canonical'] = 'https://' . current_host() . '/';
}

// -------------------------
// Replace placeholders
// -------------------------
$out = apply_placeholders($template, $data);

// -------------------------
// Caching: ETag (helps SEO via faster crawls + user perf)
// -------------------------
$etag = '"' . sha1($out) . '"';
header('ETag: ' . $etag);

// Basic cache policy (tweak as needed)
header('Cache-Control: public, max-age=300'); // 5 min

// If client sent If-None-Match and matches, return 304
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch === $etag) {
  http_response_code(304);
  exit;
}

echo $out;

// -------------------------
// Helpers
// -------------------------
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xml(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_host(): string {
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  // very small hardening: strip whitespace
  $host = preg_replace('/\s+/', '', $host) ?: 'localhost';
  return $host;
}

/**
 * Replace {{key}} placeholders.
 * - Escapes by default
 * - If JSON key starts with "raw:" then it will be inserted unescaped into {{realKey}}
 * - Leaves unknown placeholders intact
 */
function apply_placeholders(string $template, array $data): string {
  foreach ($data as $key => $value) {
    if (is_array($value) || is_object($value)) continue;

    $k = (string)$key;
    $v = (string)$value;

    if (str_starts_with($k, 'raw:')) {
      $realKey = substr($k, 4);
      $needle  = '{{' . $realKey . '}}';
      $template = str_replace($needle, $v, $template);
    } else {
      $needle  = '{{' . $k . '}}';
      $template = str_replace($needle, e($v), $template);
    }
  }
  return $template;
}
 require_once __DIR__ . '/log.php';
log_visit(__DIR__ . '/blog.sqlite');
include __DIR__ . '/sitemap.php';
