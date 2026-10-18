<?php
declare(strict_types=1);

function num($v): float
{
    return (float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v);
}

/**
 * Reads the offer form. Total comes either from the per-step prices or from the manual total, never both.
 * Returns an error string or ['price', 'days', 'notes', 'steps' => [todo_id => [price, days]]].
 */
function parse_offer_input(array $todos): array|string
{
    $steps = [];
    $any = false;
    foreach ($todos as $t) {
        $in = $_POST['otodo'][$t['id']] ?? [];
        $p = trim((string)($in['price'] ?? ''));
        $d = trim((string)($in['days'] ?? ''));
        if ($p !== '' || $d !== '') {
            $any = true;
        }
        $steps[(int)$t['id']] = [$p, $d];
    }
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 4000) {
        return 'Notes are too long.';
    }
    if ($any) {
        $out = [];
        $sumP = 0.0;
        $sumD = 0;
        foreach ($steps as $tid => [$p, $d]) {
            if ($p === '' || $d === '' || num($p) < 0 || (int)$d < 1) {
                return 'Fill in offer price and days for every To Do, or clear them all and enter a total.';
            }
            $out[$tid] = [num($p), (int)$d];
            $sumP += num($p);
            $sumD += (int)$d;
        }
        $price = $sumP;
        $days = $sumD;
    } else {
        $out = [];
        $price = num($_POST['price'] ?? '');
        $days = (int)($_POST['days'] ?? 0);
    }
    if ($price <= 0 || $price > 999999999) {
        return 'Enter a valid offer price.';
    }
    if ($days < 1 || $days > 3650) {
        return 'Enter valid offer days.';
    }
    return ['price' => $price, 'days' => $days, 'notes' => $notes, 'steps' => $out];
}

function save_offer_steps(int $offerId, array $steps): void
{
    q('DELETE FROM offer_todos WHERE offer_id = ?', [$offerId]);
    foreach ($steps as $tid => [$p, $d]) {
        q('INSERT INTO offer_todos (offer_id, todo_id, price, days) VALUES (?,?,?,?)', [$offerId, $tid, $p, $d]);
    }
}

/** Freezes the accepted terms onto contract and steps. Call inside a transaction. */
function accept_offer_terms(array $contract, array $offer): void
{
    $todos = rows('SELECT * FROM todos WHERE contract_id = ? ORDER BY position, id', [$contract['id']]);
    $per = [];
    foreach (rows('SELECT * FROM offer_todos WHERE offer_id = ?', [$offer['id']]) as $r) {
        $per[(int)$r['todo_id']] = $r;
    }
    $n = count($todos);
    $sumP = array_sum(array_map(fn($t) => (float)$t['price'], $todos));
    $leftP = (float)$offer['price'];
    $leftD = (int)$offer['days'];
    foreach ($todos as $i => $t) {
        if (isset($per[(int)$t['id']])) {
            $p = (float)$per[(int)$t['id']]['price'];
            $d = (int)$per[(int)$t['id']]['days'];
        } elseif ($i === $n - 1) {
            $p = max(0.0, $leftP);
            $d = max(1, $leftD);
        } else {
            $p = round((float)$offer['price'] * ($sumP > 0 ? (float)$t['price'] / $sumP : 1 / $n), 2);
            $d = max(1, (int)round((int)$offer['days'] / $n));
        }
        $leftP -= $p;
        $leftD -= $d;
        q('UPDATE todos SET agreed_price = ?, agreed_days = ? WHERE id = ?', [$p, $d, $t['id']]);
    }
    q("UPDATE contracts SET status = 'active', contractor_id = ?, accepted_offer_id = ?, agreed_price = ?, agreed_days = ?, started_at = NOW(), agreed_deadline = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?",
        [$offer['user_id'], $offer['id'], $offer['price'], $offer['days'], $offer['days'], $contract['id']]);
    q("UPDATE offers SET status = 'accepted' WHERE id = ?", [$offer['id']]);
    q("UPDATE offers SET status = 'rejected' WHERE contract_id = ? AND id <> ? AND status = 'pending'", [$contract['id'], $offer['id']]);
}
