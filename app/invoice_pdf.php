<?php
declare(strict_types=1);

// Invoice as a PDF (no libraries): A4, DejaVu Sans embedded as a Type0/Identity-H font so Cyrillic works,
// and the e-mail copy of a new invoice for the contract author.

class PdfFont
{
    public array $glyphs = [];   // gid => width (1/1000 em), only for glyphs that were used
    private string $data;
    private array $t = [];
    private int $upm = 1000;
    private int $nHm = 0;
    private int $cmapOff = 0;
    public array $bbox = [0, 0, 1000, 1000];
    public int $ascent = 900;
    public int $descent = -200;

    public function __construct(public string $file)
    {
        $this->data = (string)file_get_contents($file);
        $n = unpack('n', substr($this->data, 4, 2))[1];
        for ($i = 0; $i < $n; $i++) {
            $r = substr($this->data, 12 + $i * 16, 16);
            $this->t[substr($r, 0, 4)] = [unpack('N', substr($r, 8, 4))[1], unpack('N', substr($r, 12, 4))[1]];
        }
        $head = $this->t['head'][0];
        $this->upm = unpack('n', substr($this->data, $head + 18, 2))[1];
        $b = array_values(unpack('n4', substr($this->data, $head + 36, 8)));
        $this->bbox = array_map(fn($v) => (int)round(($v >= 32768 ? $v - 65536 : $v) * 1000 / $this->upm), $b);
        $hhea = $this->t['hhea'][0];
        $a = unpack('n', substr($this->data, $hhea + 4, 2))[1];
        $d = unpack('n', substr($this->data, $hhea + 6, 2))[1];
        $this->ascent = (int)round(($a >= 32768 ? $a - 65536 : $a) * 1000 / $this->upm);
        $this->descent = (int)round(($d >= 32768 ? $d - 65536 : $d) * 1000 / $this->upm);
        $this->nHm = unpack('n', substr($this->data, $hhea + 34, 2))[1];
        // cmap: Windows Unicode BMP (3,1), format 4
        $cm = $this->t['cmap'][0];
        $cnt = unpack('n', substr($this->data, $cm + 2, 2))[1];
        for ($i = 0; $i < $cnt; $i++) {
            $e = unpack('nplat/nenc/Noff', substr($this->data, $cm + 4 + $i * 8, 8));
            if ($e['plat'] === 3 && $e['enc'] === 1) {
                $this->cmapOff = $cm + $e['off'];
            }
        }
    }

    public function gid(int $cp): int
    {
        if (!$this->cmapOff || $cp > 0xFFFF) {
            return 0;
        }
        $o = $this->cmapOff;
        $segX2 = unpack('n', substr($this->data, $o + 6, 2))[1];
        $seg = $segX2 / 2;
        $ends = array_values(unpack('n' . $seg, substr($this->data, $o + 14, $segX2)));
        $starts = array_values(unpack('n' . $seg, substr($this->data, $o + 16 + $segX2, $segX2)));
        $deltas = array_values(unpack('n' . $seg, substr($this->data, $o + 16 + 2 * $segX2, $segX2)));
        $roPos = $o + 16 + 3 * $segX2;
        $ros = array_values(unpack('n' . $seg, substr($this->data, $roPos, $segX2)));
        for ($i = 0; $i < $seg; $i++) {
            if ($cp <= $ends[$i]) {
                if ($cp < $starts[$i]) {
                    return 0;
                }
                if ($ros[$i] === 0) {
                    return ($cp + $deltas[$i]) & 0xFFFF;
                }
                $p = $roPos + $i * 2 + $ros[$i] + ($cp - $starts[$i]) * 2;
                $g = unpack('n', substr($this->data, $p, 2))[1];
                return $g === 0 ? 0 : ($g + $deltas[$i]) & 0xFFFF;
            }
        }
        return 0;
    }

    public function width(int $gid): int
    {
        $idx = min($gid, $this->nHm - 1);
        $adv = unpack('n', substr($this->data, $this->t['hmtx'][0] + $idx * 4, 2))[1];
        return (int)round($adv * 1000 / $this->upm);
    }

