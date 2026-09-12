<?php
// Minimal pure-PHP PDF writer (no libraries needed): A4, Helvetica.
// Used for direct "Download as PDF" (bypasses the print dialog).
// Supports text lines + bordered tables (with header repeat on page breaks).
class MiniPDF {
    private $pages = [];
    private $cur = [];
    private $y = 800;
    private $footer = '';
    const LEFT = 50; const TOP = 800; const BOTTOM = 50; const PW = 595;

    public function setFooter(string $text): void {
        $this->footer = $text;
    }

    public function addLine(string $text, int $size = 11, bool $bold = false, int $gap = 0): void {
        $h = $size * 1.35;
        foreach ($this->wrap($text, $size) as $w) {
            if ($this->y < self::BOTTOM) {
                $this->pages[] = $this->cur; $this->cur = []; $this->y = self::TOP;
            }
            $this->cur[] = ['t', $size, $bold, self::LEFT, $this->y, $w];
            $this->y -= $h;
        }
        $this->y -= $gap;
    }

    public function blank(int $n = 1): void {
        for ($i = 0; $i < $n; $i++) $this->addLine(' ');
    }

    // Bordered table. $widths must sum to <= 495. Header repeats after page breaks.
    public function table(array $headers, array $widths, array $rows, int $size = 9): void {
        $rowH = 16;
        $total = array_sum($widths);
        $avail = self::PW - self::LEFT * 2;
        if ($total > $avail) { $scale = $avail / $total; foreach ($widths as &$w) $w = $w * $scale; unset($w); }
        $drawRow = function (array $cells, bool $bold, $fill) use ($widths, $rowH, $size) {
            if ($this->y - $rowH < self::BOTTOM) {
                $this->pages[] = $this->cur; $this->cur = []; $this->y = self::TOP;
            }
            $x = self::LEFT; $yTop = $this->y;
            foreach ($cells as $i => $c) {
                $w = $widths[$i];
                $this->cur[] = ['r', $x, $yTop - $rowH, $w, $rowH, $fill];
                $maxChars = max(4, (int)(($w - 6) / ($size * 0.52)));
                $clen = function_exists('mb_strlen') ? mb_strlen($c, 'UTF-8') : strlen($c);
                if ($clen > $maxChars) {
                    $c = (function_exists('mb_substr') ? mb_substr($c, 0, $maxChars - 3, 'UTF-8') : substr($c, 0, $maxChars - 3)) . '...';
                }
                $this->cur[] = ['t', $size, $bold, $x + 3, $yTop - 12, $c];
                $x += $w;
            }
            $this->y -= $rowH;
        };
        // caller handles repeat: draw header, then rows (re-draw header after breaks)
        $drawRow($headers, true, 0.9);
        $headerCopy = [$headers, true, 0.9];
        $startCount = count($this->pages);
        foreach ($rows as $r) {
            $before = count($this->pages);
            $drawRow(array_values($r), false, null);
            if (count($this->pages) > $before) {
                // page broke mid-table: re-draw header at top of new page.
                // (rebuild: move last row's records after a fresh header)
                $lastRow = array_splice($this->cur, -count($widths) * 2);
                $drawRow($headerCopy[0], true, 0.9);
                foreach ($lastRow as $rec) $this->cur[] = $rec;
            }
        }
    }

    private function wrap(string $text, int $size): array {
        $max = $size >= 14 ? 55 : 88;
        $words = preg_split('/\s+/', trim($text));
        $lines = []; $line = '';
        foreach ($words as $w) {
            if ($line !== '' && strlen($line . ' ' . $w) > $max) { $lines[] = $line; $line = $w; }
            else $line = $line === '' ? $w : $line . ' ' . $w;
        }
        if ($line !== '' || !$lines) $lines[] = $line;
        return $lines;
    }

    public static function esc(string $s): string {
        $s = @iconv('UTF-8', 'WINDOWS-1252//TRANSLIT//IGNORE', $s);
        if ($s === false) $s = '';
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i]; $o = ord($c);
            if ($c === '(' || $c === ')' || $c === '\\') $out .= '\\' . $c;
            elseif ($o < 32 || $o > 126) $out .= sprintf('\\%03o', $o);
            else $out .= $c;
        }
        return $out;
    }

    public function render(): string {
        $this->pages[] = $this->cur;
        $contents = [];
        $totalPages = count($this->pages);
        foreach ($this->pages as $pi => $page) {
            $s = '';
            foreach ($page as $rec) {
                if ($rec[0] === 'r') {
                    list(, $x, $y, $w, $h, $fill) = $rec;
                    if ($fill !== null) $s .= sprintf("%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f\n", $fill, $fill, $fill, $x, $y, $w, $h);
                    $s .= sprintf("0 0 0 rg %.2f %.2f %.2f %.2f re S\n", $x, $y, $w, $h);
                } else {
                    list(, $size, $bold, $x, $y, $text) = $rec;
                    $f = $bold ? 'F2' : 'F1';
                    $s .= 'BT /' . $f . ' ' . $size . ' Tf ' . $x . ' ' . $y . ' Td (' . self::esc($text) . ") Tj ET\n";
                }
            }
            if ($this->footer !== '') {
                $s .= 'BT /F1 8 Tf ' . self::LEFT . ' 30 Td (' . self::esc($this->footer) . ") Tj ET\n";
                $s .= 'BT /F1 8 Tf 470 30 Td (Page ' . ($pi + 1) . ' of ' . $totalPages . ") Tj ET\n";
            }
            $contents[] = $s;
        }
        $n = count($contents);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (3 + $i * 2) . ' 0 R';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $fontBase = 3 + $n * 2;
        for ($i = 0; $i < $n; $i++) {
            $pnum = 3 + $i * 2; $cnum = $pnum + 1;
            $objs[$pnum] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontBase . ' 0 R /F2 ' . ($fontBase + 1) . ' 0 R >> >> /Contents ' . $cnum . ' 0 R >>';
            $objs[$cnum] = "<< /Length " . strlen($contents[$i]) . " >>\nstream\n" . $contents[$i] . 'endstream';
        }
        $objs[$fontBase] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objs[$fontBase + 1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $pdf = "%PDF-1.4\n";
        $off = []; $max = $fontBase + 1;
        for ($i = 1; $i <= $max; $i++) { $off[$i] = strlen($pdf); $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($max + 1) . "\n" . "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        $pdf .= 'trailer' . "\n" . '<< /Size ' . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}
