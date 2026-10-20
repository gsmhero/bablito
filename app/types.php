<?php
declare(strict_types=1);

// Legal status of a user: Company, Self employed or Personal. A user can have several.
const PARTY_TYPES = ['company' => 'Company', 'self' => 'Self employed', 'personal' => 'Personal'];

function party_label(?string $t): string
{
    return PARTY_TYPES[$t ?? ''] ?? PARTY_TYPES['personal'];
}

/** Statuses of a user, in the fixed order company, self, personal. Never empty. */
function user_types(?array $u): array
{
    $have = array_filter(explode(',', (string)($u['user_types'] ?? '')), fn($t) => isset(PARTY_TYPES[$t]));
    $out = array_values(array_filter(array_keys(PARTY_TYPES), fn($t) => in_array($t, $have, true)));
    return $out ?: ['personal'];
}

/** Statuses ticked in a form (array of keys); falls back to Personal. */
function types_from_post($post): array
{
    $sel = array_filter((array)$post, fn($t) => is_string($t) && isset(PARTY_TYPES[$t]));
    $out = array_values(array_filter(array_keys(PARTY_TYPES), fn($t) => in_array($t, $sel, true)));
    return $out ?: ['personal'];
}

/** Checkboxes "Company / Self employed / Personal" (registration and profile). */
function party_checkboxes(string $name, array $checked): string
{
    $o = '<div class="party-types">';
    foreach (PARTY_TYPES as $k => $lbl) {
        $o .= '<label class="check"><input type="checkbox" name="' . h($name) . '[]" value="' . h($k) . '"' . (in_array($k, $checked, true) ? ' checked' : '') . '> ' . h($lbl) . '</label>';
    }
    return $o . '</div>';
}

/** Dropdown "As Company / As Self employed / As Personal", limited to the user's statuses. */
function as_select(string $name, array $allowed, ?string $current, string $form = ''): string
{
    $cur = in_array($current, $allowed, true) ? $current : $allowed[0];
    $o = '<select name="' . h($name) . '"' . ($form !== '' ? ' form="' . h($form) . '"' : '') . '>';
    foreach ($allowed as $k) {
        $o .= '<option value="' . h($k) . '"' . ($k === $cur ? ' selected' : '') . '>As ' . h(mb_strtolower(PARTY_TYPES[$k])) . '</option>';
    }
    return $o . '</select>';
}

/** Small tag showing someone's status, e.g. "Company". */
function party_pill(?string $t): string
{
    return '<span class="pill party-pill">' . h(party_label($t)) . '</span>';
}

/** Sections of the profile editor that apply to a user with the given statuses. */
function sections_for(array $structure, array $types): array
{
    return array_values(array_filter($structure, fn($s) => in_array($s['type'] ?? 'personal', $types, true)));
}
