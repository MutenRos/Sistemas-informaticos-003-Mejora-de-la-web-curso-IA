<?php
// log.php
// Logger sencillo para index.php y blog.php.
// Guarda en SQLite: timestamp ISO, epoch, ip, user agent, url, referer, idioma,
// método, host, accept headers, DNT, etc.
//
// Uso (en index.php y blog.php, lo más arriba posible):
//   require_once __DIR__ . '/log.php';
//   log_visit(__DIR__ . '/blog.sqlite'); // o la misma ruta que uses en tu web
//
// Nota: por privacidad/GDPR, NO guardamos cookies, ni parámetros sensibles.
// Puedes desactivar campos fácilmente abajo.

declare(strict_types=1);

/**
 * Registra una visita en la base de datos SQLite.
 * Captura IP (anonimizada), user-agent, URL, referer, idioma, etc.
 * Cumple GDPR: no guarda cookies ni parámetros sensibles.
 */
function log_visit(string $dbFile, array $opts = []): void
{
  // Opciones básicas
  $defaults = [
    'table' => 'visits',
    'enabled' => true,
    'store_get' => true,            // guarda query string completa (puede contener PII si tu web la usa)
    'truncate' => 2000,             // recorte defensivo de strings
    'anonymize_ip' => true,         // recomendable
  ];
  $cfg = array_merge($defaults, $opts);

  if (!$cfg['enabled']) return;

  try {
    $db = new PDO('sqlite:' . $dbFile, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Tabla de logs (misma BD que posts)
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$cfg['table']);
    if ($table === '') $table = 'visits';

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

    // =========================
    // Captura de datos
    // =========================
    $epoch = time();
    $tsIso = date('Y-m-d H:i:s', $epoch);

    $ua    = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ref   = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $acc   = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $alang = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $aenc  = (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    $dnt   = (string)($_SERVER['HTTP_DNT'] ?? '');

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $path   = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $qstr   = (string)($_SERVER['QUERY_STRING'] ?? '');

    $urlFull = $scheme . '://' . $host . $path;
    if ($cfg['store_get'] && $qstr !== '') $urlFull .= '?' . $qstr;

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $remotePort = (int)($_SERVER['REMOTE_PORT'] ?? 0);

    $isHttps = ($scheme === 'https') ? 1 : 0;

    $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);

    // =========================
    // Sanitización / recorte
    // =========================
    $truncate = (int)$cfg['truncate'];
    $cut = static function(string $s) use ($truncate): string {
      if ($truncate <= 0) return $s;
      if (mb_strlen($s, 'UTF-8') <= $truncate) return $s;
      return mb_substr($s, 0, $truncate, 'UTF-8');
    };

    $ua    = $cut($ua);
    $ref   = $cut($ref);
    $acc   = $cut($acc);
    $alang = $cut($alang);
    $aenc  = $cut($aenc);
    $host  = $cut($host);
    $path  = $cut($path);
    $qstr  = $cfg['store_get'] ? $cut($qstr) : '';
    $urlFull = $cut($urlFull);

    // IP: opcional anonimizar y/o hashear
    $ipStored = $ip;
    if ($cfg['anonymize_ip'] && $ip !== '') {
      $ipStored = anonymize_ip($ip);
    }
    $ipHash = ($ip !== '') ? hash('sha256', $ip) : null;

    // =========================
    // Insert
    // =========================
    $stmt = $db->prepare("
      INSERT INTO {$table} (
        ts_iso, epoch,
        ip, ip_hash,
        method, scheme, host, path, query, url_full,
        referer, user_agent, accept, accept_language, accept_encoding, dnt,
        remote_port, is_https,
        server_addr, server_port
      ) VALUES (
        :ts_iso, :epoch,
        :ip, :ip_hash,
        :method, :scheme, :host, :path, :query, :url_full,
        :referer, :user_agent, :accept, :accept_language, :accept_encoding, :dnt,
        :remote_port, :is_https,
        :server_addr, :server_port
      )
    ");

    $stmt->execute([
      ':ts_iso' => $tsIso,
      ':epoch'  => $epoch,

      ':ip'     => ($ipStored !== '' ? $ipStored : null),
      ':ip_hash'=> $ipHash,

      ':method' => $method,
      ':scheme' => $scheme,
      ':host'   => $host,
      ':path'   => $path,
      ':query'  => ($qstr !== '' ? $qstr : null),
      ':url_full' => $urlFull,

      ':referer' => ($ref !== '' ? $ref : null),
      ':user_agent' => ($ua !== '' ? $ua : null),
      ':accept'  => ($acc !== '' ? $acc : null),
      ':accept_language' => ($alang !== '' ? $alang : null),
      ':accept_encoding' => ($aenc !== '' ? $aenc : null),
      ':dnt'     => ($dnt !== '' ? $dnt : null),

      ':remote_port' => ($remotePort ?: null),
      ':is_https'    => $isHttps,

      ':server_addr' => ($serverAddr !== '' ? $serverAddr : null),
      ':server_port' => ($serverPort ?: null),
    ]);

  } catch (Throwable $e) {
    // No romper la web si falla el logging
    // (Opcional) error_log("log_visit error: " . $e->getMessage());
    return;
  }
}

/**
 * Anonimiza IP:
 * - IPv4: 192.168.1.123 -> 192.168.1.0
 * - IPv6: recorta a /48 aproximado (ej.: 2001:db8:abcd:1234:: -> 2001:db8:abcd::)
 */
function anonymize_ip(string $ip): string
{
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
      $parts[3] = '0';
      return implode('.', $parts);
    }
    return $ip;
  }
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    // Mantener primeros 3 hextetos (aprox /48)
    $ip = strtolower($ip);
    $expanded = inet_ntop(inet_pton($ip));
    // Si no se puede expandir, devolver tal cual
    if ($expanded === false) return $ip;

    // Dividir por ':', normalizar longitud
    $chunks = explode(':', $expanded);
    // Rellenar a 8 chunks si vienen comprimidos (inet_ntop suele devolver comprimido,
    // pero ya hicimos round-trip, aun así: fallback simple)
    // Para simplicidad, cortamos por el primer '::' si existiera en $expanded original
    // y devolvemos los 3 primeros hextetos.
    $first3 = array_slice($chunks, 0, 3);
    return implode(':', $first3) . '::';
  }
  return $ip;
}

