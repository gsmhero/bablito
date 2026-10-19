<?php
declare(strict_types=1);

function num($v): float
{
    return (float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v);
}

/**
 * Reads the offer form. Total comes either from the per-step prices (plus the offerer's own extra steps)
 * or from the manual total, never both. 0 days is allowed (several steps done on the same day).
 * Returns an error string or ['price', 'days', 'notes', 'steps' => [todo_id => [price, days]], 'extras' => [...]].
 */
function parse_offer_input(array $todos, bool $allowExtras = false): array|string
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

    $extras = [];
    $extraHasDays = false;
    if ($allowExtras) {
        foreach ((array)($_POST['extra'] ?? []) as $e) {
            $name = trim((string)($e['name'] ?? ''));
            $p = trim((string)($e['price'] ?? ''));
            $d = trim((string)($e['days'] ?? ''));
            if ($name === '' && $p === '' && $d === '') {
                continue;
            }
            if ($name === '' || mb_strlen($name) > 190 || $p === '' || num($p) < 0 || num($p) > 999999999 || ($d !== '' && ((int)$d < 0 || (int)$d > 3650))) {
                return 'Check the name, price and days of your added To Dos.';
            }
            $extras[] = ['name' => $name, 'description' => mb_substr(trim((string)($e['description'] ?? '')), 0, 4000), 'price' => num($p), 'days' => $d === '' ? 0 : (int)$d];
            if ($d !== '') {
                $extraHasDays = true;
            }
            if (count($extras) >= 30) {
                break;
            }
        }
        if ($extras) {
            $any = true;
        }
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 4000) {
        return 'Notes are too long.';
    }
    if ($any) {
        $out = [];
        $price = 0.0;
        $days = 0;
        $anyDays = $extraHasDays;
        foreach ($steps as $tid => [$p, $d]) {
            if ($p === '' || num($p) < 0 || ($d !== '' && (int)$d < 0)) {
                return 'Fill in the offer price for every To Do, or clear them all and enter a total.';
            }
            if ($d !== '') {
                $anyDays = true;
            }
            $out[$tid] = [num($p), $d === '' ? 0 : (int)$d];
            $price += num($p);
            $days += $d === '' ? 0 : (int)$d;
        }
        foreach ($extras as $e) {
            $price += $e['price'];
            $days += $e['days'];
        }
        if (!$anyDays) {
            // no days on any To Do: the total "Time days" field decides (e.g. everything is done in one day)
            $dRaw = trim((string)($_POST['days'] ?? ''));
            $days = (int)$dRaw;
            if ($dRaw === '' || $days < 1 || $days > 3650) {
                return 'Enter Time days (1 or more), or fill the days of the To Dos.';
            }
        }
    } else {
        $out = [];
        $price = num($_POST['price'] ?? '');
        $dRaw = trim((string)($_POST['days'] ?? ''));
        $days = (int)$dRaw;
        if ($dRaw === '' || $days < 0 || $days > 3650) {
            return 'Enter valid offer days.';
        }
    }
    if ($price <= 0 || $price > 999999999) {
        return 'Enter a valid offer price.';
    }
    $pt = ((string)($_POST['payment_type'] ?? '')) === 'step' ? 'step' : 'finish';
    return ['price' => $price, 'days' => $days, 'notes' => $notes, 'steps' => $out, 'extras' => $extras, 'payment_type' => $pt];
}

