<?php
// includes/helpers.php
declare(strict_types=1);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function unit_factor(array $p): float
{
    // base qty meaning:
    // - boxes/dozens/pairs/pieces -> base is pieces
    // - units -> base is that unit (kg, liters etc), factor = 1
    $t = $p['unit_type'] ?? 'pieces';
    if ($t === 'boxes') return max(1, (int)($p['pieces_per_box'] ?? 0));
    if ($t === 'dozens') return 12;
    if ($t === 'pairs')  return 2;
    if ($t === 'pieces') return 1;
    return 1; // units (kg etc)
}

function format_stock(array $p): string
{
    $t = $p['unit_type'] ?? 'pieces';
    $qty = (float)($p['qty_base'] ?? 0);

    if ($t === 'boxes') {
        $ppb = max(1, (int)($p['pieces_per_box'] ?? 0));
        $cartons = (int) floor($qty / $ppb);
        $pieces  = (int) round($qty - ($cartons * $ppb));
        return $cartons . " cartons and " . $pieces . " pieces";
    }

    if ($t === 'dozens') {
        $doz = (int) floor($qty / 12);
        $pcs = (int) round($qty - ($doz * 12));
        return $doz . " dozens and " . $pcs . " pieces";
    }

    if ($t === 'pairs') {
        $prs = (int) floor($qty / 2);
        $pcs = (int) round($qty - ($prs * 2));
        return $prs . " pairs and " . $pcs . " pieces";
    }

    if ($t === 'units') {
        $u = trim((string)($p['unit_name'] ?? 'unit'));
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . " " . $u;
    }

    // pieces
    return (string)((int)round($qty)) . " pieces";
}

function parse_sale_qty(string $input): float
{
    // allow decimals for units (kg), and integers for pieces-based
    $x = trim($input);
    if ($x === '') return 0;
    return (float)$x;
}
