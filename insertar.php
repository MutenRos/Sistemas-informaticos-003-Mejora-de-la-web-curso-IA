<?php
// seed_posts.php
// Inserta 3 artículos de ejemplo en blog.sqlite con contenido en Markdown.
// Ejecuta una vez y luego borra este archivo.
//
// URL: /seed_posts.php
// (Opcional) Protegido con ?key=TUCLAVE

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

// =========================
// CONFIG
// =========================
$DB_FILE = __DIR__ . '/blog.sqlite';

// Protección simple (recomendado). Cambia la clave.
$KEY = 'cambia-esta-clave';
if (!isset($_GET['key']) || $_GET['key'] !== $KEY) {
  http_response_code(403);
  echo "403 Forbidden\nAñade ?key={$KEY}\n";
  exit;
}

// =========================
// DB
// =========================
$db = new PDO('sqlite:' . $DB_FILE, null, null, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Asegura tabla
$db->exec("
CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL,
  title TEXT NOT NULL,
  content TEXT NOT NULL,
  category TEXT NOT NULL
);
");

// Evitar duplicados (por título)
$existsStmt = $db->prepare("SELECT id FROM posts WHERE title = :t LIMIT 1");
$insStmt = $db->prepare("
  INSERT INTO posts(date, title, content, category)
  VALUES(:date, :title, :content, :category)
");

// =========================
// POSTS (Markdown)
// =========================
$posts = [
  [
    'date' => date('Y-m-d H:i:s', strtotime('-2 days')),
    'title' => 'Curso de IA para programadores: qué vas a construir (y por qué importa)',
    'category' => 'Introducción',
    'content' => <<<MD
En internet hay miles de demos de IA. El problema es que **una demo no es un sistema**.

En este curso trabajamos con un objetivo claro: **integrar IA en aplicaciones reales**. Eso significa:

- Diseñar prompts que no se rompen al primer input raro
- Conectar la IA a tus datos (RAG) sin inventos
- Añadir herramientas (agentes) para automatizar tareas
- Medir calidad, costes y fallos de forma continua

## Qué proyectos vas a terminar

Al final tendrás varios entregables reutilizables:

1. Un **chatbot útil** con historial y contexto
2. Un **buscador semántico** sobre tus documentos (RAG)
3. Un **agente** con herramientas (APIs, archivos, tareas)
4. Un **evaluador** de respuestas con criterios y métricas
5. Un proyecto final **end-to-end** (frontend + backend + RAG + logs)

## Por qué esto es distinto de “usar ChatGPT”

“Usar ChatGPT” es escribir prompts.  
**Programar IA** es diseñar un sistema:

- fiable
- medible
- mantenible
- con control de costes
- con privacidad y seguridad

Si ya programas, lo que necesitas es **arquitectura y metodología**, no más tutoriales sueltos.

MD
  ],
  [
    'date' => date('Y-m-d H:i:s', strtotime('-1 days')),
    'title' => 'RAG explicado para programadores: patrón mínimo para hacerlo bien',
    'category' => 'RAG',
    'content' => <<<MD
RAG (*Retrieval-Augmented Generation*) es el patrón más rentable cuando quieres que un modelo responda **con tus documentos** sin inventar.

La idea es simple:

1. Partes tus documentos en *chunks*
2. Creas embeddings
3. Buscas por similitud
4. Metes el contexto recuperado en el prompt del modelo

## El patrón mínimo (que casi nadie respeta)

Tu pipeline debería tener, como mínimo:

- **Chunking** con solapamiento (overlap)
- **Normalización** (limpieza y deduplicación)
- **Top-k** + umbral de similitud
- **Citas / trazabilidad**: qué chunk apoyó qué respuesta
- **Fallback** cuando no hay evidencia suficiente

### Errores típicos

- Chunks demasiado grandes (se pierde precisión)
- Meter 20 chunks “por si acaso” (aumenta alucinaciones)
- No separar *pregunta* de *instrucciones*
- No evaluar (sin métricas no hay mejora)

## Qué veremos en el curso

Vamos a montar un RAG práctico (sin humo):

- Persistencia de embeddings
- Filtros por categoría / fecha
- Logging de consultas y resultados
- Evaluación automática de respuestas
- Costes y optimización

Si tu producto depende de información real, **RAG no es opcional**.

MD
  ],
  [
    'date' => date('Y-m-d H:i:s'),
    'title' => 'Agentes de IA: cuándo usarlos (y cuándo NO) en proyectos reales',
    'category' => 'Agentes',
    'content' => <<<MD
Un agente de IA es útil cuando necesitas **planificación + herramientas**.  
Pero meter agentes “porque sí” suele romper producto y presupuesto.

## Cuándo SÍ usar agentes

- Automatizar flujos multi-paso (ej. “leer → decidir → ejecutar → reportar”)
- Orquestar herramientas (API, DB, archivos, navegador)
- Operaciones internas (soporte, tickets, tareas repetitivas)
- Pipelines donde el modelo llama funciones con control

## Cuándo NO

- Si un prompt bien diseñado resuelve el 80%
- Si no tienes logs, límites y validación
- Si no puedes tolerar errores (sin *guardrails*)
- Si no defines un *budget* (tokens + tiempo + reintentos)

## El patrón que enseñamos

Un agente útil en producción suele tener:

- **Tooling** explícito (qué puede hacer y qué no)
- **Memoria** limitada y revisable
- **Política de reintentos** (máximo 1–2)
- **Validación** (esquemas, reglas, checks)
- **Observabilidad** (logs, métricas, trazas)

En el curso montamos agentes con control, para que no dependan de magia.

MD
  ],
];

// =========================
// INSERT
// =========================
$inserted = 0;
$skipped = 0;

foreach ($posts as $p) {
  $existsStmt->execute([':t' => $p['title']]);
  $row = $existsStmt->fetch();

  if ($row) {
    $skipped++;
    continue;
  }

  $insStmt->execute([
    ':date' => $p['date'],
    ':title' => $p['title'],
    ':content' => $p['content'],
    ':category' => $p['category'],
  ]);
  $inserted++;
}

echo "OK\n";
echo "Inserted: {$inserted}\n";
echo "Skipped (already existed): {$skipped}\n";
echo "DB: {$DB_FILE}\n";
echo "\nRecuerda borrar seed_posts.php cuando termines.\n";