function save_offer_steps(int $offerId, array $steps, array $extras = []): void
{
    q('DELETE FROM offer_todos WHERE offer_id = ?', [$offerId]);
    foreach ($steps as $tid => [$p, $d]) {
        q('INSERT INTO offer_todos (offer_id, todo_id, price, days) VALUES (?,?,?,?)', [$offerId, $tid, $p, $d]);
    }
    q('DELETE FROM offer_extra_todos WHERE offer_id = ?', [$offerId]);
    foreach ($extras as $i => $e) {
        q('INSERT INTO offer_extra_todos (offer_id, position, name, description, price, days) VALUES (?,?,?,?,?,?)', [$offerId, $i, $e['name'], $e['description'], $e['price'], $e['days']]);
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
    $perDays = array_sum(array_map(fn($r) => (int)$r['days'], $per));
    $sumP = array_sum(array_map(fn($t) => (float)$t['price'], $todos));
    $leftP = (float)$offer['price'];
    $leftD = (int)$offer['days'];
    foreach ($todos as $i => $t) {
        if (isset($per[(int)$t['id']])) {
            $p = (float)$per[(int)$t['id']]['price'];
            $d = (int)$per[(int)$t['id']]['days'];
            if ($perDays === 0 && $i === 0 && (int)$offer['days'] > 0) {
                $d = (int)$offer['days']; // whole time on the first step: every step is due at the final date
            }
        } elseif ($i === $n - 1) {
            $p = max(0.0, $leftP);
            $d = max(0, $leftD);
        } else {
            $p = round((float)$offer['price'] * ($sumP > 0 ? (float)$t['price'] / $sumP : 1 / $n), 2);
            $d = max(0, (int)round((int)$offer['days'] / $n));
        }
        $leftP -= $p;
        $leftD -= $d;
        q('UPDATE todos SET agreed_price = ?, agreed_days = ? WHERE id = ?', [$p, $d, $t['id']]);
    }
    // Steps added by the offerer become real steps of the contract.
    $pos = $n;
    foreach (rows('SELECT * FROM offer_extra_todos WHERE offer_id = ? ORDER BY position, id', [$offer['id']]) as $e) {
        q('INSERT INTO todos (contract_id, position, name, description, price, days, agreed_price, agreed_days) VALUES (?,?,?,?,?,?,?,?)',
            [$contract['id'], $pos++, $e['name'], $e['description'], $e['price'], $e['days'], $e['price'], $e['days']]);
    }
    q("UPDATE contracts SET status = 'active', contractor_id = ?, accepted_offer_id = ?, payment_type = ?, agreed_price = ?, agreed_days = ?, started_at = NOW(), agreed_deadline = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?",
        [$offer['user_id'], $offer['id'], $offer['payment_type'] ?? 'finish', $offer['price'], $offer['days'], $offer['days'], $contract['id']]);
    q("UPDATE offers SET status = 'accepted' WHERE id = ?", [$offer['id']]);
    delete_offers("contract_id = ? AND id <> ? AND status = 'pending'", [$contract['id'], $offer['id']]);
}

/** Offer form rows for the offerer's own extra steps (shared by contract and offer pages). */
function extra_todo_row(int $i, array $e = []): string
{
    return '<div class="card extra-todo"><div class="row gap">'
        . '<label class="tiny grow">To Do Name <input name="extra[' . $i . '][name]" form="offer-form" maxlength="190" value="' . h($e['name'] ?? '') . '"></label>'
        . '<label class="tiny">Price <input class="num o-price" form="offer-form" name="extra[' . $i . '][price]" inputmode="decimal" value="' . h(isset($e['price']) ? (string)(float)$e['price'] : '') . '"></label>'
        . '<label class="tiny">Days <input class="num o-days" form="offer-form" type="number" min="0" name="extra[' . $i . '][days]" value="' . h(isset($e['days']) ? (string)(int)$e['days'] : '') . '"></label>'
        . '<button type="button" class="linkbtn red remove-extra">Remove</button></div>'
        . '<label class="tiny">Description <textarea form="offer-form" name="extra[' . $i . '][description]" rows="2">' . h($e['description'] ?? '') . '</textarea></label></div>';
}

/** Removes offers (rejected ones disappear everywhere) together with their private discussion. */
function delete_offers(string $where, array $params): void
{
    $ids = array_column(rows("SELECT id FROM offers WHERE $where", $params), 'id');
    if (!$ids) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    q("DELETE FROM comments WHERE scope = 'offer' AND offer_id IN ($in)");
    q("DELETE FROM offers WHERE id IN ($in)");
}
