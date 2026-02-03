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

/**
 * Get currency symbol from database settings
 * @param mysqli|null $db Database connection
 * @return string Currency symbol (default: $)
 */
function get_currency_symbol($db = null): string {
    if (!$db) {
        $db = $GLOBALS['db'] ?? null;
    }
    
    if (!$db) {
        return '$'; // Default fallback
    }
    
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'currency_symbol' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row['value'] ?? '$';
        }
    } catch (Exception $e) {
        // Log error if needed
    }
    
    return '$'; // Default fallback
}

/**
 * Get currency code from database settings
 * @param mysqli|null $db Database connection
 * @return string Currency code (default: USD)
 */
function get_currency_code($db = null): string {
    if (!$db) {
        $db = $GLOBALS['db'] ?? null;
    }
    
    if (!$db) {
        return 'USD'; // Default fallback
    }
    
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'currency_code' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row['value'] ?? 'USD';
        }
    } catch (Exception $e) {
        // Log error if needed
    }
    
    return 'USD'; // Default fallback
}

/**
 * Get decimal places from database settings
 * @param mysqli|null $db Database connection
 * @return int Number of decimal places (default: 2)
 */
function get_currency_decimals($db = null): int {
    if (!$db) {
        $db = $GLOBALS['db'] ?? null;
    }
    
    if (!$db) {
        return 2; // Default fallback
    }
    
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'decimal_places' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return (int)($row['value'] ?? 2);
        }
    } catch (Exception $e) {
        // Log error if needed
    }
    
    return 2; // Default fallback
}

/**
 * Format currency amount with symbol and proper decimal places
 * @param float $amount Amount to format
 * @param mysqli|null $db Database connection
 * @return string Formatted currency string
 */
function format_currency(float $amount, $db = null): string {
    $symbol = get_currency_symbol($db);
    $decimals = get_currency_decimals($db);
    
    return $symbol . number_format($amount, $decimals);
}

/**
 * Format currency amount for display (without symbol)
 * @param float $amount Amount to format
 * @param mysqli|null $db Database connection
 * @return string Formatted number string
 */
function format_currency_amount(float $amount, $db = null): string {
    $decimals = get_currency_decimals($db);
    
    return number_format($amount, $decimals);
}
