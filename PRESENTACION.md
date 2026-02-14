# Mejora de la Web Curso IA — CursoIA.es

![Landing page del Curso de Inteligencia Artificial](img/hero.png)

## Introducción

Este proyecto toma la web estática del Curso de Inteligencia Artificial para Programadores (creada en Lenguajes de Marcas) y la transforma en una **aplicación web completa con PHP y SQLite**. Se añade un sistema de plantillas con reemplazo de placeholders, un blog dinámico con Markdown, un panel de analíticas de visitas, un logger GDPR-friendly, envío de correo por SMTP, generación automática de sitemap.xml y SEO avanzado (Open Graph, JSON-LD, ETag caching).

El resultado es una web profesional con landing page, blog con categorías y búsqueda, panel de administración con gráficas, y formulario de contacto funcional — todo construido con PHP puro y SQLite, sin frameworks externos.

---

## Desarrollo

### 1. index.php — Motor de plantillas con SEO avanzado

El archivo `index.php` es el punto de entrada principal. Carga la plantilla HTML (`principal.html`) y el archivo de datos (`datos.json`), y reemplaza todos los placeholders `{{clave}}` con los valores del JSON. Además incluye compresión gzip, cabeceras ETag para caché, y endpoints para robots.txt y sitemap.xml.

```php
// index.php — líneas 60-85
// Ruta: index.php
$templateFile = __DIR__ . '/principal.html';
$jsonFile     = __DIR__ . '/datos.json';

$template = file_get_contents($templateFile);
$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);

// Reemplazar placeholders
$out = apply_placeholders($template, $data);

// Caching: ETag para rendimiento SEO
$etag = '"' . sha1($out) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=300');
```

La función `apply_placeholders()` recorre el array JSON y sustituye cada `{{clave}}` en el HTML. Las claves normales se escapan con `htmlspecialchars` por seguridad, y las que empiezan con `raw:` (como el JSON-LD) se insertan sin escapar.

---

### 2. principal.html — Plantilla HTML con 9 secciones y SEO completo

La plantilla define toda la estructura visual de la landing page usando placeholders `{{...}}`. Incluye meta tags Open Graph, Twitter Cards, JSON-LD (Schema.org), link canónico, preload de recursos, skip-link de accesibilidad y 9 secciones de contenido (hero, destacados, CTA, contacto).

```html
<!-- principal.html — líneas 1-48 -->
<!-- Ruta: principal.html -->
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="description" content="{{meta_description}}">
  <meta property="og:title" content="{{og_title}}">
  <meta property="og:description" content="{{og_description}}">
  <meta property="og:image" content="{{og_image}}">
  <meta name="twitter:card" content="summary_large_image">
  <title>{{title_full}}</title>
  <link rel="preload" as="image" href="{{hero_image}}">
  <link rel="stylesheet" href="estilo.css">
  <script type="application/ld+json">
  {{raw:jsonld}}
  </script>
</head>
```

El formulario de contacto envía a `enviacorreo.php` y ahora incluye validación JavaScript que comprueba nombre (≥2 caracteres), formato de email y longitud del mensaje (≥10 caracteres) antes de enviar.

---

### 3. datos.json — Configuración centralizada de contenidos y SEO

Todo el contenido de la web se gestiona desde un único archivo JSON: textos de cada sección, metadatos SEO, Open Graph, Twitter Cards, datos de contacto, URLs de redes sociales y el JSON-LD completo para Schema.org (Organization + WebSite + WebPage + Course).

```json
// datos.json — líneas 1-20
// Ruta: datos.json
{
  "titulo1": "CursoIA",
  "titulo2": "Curso de Inteligencia Artificial",
  "title_full": "Curso de IA para Programadores | IA práctica en directo",
  "meta_description": "Curso de inteligencia artificial para programadores en directo...",
  "theme_color": "#f39a1a",
  "canonical": "https://cursoia.es/",
  "og_title": "Curso de IA para Programadores (en directo)",
  "og_image": "https://tucursoia.com/og.jpg",
  "raw:jsonld": "{ \"@context\": \"https://schema.org\", \"@graph\": [...] }"
}
```

Separar contenido de estructura permite modificar textos, SEO y datos de contacto sin tocar el HTML ni el PHP.

