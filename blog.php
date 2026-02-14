<?php
// blog.php
// Blog simple (PHP + SQLite) con estilo coherente con la landing.
// - Crea automáticamente la BD y la tabla posts si no existen
// - Lista posts (con paginación) y permite ver un post completo (?id=)
// - Filtro por categoría (?cat=)
// - Buscador por título/contenido (?q=)
// - Feed RSS básico (?rss=1)
// - Renderiza Markdown -> HTML usando markdown.php
//
// Sharing improvements (requested):
// - Share text includes article title + site url + article url
// - Social preview image is forced via OG/Twitter meta tags on article page:
//     https://cursoia.es/logo.png

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/markdown.php';

// =========================
// CONFIG
// =========================
$SITE_NAME    = 'CursoIA';
$SITE_TAGLINE = 'Curso de Inteligencia Artificial';

$SITE_URL     = 'https://cursoia.es/';      // base real
$CANONICAL    = $SITE_URL;                 // canonical base
$SHARE_IMAGE  = 'https://cursoia.es/logo.png';

$DB_FILE      = __DIR__ . '/blog.sqlite';

// UI
$CSS_FILE     = 'estilo.css';              // el mismo CSS de la landing
$LOGO         = 'logo.png';

// =========================
// DB
// =========================
$db = new PDO('sqlite:' . $DB_FILE, null, null, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db->exec("
CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL,          -- ISO 8601: 2026-01-12 13:45:00
  title TEXT NOT NULL,
  content TEXT NOT NULL,       -- Markdown
  category TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_posts_date ON posts(date DESC);
CREATE INDEX IF NOT EXISTS idx_posts_category ON posts(category);
");

// =========================
// HELPERS
// =========================
function h2(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $path  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
  return ($https ? 'https://' : 'http://') . $host . ($path ? $path . '/' : '/');
}
function excerpt_md(string $md, int $len = 240): string {
  $text = md_to_text($md);
  if (mb_strlen($text, 'UTF-8') <= $len) return $text;
  return mb_substr($text, 0, $len, 'UTF-8') . '…';
}
function fmt_date(string $iso): string {
  $ts = strtotime($iso);
  if ($ts === false) return $iso;
  return date('d/m/Y', $ts);
}

// =========================
// OPTIONAL: RSS
// =========================
if (isset($_GET['rss']) && $_GET['rss'] === '1') {
  header('Content-Type: application/rss+xml; charset=utf-8');

  $stmt = $db->query("SELECT id, date, title, content, category FROM posts ORDER BY date DESC, id DESC LIMIT 30");
  $items = $stmt->fetchAll();

  $self = base_url() . 'blog.php?rss=1';
  $site = $CANONICAL ?: base_url();

  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
  echo "<rss version=\"2.0\">\n<channel>\n";
  echo "<title>" . h2($SITE_NAME . ' — Blog') . "</title>\n";
  echo "<link>" . h2($site . 'blog.php') . "</link>\n";
  echo "<description>" . h2('Artículos sobre IA para programadores: ChatGPT, LLM, RAG, agentes, automatización.') . "</description>\n";
  echo "<language>es</language>\n";
  echo "<atom:link xmlns:atom=\"http://www.w3.org/2005/Atom\" href=\"" . h2($self) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";

  foreach ($items as $it) {
    $link = $site . "blog.php?id=" . (int)$it['id'];
    $guid = $link;
    $desc = excerpt_md((string)$it['content'], 320);

    echo "<item>\n";
    echo "  <title>" . h2((string)$it['title']) . "</title>\n";
    echo "  <link>" . h2($link) . "</link>\n";
    echo "  <guid isPermaLink=\"true\">" . h2($guid) . "</guid>\n";
    echo "  <pubDate>" . h2(gmdate('r', strtotime((string)$it['date']) ?: time())) . "</pubDate>\n";
    echo "  <category>" . h2((string)$it['category']) . "</category>\n";
    echo "  <description>" . h2($desc) . "</description>\n";
    echo "</item>\n";
  }

  echo "</channel>\n</rss>";
  exit;
}

// =========================
// ROUTING
// =========================
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cat = isset($_GET['cat']) ? trim((string)$_GET['cat']) : '';
$q   = isset($_GET['q'])   ? trim((string)$_GET['q'])   : '';

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 8;
$offset  = ($page - 1) * $perPage;

// Categories list
$cats = $db->query("SELECT category, COUNT(*) AS n FROM posts GROUP BY category ORDER BY category ASC")->fetchAll();

// =========================
// SINGLE POST
// =========================
if ($id > 0) {
  $stmt = $db->prepare("SELECT id, date, title, content, category FROM posts WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $post = $stmt->fetch();

  if (!$post) {
    http_response_code(404);
    $pageTitle = "No encontrado — Blog | {$SITE_NAME}";
    echo render_header($pageTitle, $CSS_FILE, $LOGO, $SITE_NAME, $SITE_TAGLINE, 'Artículo no encontrado.', ($CANONICAL ?: base_url()) . 'blog.php', $SHARE_IMAGE, 'website');
    echo render_blog_shell_start($SITE_NAME, $cats, $cat, $q);
    echo '<article class="blog-post card">';
    echo '<h1>Artículo no encontrado</h1>';
    echo '<p class="muted">El artículo solicitado no existe o ha sido eliminado.</p>';
    echo '<a class="btn btn-primary" href="blog.php">Volver al blog</a>';
    echo '</article>';
    echo render_blog_shell_end();
    echo render_footer($SITE_NAME, $SITE_TAGLINE);
    exit;
  }

  $md = (string)$post['content'];
  $htmlBody = md_to_html($md);

  $postUrl  = ($SITE_URL ?: ($CANONICAL ?: base_url())) . "blog.php?id=" . (int)$post['id'];
  $pageTitle = (string)$post['title'] . " — Blog | {$SITE_NAME}";
  $pageDesc  = excerpt_md($md, 160);

  // OG/Twitter per-article + forced share image
  echo render_header($pageTitle, $CSS_FILE, $LOGO, $SITE_NAME, $SITE_TAGLINE, $pageDesc, $postUrl, $SHARE_IMAGE, 'article');
  echo render_blog_shell_start($SITE_NAME, $cats, $cat, $q);

  // Calcular tiempo estimado de lectura (aprox. 200 palabras/minuto)
  $wordCount = str_word_count(strip_tags($htmlBody));
  $readMin   = max(1, (int)ceil($wordCount / 200));

  echo '<article class="blog-post card" itemscope itemtype="https://schema.org/BlogPosting">';
  echo '  <header class="blog-post-head">';
  echo '    <p class="blog-meta">';
  echo '      <span class="chip">' . h2((string)$post['category']) . '</span>';
  echo '      <span class="sep">·</span>';
  echo '      <time datetime="' . h2((string)$post['date']) . '" itemprop="datePublished">' . h2(fmt_date((string)$post['date'])) . '</time>';
  echo '      <span class="sep">·</span>';
  echo '      <span class="muted small">⏱ ' . $readMin . ' min de lectura</span>';
  echo '    </p>';
  echo '    <h1 itemprop="headline">' . h2((string)$post['title']) . '</h1>';
  echo '  </header>';

  // Rendered HTML from Markdown
  echo '  <div class="blog-content" itemprop="articleBody">';
  echo $htmlBody;
  echo '  </div>';

  echo '  <footer class="blog-post-foot">';

  echo '    <div class="blog-post-actions">';
  echo '      <a class="btn btn-primary" href="index.php#contacto">Quiero información del curso</a>';
  echo '      <a class="btn btn-ghost" href="blog.php">Volver al listado</a>';
  echo '    </div>';

  echo '    <div class="share">';
  echo '      <p class="muted small" style="margin:10px 0 8px;">Compartir:</p>';

  // Share URLs (include title + site url + article url)
  $shareUrl  = rawurlencode($postUrl);
  $shareText = rawurlencode((string)$post['title'] . ' — ' . $SITE_NAME . ' — ' . $SITE_URL . ' ' . $postUrl);

  $xUrl        = "https://x.com/intent/tweet?url={$shareUrl}&text={$shareText}";
  $linkedinUrl = "https://www.linkedin.com/sharing/share-offsite/?url={$shareUrl}";
  $facebookUrl = "https://www.facebook.com/sharer/sharer.php?u={$shareUrl}";
  $whatsUrl    = "https://wa.me/?text={$shareText}";

  echo '      <div class="share-buttons" role="navigation" aria-label="Compartir en redes">';
  echo '        <a class="share-btn share-x" href="' . h2($xUrl) . '" target="_blank" rel="noopener nofollow" aria-label="Compartir en X">X</a>';
  echo '        <a class="share-btn share-linkedin" href="' . h2($linkedinUrl) . '" target="_blank" rel="noopener nofollow" aria-label="Compartir en LinkedIn">in</a>';
  echo '        <a class="share-btn share-facebook" href="' . h2($facebookUrl) . '" target="_blank" rel="noopener nofollow" aria-label="Compartir en Facebook">f</a>';
  echo '        <a class="share-btn share-whatsapp" href="' . h2($whatsUrl) . '" target="_blank" rel="noopener nofollow" aria-label="Compartir en WhatsApp">wa</a>';
  echo '      </div>';

  echo '      <p class="muted small" style="margin-top:10px;">Enlace permanente: <a href="' . h2($postUrl) . '">' . h2($postUrl) . '</a></p>';
  echo '    </div>';

  echo '  </footer>';

  echo '</article>';

  echo render_blog_shell_end(true);
  echo render_footer($SITE_NAME, $SITE_TAGLINE);
  exit;
}

// =========================
// LISTING
// =========================
$where = [];
$params = [];

if ($cat !== '') {
  $where[] = "category = :cat";
  $params[':cat'] = $cat;
}
if ($q !== '') {
  $where[] = "(title LIKE :q OR content LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// total
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM posts {$whereSql}");
$stmt->execute($params);
$total = (int)($stmt->fetch()['c'] ?? 0);

// fetch
$stmt = $db->prepare("
  SELECT id, date, title, content, category
  FROM posts
  {$whereSql}
  ORDER BY date DESC, id DESC
  LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$pageTitle = "Blog — {$SITE_NAME}";
$pageDesc  = "Artículos sobre IA para programadores: ChatGPT, LLM, RAG, agentes y automatización con IA.";

$listCanonical = ($CANONICAL ?: base_url()) . 'blog.php';
echo render_header($pageTitle, $CSS_FILE, $LOGO, $SITE_NAME, $SITE_TAGLINE, $pageDesc, $listCanonical, $SHARE_IMAGE, 'website');
echo render_blog_shell_start($SITE_NAME, $cats, $cat, $q);

echo '<section class="blog-list">';
echo '  <div class="blog-list-head">';
echo '    <h1>Blog</h1>';
echo '    <p class="muted">IA práctica para programadores: ChatGPT, LLM, RAG, agentes, evaluación y despliegue.</p>';
echo '  </div>';

if ($total === 0) {
  echo '<div class="card" style="padding:18px;">';
  echo '<h2 style="margin:0 0 8px;">Aún no hay artículos</h2>';
  echo '<p class="muted">Cuando insertes posts en SQLite, aparecerán aquí automáticamente.</p>';
  echo '</div>';
} else {
  foreach ($posts as $p) {
    $url = 'blog.php?id=' . (int)$p['id'];
    echo '<article class="blog-card card">';
    echo '  <p class="blog-meta">';
    echo '    <a class="chip" href="blog.php?cat=' . rawurlencode((string)$p['category']) . '">' . h2((string)$p['category']) . '</a>';
    echo '    <span class="sep">·</span>';
    echo '    <time datetime="' . h2((string)$p['date']) . '">' . h2(fmt_date((string)$p['date'])) . '</time>';
    echo '  </p>';
    echo '  <h2><a href="' . h2($url) . '">' . h2((string)$p['title']) . '</a></h2>';
    echo '  <p class="muted">' . h2(excerpt_md((string)$p['content'])) . '</p>';
    echo '  <div class="blog-card-actions">';
    echo '    <a class="btn btn-ghost" href="' . h2($url) . '">Leer</a>';
    echo '    <a class="btn btn-primary" href="index.php#contacto">Inscripción</a>';
    echo '  </div>';
    echo '</article>';
  }

  // pagination
  $pages = (int)ceil($total / $perPage);
  if ($pages > 1) {
    $base = 'blog.php' . build_query_base(['id', 'p']);
    echo '<nav class="pager" aria-label="Paginación">';
    if ($page > 1) {
      echo '<a class="btn btn-ghost" href="' . h2($base . '&p=' . ($page - 1)) . '">← Anterior</a>';
    } else {
      echo '<span></span>';
    }
    echo '<p class="muted small">Página ' . (int)$page . ' de ' . (int)$pages . '</p>';
    if ($page < $pages) {
      echo '<a class="btn btn-ghost" href="' . h2($base . '&p=' . ($page + 1)) . '">Siguiente →</a>';
    } else {
      echo '<span></span>';
    }
    echo '</nav>';
  }
}
echo '</section>';

echo render_blog_shell_end();
echo render_footer($SITE_NAME, $SITE_TAGLINE);
exit;

// =========================
// VIEW COMPONENTS
// =========================
function render_header(
  string $title,
  string $cssFile,
  string $logo,
  string $site,
  string $tagline,
  string $desc = '',
  string $canonicalUrl = '',
  string $ogImage = '',
  string $ogType = 'website'
): string {
  $desc = $desc ?: 'Blog sobre inteligencia artificial para programadores.';
  $rssUrl = 'blog.php?rss=1';

  if ($canonicalUrl === '') {
    $canonicalUrl = base_url() . 'blog.php';
  }
  if ($ogImage === '') {
    $ogImage = 'https://cursoia.es/logo.png';
  }

  return '<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />

  <title>' . h2($title) . '</title>
  <meta name="description" content="' . h2($desc) . '">
  <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

  <link rel="canonical" href="' . h2($canonicalUrl) . '">

  <!-- Open Graph -->
  <meta property="og:type" content="' . h2($ogType) . '">
  <meta property="og:locale" content="es_ES">
  <meta property="og:site_name" content="' . h2($site) . '">
  <meta property="og:title" content="' . h2($title) . '">
  <meta property="og:description" content="' . h2($desc) . '">
  <meta property="og:url" content="' . h2($canonicalUrl) . '">
  <meta property="og:image" content="' . h2($ogImage) . '">
  <meta property="og:image:alt" content="' . h2($site) . '">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="' . h2($title) . '">
  <meta name="twitter:description" content="' . h2($desc) . '">
  <meta name="twitter:image" content="' . h2($ogImage) . '">

  <link rel="stylesheet" href="' . h2($cssFile) . '">
  <link rel="alternate" type="application/rss+xml" title="' . h2($site) . ' — RSS" href="' . h2($rssUrl) . '">
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>

<header>
  <div class="container">
    <div class="topbar">
      <div id="corporativo" aria-label="Marca">
        <a href="index.php" aria-label="' . h2($site) . '">
          <img src="' . h2($logo) . '" alt="Logo de ' . h2($site) . '" width="42" height="42" loading="eager" decoding="async">
        </a>
        <div class="texto">
          <span class="t1">' . h2($site) . '</span>
          <span class="t2">' . h2($tagline) . '</span>
        </div>
      </div>

      <nav aria-label="Navegación principal">
        <a href="index.php#heroe">Inicio</a>
        <a href="index.php#destacado2">Metodología</a>
        <a href="index.php#destacado4">Temario</a>
        <a href="blog.php" class="nav-cta">Blog</a>
        <a href="index.php#contacto" class="nav-cta">Inscripción</a>
      </nav>
    </div>
  </div>
</header>

<main id="contenido">
';
}

function render_footer(string $site, string $tagline): string {
  $year = date('Y');
  return '
<section id="contacto" aria-label="Contacto">
  <div class="container contact-grid">
    <div class="footer-mini" id="infocorporacion">
      <h2 style="font-size:22px;font-weight:900;margin-bottom:10px;">Contacta e inscríbete</h2>
      <p>Cuéntanos tu caso y te respondemos con una recomendación clara.</p>
      <div style="margin-top:14px;">
        <p><strong>Dirección:</strong> Online · Clases en directo por streaming</p>
        <p><strong>Email:</strong> <a href="mailto:info@cursoia.es">info@cursoia.es</a></p>
      </div>
    </div>

    <form action="enviacorreo.php" method="post">
      <h2 style="font-size:20px;font-weight:900;margin-bottom:6px;">Contacto</h2>
      <p>Indica tu objetivo (ChatGPT, RAG, agentes, automatización) y tu stack (web/backend).</p>

      <div class="form-row">
        <div>
          <label for="nombre">Nombre</label>
          <input id="nombre" type="text" name="nombre" placeholder="Tu nombre" required autocomplete="name">
        </div>
        <div>
          <label for="email_form">Email</label>
          <input id="email_form" type="email" name="email" placeholder="tu@email.com" required autocomplete="email">
        </div>
      </div>

      <label for="mensaje">Mensaje</label>
      <textarea id="mensaje" name="mensaje" placeholder="¿En qué puedo ayudarte?" required></textarea>

      <div style="margin-top:14px;">
        <button class="btn btn-primary" type="submit">Enviar mensaje</button>
      </div>

      <p class="form-legal">Al enviar este formulario aceptas nuestra política de privacidad y el tratamiento de tus datos para responder a tu solicitud.</p>
    </form>
  </div>
</section>

</main>

<footer>
  <div class="container footer-wrap">
    <div>(c) ' . h2((string)$year) . ' ' . h2($site) . ' ' . h2($tagline) . ' — Todos los derechos reservados</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <a href="/privacy.html" style="opacity:.85;">Privacy Policy</a>
      <a href="/terms.html" style="opacity:.85;">Terms of Use</a>
      <a href="blog.php?rss=1" style="opacity:.85;">RSS</a>
    </div>
  </div>
</footer>

</body>
</html>';
}

function render_blog_shell_start(string $site, array $cats = [], string $activeCat = '', string $q = ''): string {
  $qEsc = h2($q);

  $catsHtml = '';
  if ($cats) {
    $catsHtml .= '<div class="side-block">';
    $catsHtml .= '<h3>Categorías</h3>';
    $catsHtml .= '<div class="cat-list">';
    $catsHtml .= '<a class="chip ' . ($activeCat === '' ? 'chip-active' : '') . '" href="blog.php">Todas</a>';
    foreach ($cats as $c) {
      $name = (string)$c['category'];
      $n    = (int)$c['n'];
      $catsHtml .= '<a class="chip ' . ($activeCat === $name ? 'chip-active' : '') . '" href="blog.php?cat=' . rawurlencode($name) . '">' . h2($name) . ' <span class="chip-n">' . $n . '</span></a>';
    }
    $catsHtml .= '</div></div>';
  }

  return '
<div class="container" style="padding: 34px 0;">
  <div class="blog-grid">
    <aside class="blog-side">
      <div class="side-block">
        <h3>Buscar</h3>
        <form class="search" method="get" action="blog.php">
          <input type="text" name="q" value="' . $qEsc . '" placeholder="Buscar en el blog…">
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>
        <p class="muted small" style="margin-top:10px;">Ej.: “curso chatgpt programadores”, “RAG”, “agentes”, “automatización”.</p>
      </div>
      ' . $catsHtml . '
      <div class="side-block">
        <h3>Enlaces</h3>
        <div class="cat-list">
          <a class="chip" href="index.php#contacto">Inscripción</a>
          <a class="chip" href="blog.php?rss=1">RSS</a>
        </div>
      </div>
    </aside>

    <section class="blog-main">
';
}

function render_blog_shell_end(bool $single = false): string {
  return '
    </section>
  </div>
</div>

<style>
.blog-grid{
  display:grid;
  grid-template-columns: 320px 1fr;
  gap: 22px;
  align-items:start;
}
.blog-side .side-block{
  background:#fff;
  border:1px solid rgba(0,0,0,.08);
  padding:16px;
  margin-bottom: 14px;
}
.blog-side h3{
  margin:0 0 10px;
  font-size: 15px;
  font-weight: 900;
}
.search{
  display:flex;
  gap: 10px;
}
.search input{
  flex:1;
  border: 1px solid rgba(0,0,0,.14);
  padding: 11px 12px;
  background:#fff;
}
.cat-list{
  display:flex;
  flex-wrap:wrap;
  gap: 8px;
}
.chip{
  display:inline-flex;
  align-items:center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 999px;
  background: rgba(0,0,0,.06);
  font-size: 12px;
  font-weight: 800;
  text-decoration:none;
}
.chip-active{
  background: rgba(243,154,26,.18);
}
.chip-n{
  font-weight: 900;
  opacity:.8;
}
.blog-main h1{
  margin:0 0 8px;
  font-size: 28px;
  font-weight: 900;
}
.blog-list{
  display:grid;
  gap: 14px;
}
.blog-card{
  padding: 18px;
}
.blog-card h2{
  margin: 0 0 8px;
  font-size: 20px;
  font-weight: 900;
}
.blog-meta{
  display:flex;
  gap: 10px;
  align-items:center;
  flex-wrap:wrap;
  margin:0 0 10px;
  font-size: 12px;
  color:#5b6670;
}
.sep{ opacity:.55; }
.blog-card-actions{
  display:flex;
  gap: 10px;
  margin-top: 12px;
}
.card{
  background:#fff;
  border:1px solid rgba(0,0,0,.08);
}
.btn-ghost{
  background: rgba(0,0,0,.06);
  color: #2f3a44;
  box-shadow: none;
}
.btn-ghost:hover{
  transform: translateY(-1px);
}
.muted{ color:#5b6670; }
.small{ font-size: 12px; }

/* Post */
.blog-post.card{
  padding: 22px;
}
.blog-post-head h1{
  font-size: 30px;
}
.blog-content{
  margin-top: 14px;
  color: #1b1f23;
}

/* Markdown styling inside post */
.blog-content p{ color:#2f3a44; font-size: 15px; }
.blog-content h2{ font-size: 22px; margin-top: 22px; }
.blog-content h3{ font-size: 18px; margin-top: 18px; }
.blog-content ul, .blog-content ol{ padding-left: 22px; margin: 12px 0; color:#2f3a44; }
.blog-content li{ margin: 6px 0; }
.blog-content blockquote{
  border-left: 4px solid rgba(243,154,26,.55);
  padding-left: 12px;
  margin: 14px 0;
  color:#2f3a44;
  background: rgba(243,154,26,.08);
}
.blog-content code{
  padding: 2px 6px;
  border: 1px solid rgba(0,0,0,.12);
  background: rgba(0,0,0,.04);
}
.blog-content pre{
  padding: 14px;
  overflow:auto;
  border: 1px solid rgba(0,0,0,.12);
  background: rgba(0,0,0,.04);
}
.blog-content pre code{ border:0; background: transparent; padding:0; }

.pager{
  display:grid;
  grid-template-columns: 1fr auto 1fr;
  align-items:center;
  gap: 10px;
  margin-top: 14px;
}

@media (max-width: 980px){
  .blog-grid{ grid-template-columns: 1fr; }
  .search{ flex-direction: column; }
}

/* Share */
.blog-post-foot{
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid rgba(0,0,0,.08);
}
.blog-post-actions{
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items:center;
}
.share{
  margin-top: 10px;
}
.share-buttons{
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
}
.share-btn{
  width: 44px;
  height: 38px;
  border-radius: 999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-weight: 900;
  font-size: 12px;
  background: rgba(0,0,0,.06);
  border: 1px solid rgba(0,0,0,.08);
  text-decoration:none;
  user-select:none;
  transition: transform .12s ease, background .2s ease;
}
.share-btn:hover{
  transform: translateY(-1px);
  background: rgba(243,154,26,.18);
}
</style>
';
}

function build_query_base(array $excludeKeys): string {
  $q = $_GET;
  foreach ($excludeKeys as $k) unset($q[$k]);
  $pairs = [];
  foreach ($q as $k => $v) {
    $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
  }
  return '?' . ($pairs ? implode('&', $pairs) : '');
}
 require_once __DIR__ . '/log.php';
log_visit(__DIR__ . '/blog.sqlite');
