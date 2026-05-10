<?php
declare(strict_types=1);

namespace MIS;

/**
 * Tiny Markdown→HTML converter for in-app docs.
 * Targeted at the subset used in docs/guide/: ATX headings, paragraphs,
 * fenced code, inline code, bold / italic, links, ordered + unordered lists,
 * GFM tables, blockquotes, and `---` rules.
 *
 * Not a general-purpose Markdown engine. Avoids any composer dependency.
 */
final class Markdown
{
    public static function render(string $md): string
    {
        // Normalize line endings, strip BOM
        $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        $lines = explode("\n", $md);
        $out   = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            // --- fenced code block ---
            if (preg_match('/^```\s*([a-zA-Z0-9_-]*)\s*$/', $line, $m)) {
                $lang = $m[1] ?? '';
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i++];
                }
                $i++; // skip closing fence
                $cls = $lang !== '' ? ' class="language-' . self::esc($lang) . '"' : '';
                $out[] = '<pre><code' . $cls . '>' . self::esc(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            // --- heading ---
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $out[] = "<h$level>" . self::inline($m[2]) . "</h$level>";
                $i++; continue;
            }

            // --- horizontal rule ---
            if (preg_match('/^\s*(-\s*){3,}\s*$/', $line) || preg_match('/^\s*(\*\s*){3,}\s*$/', $line)) {
                $out[] = '<hr>';
                $i++; continue;
            }

            // --- table (GFM) ---
            // Header row + separator row + body rows; require | in header
            if (str_contains($line, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{3,}.*$/', $lines[$i + 1])) {
                [$tableHtml, $consumed] = self::parseTable($lines, $i);
                if ($tableHtml !== null) {
                    $out[] = $tableHtml;
                    $i += $consumed;
                    continue;
                }
            }

            // --- blockquote ---
            if (preg_match('/^>\s?(.*)$/', $line)) {
                $buf = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $m)) {
                    $buf[] = $m[1];
                    $i++;
                }
                $inner = self::render(implode("\n", $buf));
                $out[] = "<blockquote>$inner</blockquote>";
                continue;
            }

            // --- list (ordered or unordered) ---
            if (preg_match('/^(\s*)([-*+]|\d+\.)\s+(.*)$/', $line, $m)) {
                [$listHtml, $consumed] = self::parseList($lines, $i);
                $out[] = $listHtml;
                $i += $consumed;
                continue;
            }

            // --- blank line ---
            if (trim($line) === '') {
                $i++; continue;
            }

            // --- paragraph (collect contiguous non-blank, non-special lines) ---
            $para = [];
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') break;
                if (preg_match('/^(#{1,6})\s+/', $l)) break;
                if (preg_match('/^```/', $l)) break;
                if (preg_match('/^>\s?/', $l)) break;
                if (preg_match('/^(\s*)([-*+]|\d+\.)\s+/', $l)) break;
                if (preg_match('/^\s*(-\s*){3,}\s*$/', $l) || preg_match('/^\s*(\*\s*){3,}\s*$/', $l)) break;
                $para[] = $l;
                $i++;
            }
            if ($para) {
                $text = implode(' ', array_map('trim', $para));
                $out[] = '<p>' . self::inline($text) . '</p>';
            }
        }
        return implode("\n", $out);
    }

    /** Parse a list block starting at $start. Returns [html, lines_consumed]. */
    private static function parseList(array $lines, int $start): array
    {
        $i = $start;
        $n = count($lines);
        $items = [];
        $first = $lines[$i];
        $isOrdered = (bool) preg_match('/^\s*\d+\.\s+/', $first);
        // Match list items at the *same* indent level (basic — no nesting)
        $marker = $isOrdered ? '\d+\.' : '[-*+]';
        $itemRe = '/^(\s*)(' . $marker . ')\s+(.*)$/';

        while ($i < $n && preg_match($itemRe, $lines[$i], $m)) {
            $body = [$m[3]];
            $i++;
            // Continuation lines: blank or further indented (≥ 2 spaces) belong to current item
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    // peek — if next line is another list item, list continues; otherwise list ends
                    if ($i + 1 < $n && preg_match($itemRe, $lines[$i + 1])) {
                        $i++; continue;
                    } else {
                        break;
                    }
                }
                if (preg_match($itemRe, $l)) break;
                if (preg_match('/^(\s{2,}|\t)/', $l)) {
                    $body[] = trim($l);
                    $i++; continue;
                }
                break;
            }
            $items[] = self::inline(implode(' ', $body));
        }
        $tag = $isOrdered ? 'ol' : 'ul';
        $html = "<$tag>\n" . implode("\n", array_map(fn($x) => "  <li>$x</li>", $items)) . "\n</$tag>";
        return [$html, $i - $start];
    }

    /** Parse a GFM table starting at $start. Returns [html|null, lines_consumed]. */
    private static function parseTable(array $lines, int $start): array
    {
        $header = trim($lines[$start], " \t|");
        $sep    = trim($lines[$start + 1] ?? '', " \t|");
        $cols = array_map('trim', explode('|', $header));
        if (!$cols) return [null, 0];
        $aligns = array_map(function ($s) {
            $s = trim($s);
            $left  = str_starts_with($s, ':');
            $right = str_ends_with($s, ':');
            if ($left && $right) return 'center';
            if ($right) return 'right';
            if ($left)  return 'left';
            return null;
        }, explode('|', $sep));
        $i = $start + 2;
        $body = [];
        while ($i < count($lines)) {
            $l = $lines[$i];
            if (!str_contains($l, '|') || trim($l) === '') break;
            $row = trim($l, " \t|");
            $body[] = array_map('trim', explode('|', $row));
            $i++;
        }
        $html = '<table>' . "\n" . '<thead><tr>';
        foreach ($cols as $idx => $c) {
            $a = $aligns[$idx] ?? null;
            $html .= '<th' . ($a ? " style=\"text-align:$a\"" : '') . '>' . self::inline($c) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";
        foreach ($body as $row) {
            $html .= '<tr>';
            foreach ($row as $idx => $cell) {
                $a = $aligns[$idx] ?? null;
                $html .= '<td' . ($a ? " style=\"text-align:$a\"" : '') . '>' . self::inline($cell) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody></table>";
        return [$html, $i - $start];
    }

    /** Inline span: code, links, bold, italic, escapes, autolinks. */
    private static function inline(string $s): string
    {
        // Protect inline code first
        $codes = [];
        $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $idx = count($codes);
            $codes[$idx] = '<code>' . self::esc($m[1]) . '</code>';
            return "\x01CODE$idx\x02";
        }, $s);

        // Escape the rest
        $s = self::esc($s);

        // Links [text](url)
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) {
            $href = $m[2];
            // Allow http(s), relative paths, and mailto. Block javascript: explicitly.
            if (preg_match('/^javascript:/i', $href)) {
                return self::esc($m[1]);
            }
            return '<a href="' . self::esc($href) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }, $s);

        // Bold then italic
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);

        // Restore inline code
        $s = preg_replace_callback('/\x01CODE(\d+)\x02/', fn($m) => $codes[(int) $m[1]] ?? '', $s);
        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