---

### 4. estilo.css — Diseño moderno con CSS Variables y responsive

El CSS usa variables personalizadas (`:root`) para colores, sombras y radios, permitiendo cambiar el tema fácilmente. Incluye sticky header transparente, hero con overlay y gradiente, grid sections, botones con transiciones, formulario estilizado y diseño responsive completo con dos breakpoints (980px y 560px).

```css
/* estilo.css — líneas 2-22 */
/* Ruta: estilo.css */
:root{
  --bg: #ffffff;
  --bg-soft: #f2f4f4;
  --text: #1b1f23;
  --muted: #5b6670;
  --green: #6f8f12;
  --orange: #f39a1a;
  --orange-dark: #d98206;
  --shadow: 0 10px 24px rgba(0,0,0,.08);
  --radius: 10px;
  --container: 1120px;
}

/* Scroll suave para navegación por anclas */
html{ scroll-behavior: smooth; }
```

Se añadieron mejoras de accesibilidad: `focus-visible` con outline naranja en enlaces de navegación y botones, y transiciones suaves en los iconos sociales con efecto scale al hacer hover.

---

### 5. blog.php — Blog dinámico con SQLite, Markdown y RSS

El archivo `blog.php` implementa un blog completo: listado con paginación, vista de artículo individual, filtro por categoría, búsqueda por texto, feed RSS y compartir en redes sociales. Los posts se almacenan en SQLite (`blog.sqlite`) con contenido en Markdown que se renderiza a HTML usando `markdown.php`.

```php
// blog.php — líneas 56-84
// Ruta: blog.php
function excerpt_md(string $md, int $len = 240): string {
  $text = md_to_text($md);
  if (mb_strlen($text, 'UTF-8') <= $len) return $text;
  return mb_substr($text, 0, $len, 'UTF-8') . '…';
}

// Vista de artículo individual con Schema.org BlogPosting
echo '<article class="blog-post card" itemscope itemtype="https://schema.org/BlogPosting">';
echo '  <h1 itemprop="headline">' . h2($post['title']) . '</h1>';
echo '  <div class="blog-content" itemprop="articleBody">';
echo $htmlBody;  // Markdown renderizado a HTML
echo '  </div>';
```

Se añadió un cálculo de **tiempo estimado de lectura** (200 palabras/minuto) que se muestra junto a la fecha y categoría de cada artículo.

---

### 6. admin.php — Panel de analíticas con login y gráficas

El panel de administración requiere autenticación (usuario/contraseña hardcoded con `hash_equals`). Ofrece API endpoints JSON que devuelven: resumen de visitas, serie temporal por día, páginas más visitadas, referrers, distribución de dispositivos/navegadores y visitas por horas. La interfaz muestra gráficas con barras CSS (sin librerías externas).

```php
// admin.php — líneas 42-48
// Ruta: admin.php
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function is_logged_in(): bool {
  return !empty($_SESSION['admin_ok']) && $_SESSION['admin_ok'] === true;
}
```

Las consultas SQL agrupan logs por día, path, referer e IP hash para generar estadísticas sin comprometer la privacidad de los visitantes.

---

### 7. log.php — Logger de visitas compatible con GDPR

El logger captura cada visita (timestamp, IP anonimizada por hash, user agent, URL, referer, idiomas, método HTTP) y la guarda en la tabla `visits` de SQLite. Incluye opciones configurables: anonimizar IP, truncar strings largos, y almacenar o no el query string.

```php
// log.php — líneas 17-32
// Ruta: log.php
/**
 * Registra una visita en la base de datos SQLite.
 * Cumple GDPR: no guarda cookies ni parámetros sensibles.
 */
function log_visit(string $dbFile, array $opts = []): void {
  $defaults = [
    'table' => 'visits',
    'enabled' => true,
    'store_get' => true,
    'truncate' => 2000,
    'anonymize_ip' => true,  // recomendable para GDPR
  ];
  $cfg = array_merge($defaults, $opts);
}
```

---

### 8. enviacorreo.php — Envío SMTP con STARTTLS

Gestiona el envío del formulario de contacto vía SMTP (IONOS) usando STARTTLS en el puerto 587. Construye el email MIME manualmente (sin librerías), valida los campos del formulario server-side, y muestra una página de confirmación con redirección automática a 5 segundos.