    /** UTF-8 -> list of code points (no mbstring needed). */
    public static function codepoints(string $s): array
    {
        $out = [];
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $c = ord($s[$i]);
            if ($c < 0x80) { $out[] = $c; $i++; }
            elseif ($c >= 0xF0 && $i + 3 < $n) { $out[] = (($c & 7) << 18) | ((ord($s[$i + 1]) & 63) << 12) | ((ord($s[$i + 2]) & 63) << 6) | (ord($s[$i + 3]) & 63); $i += 4; }
            elseif ($c >= 0xE0 && $i + 2 < $n) { $out[] = (($c & 15) << 12) | ((ord($s[$i + 1]) & 63) << 6) | (ord($s[$i + 2]) & 63); $i += 3; }
            elseif ($c >= 0xC0 && $i + 1 < $n) { $out[] = (($c & 31) << 6) | (ord($s[$i + 1]) & 63); $i += 2; }
            else { $i++; }
        }
        return $out;
    }

    /** Text width in points at the given size. */
    public function measure(string $s, float $size): float
    {
        $w = 0;
        foreach (self::codepoints($s) as $cp) {
            $w += $this->width($this->gid($cp));
        }
        return $w * $size / 1000;
    }

    /** Hex string for a Tj operator; remembers the glyph widths for the /W array. */
    public function hex(string $s): string
    {
        $h = '';
        foreach (self::codepoints($s) as $cp) {
            $g = $this->gid($cp);
            $this->glyphs[$g] = $this->width($g);
            $h .= sprintf('%04X', $g);
        }
        return '<' . $h . '>';
    }

    public function raw(): string
    {
        return $this->data;
    }
}

class MiniPdf
{
    private array $pages = [];
    private string $cur = '';
    public float $y = 0;
    public const W = 595.0, H = 842.0, M = 48.0;
    private PdfFont $reg;
    private PdfFont $bold;

    public function __construct()
    {
        $dir = ROOT . '/assets/fonts/';
        $this->reg = new PdfFont($dir . 'DejaVuSans.ttf');
        $this->bold = new PdfFont($dir . 'DejaVuSans-Bold.ttf');
        $this->page();
    }

    public function page(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
        $this->y = self::H - self::M;
    }

