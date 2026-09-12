<?php
// Minimal pure-PHP PDF writer (no libraries needed): A4, Helvetica.
// Used for direct "Download as PDF" (bypasses the print dialog).
class MiniPDF {
    private $pages = [];
    private $cur = [];
    private $y = 800;
    const LEFT = 50; const TOP = 800; const BOTTOM = 50;

    public function addLine(string $text, int $size = 11, bool $bold = false, int $gap = 0): void {
        $h = $size * 1.35;
        foreach ($this->wrap($text, $size) as $w) {
            if ($this->y < self::BOTTOM) {
                $this->pages[] = $this->cur; $this->cur = []; $this->y = self::TOP;
            }
            $this->cur[] = [$size, $bold, $w];
            $this->y -= $h;
        }
        $this->y -= $gap;
    }

    public function blank(int $n = 1): void {
        for ($i = 0; $i < $n; $i++) $this->addLine(' ');
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
        foreach ($this->pages as $page) {
            $y = self::TOP; $s = '';
            foreach ($page as $ln) {
                list($size, $bold, $text) = $ln;
                $f = $bold ? 'F2' : 'F1';
                $s .= 'BT /' . $f . ' ' . $size . ' Tf ' . self::LEFT . ' ' . $y . ' Td (' . self::esc($text) . ") Tj ET\n";
                $y -= $size * 1.35;
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
