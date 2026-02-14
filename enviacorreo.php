<?php
// enviacorreo.php
// Sends contact form via SMTP (IONOS) using STARTTLS.
// Then shows a thank-you screen and redirects to index.php after 5 seconds.

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

// =========================
// CONFIG
// =========================
// IMPORTANTE: En producción, mover credenciales a variables de entorno
// para no exponer contraseñas en el código fuente.
$SMTP_HOST = 'smtp.ionos.es';
$SMTP_PORT = 587;                 // STARTTLS
$SMTP_USER = 'python@jocarsa.com';
$SMTP_PASS = 'TAME123$';

$TO_EMAIL  = 'jocarsa2@gmail.com'; // <-- change if you want to receive in another inbox
$FROM_EMAIL = 'python@jocarsa.com';
$FROM_NAME  = 'CURSOIA.ES';
$SUBJECT_PREFIX = '[CURSOIA.ES]';

// =========================
// INPUT
// =========================
$nombre  = trim((string)($_POST['nombre'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$mensaje = trim((string)($_POST['mensaje'] ?? ''));

$errors = [];
if ($nombre === '') $errors[] = 'Falta el nombre.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
if ($mensaje === '') $errors[] = 'Falta el mensaje.';

if ($errors) {
  http_response_code(400);
  echo render_page('Error', 'No se pudo enviar el mensaje.', implode(' ', array_map('h', $errors)), false);
  exit;
}

// Basic hardening
$nombre_safe = preg_replace('/[\r\n]+/', ' ', $nombre) ?? $nombre;
$email_safe  = preg_replace('/[\r\n]+/', '', $email) ?? $email;

// =========================
// BUILD EMAIL (MIME, UTF-8)
// =========================
$subject = $SUBJECT_PREFIX . ' Nuevo contacto de ' . $nombre_safe;

$bodyText =
"Nuevo mensaje desde el formulario:\n\n" .
"Nombre: {$nombre_safe}\n" .
"Email: {$email_safe}\n\n" .
"Mensaje:\n{$mensaje}\n";

$boundary = 'bnd_' . bin2hex(random_bytes(12));
$date = gmdate('D, d M Y H:i:s') . ' +0000';
$messageId = '<' . bin2hex(random_bytes(16)) . '@cursoia.es>';

$headers = [];
$headers[] = 'Date: ' . $date;
$headers[] = 'Message-ID: ' . $messageId;
$headers[] = 'From: ' . encodeHeaderName($FROM_NAME) . " <{$FROM_EMAIL}>";
$headers[] = "To: <{$TO_EMAIL}>";
$headers[] = 'Reply-To: ' . encodeHeaderName($nombre_safe) . " <{$email_safe}>";
$headers[] = 'Subject: ' . encodeHeader($subject);
$headers[] = 'MIME-Version: 1.0';
$headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

$mime  = "This is a multi-part message in MIME format.\r\n";
$mime .= "--{$boundary}\r\n";
$mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
$mime .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$mime .= $bodyText . "\r\n";
$mime .= "--{$boundary}--\r\n";

$rawEmail = implode("\r\n", $headers) . "\r\n\r\n" . $mime;

// =========================
// SEND VIA SMTP
// =========================
try {
  smtp_send_starttls(
    host: $SMTP_HOST,
    port: $SMTP_PORT,
    username: $SMTP_USER,
    password: $SMTP_PASS,
    mailFrom: $FROM_EMAIL,
    rcptTo: $TO_EMAIL,
    data: $rawEmail
  );

  echo render_page('Gracias', '¡Gracias por tu mensaje!', 'Te responderemos lo antes posible. Redirigiendo…', true);
} catch (Throwable $e) {
  http_response_code(500);
  echo render_page('Error', 'No se pudo enviar el mensaje.', h($e->getMessage()), false);
}

// =========================
// UI
// =========================
function render_page(string $title, string $h1, string $p, bool $redirect): string {
  $meta = $redirect ? '<meta http-equiv="refresh" content="5;url=index.php">' : '';
  $js   = $redirect ? '<script>setTimeout(()=>{window.location.href="index.php";}, 5000);</script>' : '';
  return '<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  ' . $meta . '
  <title>' . h($title) . '</title>
  <style>
    :root{--bg:#fff;--text:#1b1f23;--muted:#5b6670;--orange:#f39a1a;}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;background:var(--bg);color:var(--text);}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px;}
    .card{width:min(720px,100%);border:1px solid rgba(0,0,0,.10);padding:28px;border-radius:14px}
    h1{margin:0 0 8px;font-size:28px}
    p{margin:0 0 14px;color:var(--muted);line-height:1.6}
    .hint{font-size:13px}
    a.btn{display:inline-block;margin-top:10px;background:var(--orange);color:#fff;padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:800}
  </style>
</head>
<body>
  <main class="wrap">
    <section class="card" aria-label="Resultado del envío">
      <h1>' . h($h1) . '</h1>
      <p>' . $p . '</p>
      <p class="hint">Si no redirige automáticamente, pulsa el botón.</p>
      <a class="btn" href="index.php">Volver a la página principal</a>
    </section>
  </main>
  ' . $js . '
</body>
</html>';
}

// =========================
// SMTP (STARTTLS) minimal client
// =========================
function smtp_send_starttls(
  string $host,
  int $port,
  string $username,
  string $password,
  string $mailFrom,
  string $rcptTo,
  string $data
): void {
  $fp = @stream_socket_client(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT
  );

  if (!$fp) {
    throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
  }

  stream_set_timeout($fp, 15);

  $greeting = smtp_read($fp);
  if (!str_starts_with($greeting, '220')) {
    throw new RuntimeException("SMTP greeting error: {$greeting}");
  }

  smtp_cmd($fp, "EHLO cursoia.es", [250]);

  // STARTTLS
  smtp_cmd($fp, "STARTTLS", [220]);

  $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
  if ($cryptoOk !== true) {
    throw new RuntimeException("STARTTLS failed (could not enable crypto).");
  }

  // EHLO again after TLS
  smtp_cmd($fp, "EHLO cursoia.es", [250]);

  // AUTH LOGIN
  smtp_cmd($fp, "AUTH LOGIN", [334]);
  smtp_cmd($fp, base64_encode($username), [334]);
  smtp_cmd($fp, base64_encode($password), [235]);

  // Envelope
  smtp_cmd($fp, "MAIL FROM:<{$mailFrom}>", [250]);
  smtp_cmd($fp, "RCPT TO:<{$rcptTo}>", [250, 251]);

  // DATA
  smtp_cmd($fp, "DATA", [354]);

  // Dot-stuffing + ensure CRLF line endings
  $lines = preg_split("/\r\n|\n|\r/", $data);
  $safe = '';
  foreach ($lines as $line) {
    if ($line !== '' && $line[0] === '.') $line = '.' . $line;
    $safe .= $line . "\r\n";
  }
  $safe .= ".\r\n";

  fwrite($fp, $safe);
  $resp = smtp_read($fp);
  if (!str_starts_with($resp, '250')) {
    throw new RuntimeException("SMTP DATA not accepted: {$resp}");
  }

  smtp_cmd($fp, "QUIT", [221]);
  fclose($fp);
}

function smtp_cmd($fp, string $cmd, array $okCodes): string {
  fwrite($fp, $cmd . "\r\n");
  $resp = smtp_read($fp);
  $code = (int)substr($resp, 0, 3);
  if (!in_array($code, $okCodes, true)) {
    throw new RuntimeException("SMTP command failed: {$cmd} => {$resp}");
  }
  return $resp;
}

function smtp_read($fp): string {
  $out = '';
  while (!feof($fp)) {
    $line = fgets($fp, 4096);
    if ($line === false) break;
    $out .= $line;
    // multi-line replies have "xyz-" until the final "xyz "
    if (preg_match('/^\d{3}\s/', $line)) break;
  }
  return trim($out);
}

// =========================
// Encoding helpers (UTF-8 headers)
// =========================
function encodeHeader(string $s): string {
  // RFC 2047 encoded-word
  return '=?UTF-8?B?' . base64_encode($s) . '?=';
}
function encodeHeaderName(string $s): string {
  // only encode if non-ascii
  return preg_match('/[^\x20-\x7E]/', $s) ? encodeHeader($s) : $s;
}
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