    private function color(array $rgb, bool $fill = true): string
    {
        return sprintf('%.3F %.3F %.3F %s ', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $fill ? 'rg' : 'RG');
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [31, 41, 55]): void
    {
        $f = $bold ? $this->bold : $this->reg;
        $this->cur .= 'BT ' . $this->color($rgb) . '/' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf ' . sprintf('%.2F %.2F Td ', $x, $y) . $f->hex($s) . " Tj ET\n";
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= $this->color($rgb) . sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $y, $w, $h);
    }

    public function line(float $x1, float $y, float $x2, array $rgb = [221, 224, 229]): void
    {
        $this->cur .= $this->color($rgb, false) . sprintf("0.8 w %.2F %.2F m %.2F %.2F l S\n", $x1, $y, $x2, $y);
    }

    /** Word-wrapped paragraph in a column; returns the height used and moves nothing. */
    public function wrap(string $s, float $width, float $size, bool $bold = false): array
    {
        $f = $bold ? $this->bold : $this->reg;
        $lines = [];
        foreach (preg_split('/\R/u', $s) as $para) {
            $cur = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                // very long words are cut by characters
                while ($f->measure($word, $size) > $width && $word !== '') {
                    $cut = '';
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                        if ($f->measure($cut . $ch, $size) > $width) {
                            break;
                        }
                        $cut .= $ch;
                    }
                    if ($cut === '') {
                        break;
                    }
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = $cut;
                    $word = substr($word, strlen($cut));
                }
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $f->measure($try, $size) > $width) {
                    $lines[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /** Writes wrapped text at x with the cursor $this->y; returns the bottom y. */
    public function para(float $x, float $width, string $s, float $size = 10, bool $bold = false, array $rgb = [31, 41, 55], float $lead = 0): float
    {
        $lead = $lead ?: $size * 1.4;
        $y = $this->y;
        foreach ($this->wrap($s, $width, $size, $bold) as $l) {
            if ($y - $lead < self::M) {
                $this->page();
                $y = $this->y;
            }
            $y -= $lead;
            $this->text($x, $y + $lead * 0.25, $l, $size, $bold, $rgb);
        }
        return $y;
    }

    public function output(): string
    {
        $this->pages[] = $this->cur;
        $this->cur = '';
        $objs = [];           // 1-based object bodies
        $add = function (string $b) use (&$objs): int { $objs[] = $b; return count($objs); };
        $stream = function (string $dict, string $data): string {
            $filter = '';
            if (function_exists('gzcompress')) {
                $data = gzcompress($data, 6);
                $filter = ' /Filter /FlateDecode';
            }
            return '<< ' . $dict . $filter . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
        };
        $catalog = $add('');
        $pagesObj = $add('');
        $fontRefs = [];
        $fontDefs = [];
        foreach ([1 => [$this->reg, 'DejaVuSans'], 2 => [$this->bold, 'DejaVuSans-Bold']] as $n => [$f, $name]) {
            $ff = $add($stream('/Length1 ' . strlen($f->raw()), $f->raw()));
            $bb = $f->bbox;
            $fd = $add("<< /Type /FontDescriptor /FontName /$name /Flags 4 /FontBBox [{$bb[0]} {$bb[1]} {$bb[2]} {$bb[3]}] /ItalicAngle 0 /Ascent {$f->ascent} /Descent {$f->descent} /CapHeight 729 /StemV 80 /FontFile2 $ff 0 R >>");
            ksort($f->glyphs);
            $w = '';
            foreach ($f->glyphs as $g => $gw) {
                $w .= "$g [$gw] ";
            }
            $cid = $add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$name /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $fd 0 R /DW 600 /W [ $w] /CIDToGIDMap /Identity >>");
            $fontRefs[$n] = $add("<< /Type /Font /Subtype /Type0 /BaseFont /$name /Encoding /Identity-H /DescendantFonts [ $cid 0 R ] >>");
        }
        $kids = [];
        foreach ($this->pages as $content) {
            $c = $add($stream('', $content));
            $kids[] = $add('<< /Type /Page /Parent ' . $pagesObj . ' 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontRefs[1] . ' 0 R /F2 ' . $fontRefs[2] . ' 0 R >> >> /Contents ' . $c . ' 0 R >>');
        }
        $objs[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>';
        $objs[$pagesObj - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => $k . ' 0 R', $kids)) . '] /Count ' . count($kids) . ' >>';
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $b) {
            $off[$i] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $b . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        return $out . 'trailer << /Size ' . (count($objs) + 1) . ' /Root ' . $catalog . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

/* ---------- invoice data and document ---------- */

/** Invoice with everything the view, the PDF and the e-mail need; null when missing. */
function invoice_full(int $id): ?array
{
    $inv = row("SELECT i.*, c.title AS contract_title, c.author_type, c.accepted_offer_id, t.name AS todo_name, t.position AS todo_pos
        FROM invoices i JOIN contracts c ON c.id = i.contract_id LEFT JOIN todos t ON t.id = i.todo_id WHERE i.id = ?", [$id]);
    return $inv ?: null;
}

/** The text on the invoice: number, date, reason (task name) and the price of the To Do. */
function invoice_summary(array $inv, string $lang): array
{
    $ru = $lang === 'ru';
    $sym = CURRENCIES[$inv['currency']][0] ?? $inv['currency'];
    $reason = $inv['todo_name'] !== null ? (string)$inv['todo_name'] : ($ru ? 'Весь контракт' : 'Whole contract');
    return [
        'number' => ['№', '#' . $inv['id']],
        'date' => [$ru ? 'Дата' : 'Date', fdate($inv['created_at'])],
        'reason' => [$ru ? 'Основание (задача)' : 'Reason (task)', $reason],
        'contract' => [$ru ? 'Контракт' : 'Contract', (string)$inv['contract_title']],
        'price' => [$ru ? 'Сумма' : 'Amount', $sym . ' ' . money($inv['amount'])],
    ];
}

function invoice_pdf(array $inv, string $lang): string
{
    $ru = $lang === 'ru';
    $from = row('SELECT * FROM users WHERE id = ?', [$inv['payee_id']]) ?: [];
    $to = row('SELECT * FROM users WHERE id = ?', [$inv['payer_id']]) ?: [];
    $fromType = (string)(val('SELECT offerer_type FROM offers WHERE id = ?', [$inv['accepted_offer_id']]) ?: 'personal');
    $toType = (string)($inv['author_type'] ?: 'personal');
    $tpl = pick_invoice_template($fromType, $toType);
    $sum = invoice_summary($inv, $lang);

    $pdf = new MiniPdf();
    $L = MiniPdf::M;
    $R = MiniPdf::W - MiniPdf::M;
    $green = [0, 188, 31];
    $pdf->rect(0, MiniPdf::H - 10, MiniPdf::W, 10, $green);
    $pdf->text($L, MiniPdf::H - 52, 'bablito', 24, true, $green);
    $pdf->text($L, MiniPdf::H - 92, ($ru ? 'Счёт ' : 'Invoice ') . $sum['number'][1], 20, true);
    $pdf->text($R - 140, MiniPdf::H - 52, $sum['date'][0] . ': ' . $sum['date'][1], 10, false, [107, 114, 128]);
    $pdf->y = MiniPdf::H - 110;
    $pdf->line($L, $pdf->y, $R);
    $pdf->y -= 14;

    // From / To columns
    $colW = (MiniPdf::W - 2 * MiniPdf::M - 20) / 2;
    $blockLines = function (array $user) use ($inv, $tpl, $ru): string {
        $out = [];
        if ($tpl) {
            $side = $user['id'] === $inv['payee_id'] ? 'from' : 'to';
            foreach (invoice_block_tokens($tpl, $side) as $tok) {
                $fv = invoice_field_value($tok, $inv, $user);
                if ($fv) {
                    $out[] = $fv[0] . ': ' . $fv[1];
                }
            }
        }
        if (!$out) {
            $out[] = display_name($user);
            $e = (string)($user['contact_email'] ?: $user['email']);
            if ($e !== '') {
                $out[] = $e;
            }
        }
        return implode("\n", $out);
    };
    $top = $pdf->y;
    $pdf->y = $top;
    $pdf->text($L, $top - 10, $ru ? 'ОТ (ИСПОЛНИТЕЛЬ)' : 'FROM (CONTRACTOR)', 8, true, [107, 114, 128]);
    $pdf->y = $top - 14;
    $b1 = $pdf->para($L, $colW, $blockLines($from), 10);
    $pdf->y = $top;
    $pdf->text($L + $colW + 20, $top - 10, $ru ? 'КОМУ (ЗАКАЗЧИК)' : 'TO (CONTRACT AUTHOR)', 8, true, [107, 114, 128]);
    $pdf->y = $top - 14;
    $b2 = $pdf->para($L + $colW + 20, $colW, $blockLines($to), 10);
    $pdf->y = min($b1, $b2) - 14;

    // Details table
    $pdf->line($L, $pdf->y, $R);
    $pdf->y -= 8;
    foreach (['reason', 'contract', 'number', 'date'] as $k) {
        $y0 = $pdf->y;
        $pdf->text($L, $y0 - ($k === 'reason' ? 12.6 : 10.5), $sum[$k][0], 10, false, [107, 114, 128]);
        $pdf->y = $y0;
        $bottom = $pdf->para($L + 170, $R - $L - 170, $sum[$k][1], $k === 'reason' ? 12 : 10, $k === 'reason');
        $pdf->y = $bottom - 6;
    }
    $pdf->line($L, $pdf->y, $R);
    $pdf->y -= 10;
    // Total
    $pdf->rect($L, $pdf->y - 42, $R - $L, 42, [243, 249, 238]);
    $pdf->text($L + 16, $pdf->y - 26, $sum['price'][0], 12, true);
    $amt = $sum['price'][1];
    $w = (new PdfFont(ROOT . '/assets/fonts/DejaVuSans-Bold.ttf'))->measure($amt, 16);
    $pdf->text($R - 16 - $w, $pdf->y - 28, $amt, 16, true, [10, 107, 27]);
    $pdf->y -= 60;

    if ($tpl) {
        foreach ([$tpl['header_html'], $tpl['footer_html']] as $html) {
            $t = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h[1-4]|/li)\s*/?>#i', "\n", (string)$html)), ENT_QUOTES, 'UTF-8'));
            if ($t !== '') {
                $pdf->y = $pdf->para($L, $R - $L, $t, 9, false, [107, 114, 128]) - 6;
            }
        }
    }
    $pdf->text($L, MiniPdf::M - 20, 'bablito.ru', 8, false, [160, 168, 178]);
    return $pdf->output();
}

/** Queues the e-mail with the PDF copy of a new invoice for the contract author (the payer). */
function mail_invoice_copy(int $invoiceId): void
{
    try {
        $inv = invoice_full($invoiceId);
        $payer = $inv ? row('SELECT id, email, lang FROM users WHERE id = ?', [$inv['payer_id']]) : null;
        if (!$payer || (string)$payer['email'] === '') {
            return;
        }
        $lang = user_lang($payer);
        $ru = $lang === 'ru';
        $sum = invoice_summary($inv, $lang);
        $site = site_base_url();
        $url = $site . '/invoice/' . $inv['id'];
        $subject = ($ru ? 'Счёт ' : 'Invoice ') . $sum['number'][1] . ' - ' . $sum['reason'][1];
        $text = ($ru ? 'Выставлен счёт ' : 'An invoice was issued: ') . $sum['number'][1] . "\n\n"
            . $sum['reason'][0] . ': ' . $sum['reason'][1] . "\n" . $sum['contract'][0] . ': ' . $sum['contract'][1] . "\n"
            . $sum['price'][0] . ': ' . $sum['price'][1] . "\n" . $sum['date'][0] . ': ' . $sum['date'][1] . "\n\n"
            . ($ru ? 'Копия счёта во вложении (PDF).' : 'A copy of the invoice is attached (PDF).') . "\n" . $url;
        $html = email_html([
            'lang' => $lang, 'title' => $subject, 'preheader' => $sum['reason'][1] . ' - ' . $sum['price'][1],
            'tag' => $ru ? 'Счёт' : 'Invoice', 'who' => 'bablito', 'when' => date('d.m.Y H:i'),
            'headline' => ($ru ? 'Счёт ' : 'Invoice ') . $sum['number'][1],
            'rows' => [$sum['reason'], $sum['contract'], $sum['price'], $sum['date']],
            'comment' => ['body' => $ru ? 'Копия счёта во вложении (PDF).' : 'A copy of the invoice is attached (PDF).', 'files' => false, 'label' => 'PDF'],
            'button' => $ru ? 'Открыть счёт' : 'Open invoice', 'url' => $url,
            'link_hint' => $ru ? 'Если кнопка не работает, откройте ссылку:' : 'If the button does not work, open this link:',
            'footer' => $ru ? 'Вы получили это письмо, потому что вы автор этого контракта.' : 'You get this message because you are the author of this contract.',
            'settings_label' => $ru ? 'Настройки профиля' : 'Profile settings',
            'settings_url' => $site . '/profile', 'site_url' => $site,
        ]);
        $file = 'invoice-' . $inv['id'] . '.pdf';
        queue_mail((string)$payer['email'], 'bablito: ' . $subject, $text, $html, [['name' => $file, 'type' => 'application/pdf', 'data' => invoice_pdf($inv, $lang)]]);
    } catch (Throwable $e) {
        error_log('invoice mail failed: ' . $e);
    }
}
