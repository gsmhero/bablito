<?php
declare(strict_types=1);

// Admin-defined profile sections and fields (see Admin mode -> User Profile Editor).

const PROFILE_FIELD_TYPES = ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'url' => 'Link (URL)', 'date' => 'Date', 'select' => 'Choice list'];

/** Sections with their fields: [ [id, title, position, fields => [ [...], ... ]], ... ] */
function profile_structure(): array
{
    $sections = rows('SELECT * FROM profile_sections ORDER BY position, id');
    $fields = rows('SELECT * FROM profile_fields ORDER BY position, id');
    $by = [];
    foreach ($fields as $f) {
        $by[(int)$f['section_id']][] = $f;
    }
    foreach ($sections as $i => $s) {
        $sections[$i]['fields'] = $by[(int)$s['id']] ?? [];
    }
    return $sections;
}

/** Stored values of one user: [field_id => ['value' => ..., 'is_public' => 0|1]] */
function profile_values(int $userId): array
{
    $out = [];
    foreach (rows('SELECT field_id, value, is_public FROM profile_values WHERE user_id = ?', [$userId]) as $r) {
        $out[(int)$r['field_id']] = $r;
    }
    return $out;
}

function profile_options(array $f): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$f['options'])), fn($o) => $o !== ''));
}

function profile_input_html(array $f, string $value): string
{
    $name = 'pf[' . (int)$f['id'] . '][value]';
    $req = $f['required'] ? ' required' : '';
    switch ($f['type']) {
        case 'textarea':
            return '<textarea name="' . $name . '" rows="3" maxlength="4000"' . $req . '>' . h($value) . '</textarea>';
        case 'number':
            return '<input type="number" step="any" name="' . $name . '" value="' . h($value) . '"' . $req . '>';
        case 'url':
            return '<input type="url" name="' . $name . '" maxlength="300" placeholder="https://" value="' . h($value) . '"' . $req . '>';
        case 'date':
            return '<input type="date" name="' . $name . '" value="' . h($value) . '"' . $req . '>';
        case 'select':
            $o = '<select name="' . $name . '"' . $req . '><option value="">-</option>';
            foreach (profile_options($f) as $opt) {
                $o .= '<option value="' . h($opt) . '"' . ($opt === $value ? ' selected' : '') . '>' . h($opt) . '</option>';
            }
            return $o . '</select>';
        default:
            return '<input name="' . $name . '" maxlength="190" value="' . h($value) . '"' . $req . '>';
    }
}

/** Validates and stores the profile form. Returns an error message or null. */
function profile_save_values(int $userId, array $structure, array $post): ?string
{
    $rows = [];
    foreach ($structure as $s) {
        foreach ($s['fields'] as $f) {
            $in = $post[$f['id']] ?? [];
            $v = trim((string)($in['value'] ?? ''));
            if ($v === '') {
                if ($f['required']) {
                    return 'Fill in the required field "' . $f['label'] . '".';
                }
            } elseif ($f['type'] === 'number' && !is_numeric($v)) {
                return '"' . $f['label'] . '" must be a number.';
            } elseif ($f['type'] === 'url' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v))) {
                return '"' . $f['label'] . '" must be a link starting with http:// or https://.';
            } elseif ($f['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return '"' . $f['label'] . '" must be a date.';
            } elseif ($f['type'] === 'select' && !in_array($v, profile_options($f), true)) {
                return '"' . $f['label'] . '" has an invalid choice.';
            } elseif (mb_strlen($v) > ($f['type'] === 'textarea' ? 4000 : 300)) {
                return '"' . $f['label'] . '" is too long.';
            }
            $rows[] = [(int)$f['id'], $v, empty($in['public']) ? 0 : 1];
        }
    }
    foreach ($rows as [$fid, $v, $pub]) {
        if ($v === '') {
            q('DELETE FROM profile_values WHERE user_id = ? AND field_id = ?', [$userId, $fid]);
        } else {
            q('REPLACE INTO profile_values (user_id, field_id, value, is_public) VALUES (?,?,?,?)', [$userId, $fid, $v, $pub]);
        }
    }
    return null;
}
