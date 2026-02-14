<?php
// markdown.php
// Minimal, safe-ish Markdown -> HTML renderer (no external deps).
// Supports: headings (#..######), paragraphs, bold/italic, inline code,
// fenced code blocks (```), links, unordered/ordered lists, blockquotes, hr.
// Also sanitizes links and escapes HTML by default.

declare(strict_types=1);

function md_to_html(string $md): string {
  $md = normalize_newlines($md);
  $lines = explode("\n", $md);

  $html = '';
  $inCode = false;
  $codeLang = '';
  $codeBuf = '';

  $inUl = false;
  $inOl = false;
  $inQuote = false;

  $paraBuf = '';

  $flush_para = function() use (&$html, &$paraBuf) {
    $text = trim($paraBuf);
    if ($text === '') { $paraBuf = ''; return; }
    $html .= '<p>' . md_inline($text) . "</p>\n";
    $paraBuf = '';
  };

  $close_lists = function() use (&$html, &$inUl, &$inOl) {
    if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
    if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
  };

  $close_quote = function() use (&$html, &$inQuote) {
    if ($inQuote) { $html .= "</blockquote>\n"; $inQuote = false; }
  };

  foreach ($lines as $rawLine) {
    $line = rtrim($rawLine, " \t");

    // Fenced code blocks
    if (preg_match('/^\s*```(\w+)?\s*$/', $line, $m)) {
      $flush_para();
      $close_lists();
      $close_quote();

      if (!$inCode) {
        $inCode = true;
        $codeLang = isset($m[1]) ? strtolower((string)$m[1]) : '';
        $codeBuf = '';
      } else {
        // close
        $inCode = false;
        $cls = $codeLang !== '' ? ' class="language-' . h($codeLang) . '"' : '';
        $html .= "<pre><code{$cls}>" . h($codeBuf) . "</code></pre>\n";
        $codeLang = '';
        $codeBuf = '';
      }
      continue;
    }

    if ($inCode) {
      $codeBuf .= $rawLine . "\n";
      continue;
    }

    // Horizontal rule
    if (preg_match('/^\s*(---|\*\*\*|___)\s*$/', $line)) {
      $flush_para();
      $close_lists();
      $close_quote();
      $html .= "<hr>\n";
      continue;
    }

    // Empty line => flush paragraph and close quote block if any
    if (trim($line) === '') {
      $flush_para();
      $close_lists();
      $close_quote();
      continue;
    }

    // Blockquote
    if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
      $flush_para();
      $close_lists();
      if (!$inQuote) { $html .= "<blockquote>\n"; $inQuote = true; }
      $html .= '<p>' . md_inline(trim((string)$m[1])) . "</p>\n";
      continue;
    } else {
      $close_quote();
    }

    // Headings
    if (preg_match('/^(#{1,6})\s+(.+?)\s*$/', $line, $m)) {
      $flush_para();
      $close_lists();
      $level = strlen($m[1]);
      $text = md_inline(trim((string)$m[2]));
      $html .= "<h{$level}>{$text}</h{$level}>\n";
      continue;
    }

    // UL item
    if (preg_match('/^\s*[-*+]\s+(.+?)\s*$/', $line, $m)) {
      $flush_para();
      if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
      if (!$inUl) { $html .= "<ul>\n"; $inUl = true; }
      $html .= '<li>' . md_inline(trim((string)$m[1])) . "</li>\n";
      continue;
    }

    // OL item
    if (preg_match('/^\s*\d+\.\s+(.+?)\s*$/', $line, $m)) {
      $flush_para();
      if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
      if (!$inOl) { $html .= "<ol>\n"; $inOl = true; }
      $html .= '<li>' . md_inline(trim((string)$m[1])) . "</li>\n";
      continue;
    }

    // Normal text => paragraph buffer (supports multi-line paragraphs)
    // Ensure lists are closed when returning to paragraph
    if ($inUl || $inOl) {
      $close_lists();
    }

    $paraBuf .= ($paraBuf === '' ? '' : ' ') . trim($line);
  }

  // Final flush/close
  $flush_para();
  $close_lists();
  $close_quote();

  return $html;
}

function md_inline(string $text): string {
  // Escape HTML first (prevents raw HTML injection)
  $s = h($text);

  // Inline code: `code`
  $s = preg_replace_callback('/`([^`]+)`/', function($m){
    return '<code>' . h($m[1]) . '</code>';
  }, $s);

  // Links: [text](url)
  $s = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function($m){
    $label = $m[1];
    $url = $m[2];
    $safe = sanitize_url($url);
    if ($safe === '') return $label; // drop unsafe urls, keep text
    return '<a href="' . h($safe) . '" rel="nofollow noopener" target="_blank">' . $label . '</a>';
  }, $s);

  // Bold: **text**
  $s = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $s);

  // Italic: *text* (simple)
  $s = preg_replace('/(^|[^\*])\*([^\*]+)\*(?!\*)/', '$1<em>$2</em>', $s);

  return $s;
}

function md_to_text(string $md): string {
  // For excerpts: convert to HTML then strip tags
  $html = md_to_html($md);
  $txt = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
  return $txt;
}

function sanitize_url(string $url): string {
  $u = trim($url);
  if ($u === '') return '';

  // Allow relative URLs
  if (str_starts_with($u, '/')) return $u;

  // Only allow http(s)
  if (preg_match('~^https?://~i', $u) !== 1) return '';

  // Basic validation
  if (filter_var($u, FILTER_VALIDATE_URL) === false) return '';

  return $u;
}

function normalize_newlines(string $s): string {
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  return $s;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

