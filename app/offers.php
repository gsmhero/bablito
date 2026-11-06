<?php
declare(strict_types=1);

function num($v): float
{
    return (float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v);
}

/**
 * Reads the offer form (counteroffer or "accept as is"). The form posts items[k] in the order the offerer arranged them:
 * every step of the contract (price and days of the offer, optionally marked "unnecessary") and the offerer's own steps.
 * The offer total is the sum of the steps that are not marked unnecessary.
 * Returns an error string or ['price', 'days', 'notes', 'items' => [...], 'payment_type', 'offerer_type'].
 */
function parse_offer_items(array $todos, bool $asIs = false): array|string
{
    $byId = [];
    foreach ($todos as $t) {
        $byId[(int)$t['id']] = $t;
    }
    $items = [];
    $seen = [];
    if (!$asIs) {
        foreach ((array)($_POST['items'] ?? []) as $in) {
            if (!is_array($in)) {
                continue;
            }
            $p = trim((string)($in['price'] ?? ''));
            $d = trim((string)($in['days'] ?? ''));
            if (($in['type'] ?? '') === 'extra') {
                $name = trim((string)($in['name'] ?? ''));
                if ($name === '' && $p === '' && $d === '') {
                    continue; // an empty added row is ignored
                }
                if ($name === '' || mb_strlen($name) > 190 || $p === '') {
                    return 'Check the name, price and days of your added To Dos.';
                }
                if (count($items) >= 80) {
                    break;
                }
                $items[] = ['type' => 'extra', 'id' => 0, 'name' => $name, 'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 4000), 'price' => num($p), 'days' => $d === '' ? 0 : (int)$d, 'excluded' => false];
            } else {
                $tid = (int)($in['id'] ?? 0);
                if (!isset($byId[$tid]) || isset($seen[$tid])) {
                    continue;
                }
                $seen[$tid] = true;
                $items[] = ['type' => 'todo', 'id' => $tid, 'price' => $p === '' ? (float)$byId[$tid]['price'] : num($p), 'days' => $d === '' ? (int)$byId[$tid]['days'] : (int)$d, 'excluded' => !empty($in['excluded'])];
            }
        }
    }
    foreach ($byId as $tid => $t) { // a step that did not come back with the form is taken as the author wrote it
        if (!isset($seen[$tid])) {
            $items[] = ['type' => 'todo', 'id' => $tid, 'price' => (float)$t['price'], 'days' => (int)$t['days'], 'excluded' => false];
        }
    }
    $price = 0.0;
    $days = 0;
    $kept = 0;
    foreach ($items as $i => $it) {
        if ($it['price'] < 0 || $it['price'] > 999999999 || $it['days'] < 0 || $it['days'] > 3650) {
            return 'Check the price and days of the To Dos.';
        }
        $items[$i]['pos'] = $i;
        if (!$it['excluded']) {
            $price += $it['price'];
            $days += $it['days'];
            $kept++;
        }
    }
    if ($kept === 0) {
        return 'Keep at least one To Do in the offer.';
    }
    if ($price <= 0) {
        return 'Enter a valid offer price.';
    }
    $notes = trim((string)($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 4000) {
        return 'Notes are too long.';
    }
    $pt = ((string)($_POST['payment_type'] ?? '')) === 'step' ? 'step' : 'finish';
    $mine = user_types(user());
    $ot = in_array((string)($_POST['offerer_type'] ?? ''), $mine, true) ? (string)$_POST['offerer_type'] : $mine[0];
    return ['price' => $price, 'days' => $days, 'notes' => $notes, 'items' => $items, 'payment_type' => $pt, 'offerer_type' => $ot];
}

/** Stores the steps of an offer in the order of the offerer. */
function save_offer_items(int $offerId, array $items): void
{
    q('DELETE FROM offer_todos WHERE offer_id = ?', [$offerId]);
    q('DELETE FROM offer_extra_todos WHERE offer_id = ?', [$offerId]);
    foreach ($items as $it) {
        if ($it['type'] === 'todo') {
            q('INSERT INTO offer_todos (offer_id, todo_id, price, days, position, excluded) VALUES (?,?,?,?,?,?)', [$offerId, $it['id'], $it['price'], $it['days'], $it['pos'], $it['excluded'] ? 1 : 0]);
        } else {
            q('INSERT INTO offer_extra_todos (offer_id, position, name, description, price, days) VALUES (?,?,?,?,?,?)', [$offerId, $it['pos'], $it['name'], $it['description'], $it['price'], $it['days']]);
        }
    }
}

/**
 * Steps of an offer form in display order. $todos are the contract steps (optionally with the saved offer values
 * o_price, o_days, o_pos, o_excl); $draft is the posted form of an offer that failed validation (typed values are kept).
 */
function offer_items(array $todos, array $extras = [], ?array $draft = null): array
{
    $byId = [];
    foreach ($todos as $t) {
        $byId[(int)$t['id']] = $t;
    }
    $out = [];
    if ($draft !== null) {
        $seen = [];
        foreach ((array)($draft['items'] ?? []) as $in) {
            if (!is_array($in)) {
                continue;
            }
            if (($in['type'] ?? '') === 'extra') {
                $out[] = ['type' => 'extra', 'id' => 0, 'name' => (string)($in['name'] ?? ''), 'description' => (string)($in['description'] ?? ''), 'price' => '', 'days' => '', 'o_price' => (string)($in['price'] ?? ''), 'o_days' => (string)($in['days'] ?? ''), 'excluded' => false];
            } elseif (isset($byId[(int)($in['id'] ?? 0)]) && !isset($seen[(int)$in['id']])) {
                $t = $byId[(int)$in['id']];
                $seen[(int)$in['id']] = true;
                $out[] = ['type' => 'todo', 'id' => (int)$t['id'], 'name' => $t['name'], 'description' => $t['description'], 'price' => $t['price'], 'days' => $t['days'], 'o_price' => (string)($in['price'] ?? ''), 'o_days' => (string)($in['days'] ?? ''), 'excluded' => !empty($in['excluded'])];
            }
        }
        foreach ($byId as $tid => $t) {
            if (!isset($seen[$tid])) {
                $out[] = ['type' => 'todo', 'id' => $tid, 'name' => $t['name'], 'description' => $t['description'], 'price' => $t['price'], 'days' => $t['days'], 'o_price' => null, 'o_days' => null, 'excluded' => false];
            }
        }
        return $out;
    }
    $ordered = false;
    foreach ($todos as $t) {
        if (($t['o_pos'] ?? null) !== null) {
            $ordered = true;
        }
        $out[] = ['type' => 'todo', 'id' => (int)$t['id'], 'name' => $t['name'], 'description' => $t['description'], 'price' => $t['price'], 'days' => $t['days'], 'o_price' => $t['o_price'] ?? null, 'o_days' => $t['o_days'] ?? null, 'excluded' => !empty($t['o_excl']), 'pos' => $ordered ? (int)$t['o_pos'] : null];
    }
    foreach ($extras as $e) {
        $out[] = ['type' => 'extra', 'id' => 0, 'name' => $e['name'], 'description' => $e['description'], 'price' => '', 'days' => '', 'o_price' => $e['price'], 'o_days' => $e['days'], 'excluded' => false, 'pos' => $ordered ? (int)$e['position'] : null];
    }
    if ($ordered) {
        usort($out, fn($a, $b) => ($a['pos'] ?? 9999) <=> ($b['pos'] ?? 9999));
    }
    return $out;
}

function offer_val($v): string
{
    return is_numeric($v) ? (string)(float)$v : (string)$v;
}

/**
 * One step of the contract as a card. $mode: "view" (read only, shows the offer values when there are any) or
 * "edit" (counteroffer fields: offer price and days, "Unnecessary", up / down; added steps can be removed).
 * In edit mode everything that only belongs to editing carries .edit-only: the contract page hides it until the
 * person presses "Make counteroffer".
 */
function offer_task_card(array $c, array $it, string $k, string $mode): string
{
    $sym = cur_symbol($c);
    $edit = $mode === 'edit';
    $extra = $it['type'] === 'extra';
    $name = 'items[' . $k . ']';
    $o = '<div class="card todo-view otask' . (!empty($it['excluded']) ? ' unneeded' : '') . ($extra ? ' is-extra' : '') . '">';
    if ($edit) {
        $o .= '<input type="hidden" form="offer-form" name="' . $name . '[type]" value="' . $it['type'] . '"><input type="hidden" form="offer-form" name="' . $name . '[id]" value="' . (int)$it['id'] . '">';
        $o .= '<div class="otask-tools edit-only"><button type="button" class="linkbtn otask-up" title="' . h('Move up') . '">&uarr;</button><button type="button" class="linkbtn otask-down" title="' . h('Move down') . '">&darr;</button>';
        $o .= $extra
            ? '<button type="button" class="linkbtn red remove-otask">Remove</button>'
            : '<label class="check unneeded-chk"><input type="checkbox" form="offer-form" name="' . $name . '[excluded]" value="1"' . (!empty($it['excluded']) ? ' checked' : '') . '> Unnecessary</label>';
        $o .= '</div>';
    }
    if ($edit && $extra) {
        $o .= '<label class="tiny">To Do Name <input form="offer-form" name="' . $name . '[name]" maxlength="190" value="' . h($it['name']) . '"></label>';
        $o .= '<label class="tiny">Description <textarea form="offer-form" name="' . $name . '[description]" rows="2">' . h($it['description']) . '</textarea></label>';
        $o .= '<div class="todo-fields">'
            . '<label class="tiny">Offer price <input form="offer-form" class="num o-price" name="' . $name . '[price]" inputmode="decimal" value="' . h(offer_val($it['o_price'] ?? '')) . '"></label>'
            . '<label class="tiny">Offer days <input form="offer-form" class="num o-days" type="number" min="0" name="' . $name . '[days]" value="' . h(offer_val($it['o_days'] ?? '')) . '"></label></div>';
        return $o . '</div>';
    }
    $o .= '<h4>' . h($it['name']) . (!empty($it['excluded']) && !$edit ? ' <span class="pill grey">' . h('Unnecessary') . '</span>' : '') . ($extra ? ' <span class="pill blue">' . h('Added by the offerer') . '</span>' : '') . '</h4>';
    $o .= '<p class="small">' . nl2br(h((string)$it['description'])) . '</p>';
    $o .= '<div class="todo-fields">';
    if (!$extra) {
        $o .= '<div><div class="tiny">Price</div><div class="mid green">' . h($sym) . '&nbsp;' . money($it['price']) . '</div></div>'
            . '<div><div class="tiny">Days</div><div class="mid grey">' . (int)$it['days'] . '</div></div>';
    }
    if ($edit) {
        $op = $it['o_price'] ?? $it['price'];
        $od = $it['o_days'] ?? $it['days'];
        $o .= '<label class="tiny edit-only">Offer price <input form="offer-form" class="num o-price" name="' . $name . '[price]" inputmode="decimal" value="' . h(offer_val($op)) . '"></label>'
            . '<label class="tiny edit-only">Offer days <input form="offer-form" class="num o-days" type="number" min="0" name="' . $name . '[days]" value="' . h(offer_val($od)) . '"></label>';
    } elseif ($it['o_price'] !== null && $it['o_price'] !== '') {
        $o .= '<div><div class="tiny">Offer price</div><div class="mid blue">' . h($sym) . '&nbsp;' . money($it['o_price']) . '</div></div>'
            . '<div><div class="tiny">Offer days</div><div class="mid blue">' . (int)$it['o_days'] . '</div></div>';
    }
    return $o . '</div></div>';
}

/** The whole task list with the add button and the template for a new step (edit mode). */
function offer_task_list(array $c, array $items, string $mode): string
{
    $o = '<div id="otasks">';
    foreach ($items as $i => $it) {
        $o .= offer_task_card($c, $it, (string)$i, $mode);
    }
    $o .= '</div>';
    if ($mode === 'edit') {
        $o .= '<div class="right edit-only"><button type="button" class="btn small blue-outline" id="add-otask">+ Add To Do</button></div>'
            . '<template id="otask-tpl">' . offer_task_card($c, ['type' => 'extra', 'id' => 0, 'name' => '', 'description' => '', 'price' => '', 'days' => '', 'o_price' => '', 'o_days' => '', 'excluded' => false], '__k__', 'edit') . '</template>';
    }
    return $o;
}

/** Freezes the accepted terms onto contract and steps. Call inside a transaction. */
function accept_offer_terms(array $contract, array $offer): void
{
    $todos = rows('SELECT * FROM todos WHERE contract_id = ? ORDER BY position, id', [$contract['id']]);
    $per = [];
    $ordered = false;
    foreach (rows('SELECT * FROM offer_todos WHERE offer_id = ?', [$offer['id']]) as $r) {
        $per[(int)$r['todo_id']] = $r;
        if ($r['position'] !== null) {
            $ordered = true;
        }
    }
    if ($ordered) {
        // counteroffer: the offerer's order, unnecessary steps are dropped, the offerer's own steps become real steps
        foreach ($todos as $t) {
            $r = $per[(int)$t['id']] ?? null;
            if ($r && (int)$r['excluded'] === 1) {
                q('DELETE FROM todos WHERE id = ?', [$t['id']]);
            } elseif ($r) {
                q('UPDATE todos SET position = ?, agreed_price = ?, agreed_days = ? WHERE id = ?', [(int)$r['position'], $r['price'], $r['days'], $t['id']]);
            } else {
                q('UPDATE todos SET agreed_price = ?, agreed_days = ? WHERE id = ?', [$t['price'], $t['days'], $t['id']]);
            }
        }
        foreach (rows('SELECT * FROM offer_extra_todos WHERE offer_id = ? ORDER BY position, id', [$offer['id']]) as $e) {
            q('INSERT INTO todos (contract_id, position, name, description, price, days, agreed_price, agreed_days) VALUES (?,?,?,?,?,?,?,?)',
                [$contract['id'], (int)$e['position'], $e['name'], $e['description'], $e['price'], $e['days'], $e['price'], $e['days']]);
        }
    } else {
        // offers made before the counteroffer form: per-step values or a total that is spread over the steps
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
        $pos = $n;
        foreach (rows('SELECT * FROM offer_extra_todos WHERE offer_id = ? ORDER BY position, id', [$offer['id']]) as $e) {
            q('INSERT INTO todos (contract_id, position, name, description, price, days, agreed_price, agreed_days) VALUES (?,?,?,?,?,?,?,?)',
                [$contract['id'], $pos++, $e['name'], $e['description'], $e['price'], $e['days'], $e['price'], $e['days']]);
        }
    }
    q("UPDATE contracts SET status = 'active', contractor_id = ?, accepted_offer_id = ?, payment_type = ?, agreed_price = ?, agreed_days = ?, started_at = NOW(), agreed_deadline = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE id = ?",
        [$offer['user_id'], $offer['id'], $offer['payment_type'] ?? 'finish', $offer['price'], $offer['days'], $offer['days'], $contract['id']]);
    q("UPDATE offers SET status = 'accepted' WHERE id = ?", [$offer['id']]);
    delete_offers("contract_id = ? AND id <> ? AND status = 'pending'", [$contract['id'], $offer['id']]);
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