```php
// enviacorreo.php — líneas 27-36
// Ruta: enviacorreo.php
$nombre  = trim((string)($_POST['nombre'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$mensaje = trim((string)($_POST['mensaje'] ?? ''));

$errors = [];
if ($nombre === '') $errors[] = 'Falta el nombre.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
if ($mensaje === '') $errors[] = 'Falta el mensaje.';
```

Se añadió un comentario de seguridad recordando mover las credenciales SMTP a variables de entorno en producción.

---

### 9. markdown.php y sitemap.php — Utilidades del ecosistema

`markdown.php` es un renderizador Markdown-a-HTML sin dependencias externas. Soporta encabezados, párrafos, negrita/cursiva, código inline y bloques, enlaces, listas, blockquotes y líneas horizontales. Sanitiza HTML por defecto.

```php
// markdown.php — líneas 10-15
// Ruta: markdown.php
function md_to_html(string $md): string {
  $md = normalize_newlines($md);
  $lines = explode("\n", $md);
  // Procesa línea por línea: headings, code blocks, lists, quotes...
}
```

`sitemap.php` genera dinámicamente `sitemap.xml` incluyendo la página principal, las páginas legales y todos los posts del blog con sus fechas de publicación.

---

### 10. Mejoras aplicadas

- **CSS**: `scroll-behavior: smooth` para navegación fluida por anclas.
- **CSS**: Efecto `scale(1.15)` en hover de iconos sociales con transición.
- **CSS**: `focus-visible` con outline naranja en enlaces de navegación y botones para accesibilidad.
- **JS**: Validación client-side del formulario de contacto: nombre ≥2 chars, email con regex, mensaje ≥10 chars.
- **PHP (blog.php)**: Tiempo estimado de lectura (200 palabras/min) en la vista de artículo.
- **PHP (enviacorreo.php)**: Comentario de seguridad sobre credenciales SMTP en código.
- **PHP (log.php)**: Docstring explicativo sobre cumplimiento GDPR de la función `log_visit`.

---

## Presentación

CursoIA es una web completa para un curso de Inteligencia Artificial dirigido a programadores. El proyecto parte de una landing page estática (HTML + CSS) y la evoluciona añadiendo capas de backend con PHP y SQLite.

La landing page usa un sistema de plantillas propio donde el HTML contiene placeholders `{{clave}}` que se rellenan desde un JSON centralizado, permitiendo cambiar todo el contenido sin tocar código. El diseño es moderno y responsive, con sticky header, hero con overlay, 9 secciones temáticas y formulario de contacto funcional que envía por SMTP.

El blog está construido sobre SQLite: los artículos se escriben en Markdown y se renderizan a HTML en tiempo real. Soporta paginación, filtro por categoría, búsqueda, feeds RSS y compartir en redes sociales con metadatos Open Graph correctos.

El panel de administración muestra analíticas de las visitas registradas por el logger (totales, series temporales, páginas top, referrers, dispositivos y horas), todo con autenticación por sesión y gráficas CSS sin librerías externas.

Las mejoras aplicadas tocan CSS (scroll suave, hover en iconos, focus-visible), JavaScript (validación del formulario) y PHP (tiempo de lectura, documentación GDPR, advertencia de seguridad SMTP).

---

## Conclusión

Este proyecto demuestra cómo transformar una web estática de presentación en una aplicación web completa utilizando PHP y SQLite como único stack de backend. Cada componente — plantillas, blog, analíticas, logging, SMTP, sitemap — se construye desde cero sin frameworks, lo que permite entender el funcionamiento interno de cada pieza.

La arquitectura separa datos (JSON) de presentación (HTML) y lógica (PHP), facilitando el mantenimiento. El uso de SQLite elimina la necesidad de un servidor de base de datos externo, y el renderizador Markdown propio evita dependencias. Las mejoras aplicadas refuerzan buenas prácticas: accesibilidad (focus-visible, skip-link), seguridad (htmlspecialchars, validación server+client, GDPR), rendimiento (ETag, gzip, preload) y experiencia de usuario (smooth scroll, hover effects, tiempo de lectura).
