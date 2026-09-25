<?php

declare(strict_types=1);

/**
 * View helpers for standalone screens (installer and authentication).
 * Returned as closures so no global functions or autoloading changes are introduced.
 *
 * @return array{e: \Closure, field: \Closure}
 */

$e = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

/**
 * Render an accessible labelled input.
 *
 * Keys: id, name, label, type, value, required, optional, hint, hint_html, placeholder,
 *       autocomplete, attrs (array), toggle (password visibility), describedby (extra ids), error (server message).
 */
$field = static function (array $options) use ($e): string {
    $id = (string)$options['id'];
    $type = (string)($options['type'] ?? 'text');
    $required = !empty($options['required']);
    $hintHtml = isset($options['hint_html']) ? (string)$options['hint_html'] : (isset($options['hint']) ? $e($options['hint']) : '');
    $serverError = (string)($options['error'] ?? '');

    $describedBy = [];
    if ($hintHtml !== '') {
        $describedBy[] = $id . '-hint';
    }
    foreach ((array)($options['describedby'] ?? []) as $extraId) {
        $describedBy[] = (string)$extraId;
    }
    $describedBy[] = $id . '-error';

    $attributes = '';
    foreach (['placeholder', 'autocomplete'] as $simple) {
        if (isset($options[$simple])) {
            $attributes .= ' ' . $simple . '="' . $e($options[$simple]) . '"';
        }
    }
    foreach ((array)($options['attrs'] ?? []) as $name => $value) {
        if ($value === false || $value === null) {
            continue;
        }
        $attributes .= $value === true ? ' ' . $e($name) : ' ' . $e($name) . '="' . $e($value) . '"';
    }

    $label = $e($options['label']);
    if ($required) {
        $label .= ' <span class="fc-label__req" aria-hidden="true">*</span>';
    } elseif (!empty($options['optional'])) {
        $label .= ' <span class="fc-optional">(optional)</span>';
    }

    $control = '<input class="fc-input" type="' . $e($type) . '" id="' . $e($id) . '" name="' . $e($options['name'] ?? $id) . '"'
        . (array_key_exists('value', $options) ? ' value="' . $e($options['value']) . '"' : '')
        . ($required ? ' required' : '')
        . ($serverError !== '' ? ' aria-invalid="true"' : '')
        . ' aria-describedby="' . $e(implode(' ', $describedBy)) . '"'
        . $attributes . '>';

    if (!empty($options['toggle'])) {
        $toggleLabel = strtolower((string)$options['label']);
        $control = '<div class="fc-input-group">' . $control
            . '<button type="button" class="fc-input-toggle" data-toggle-password="' . $e($id) . '" data-label="' . $e($toggleLabel) . '"'
            . ' aria-controls="' . $e($id) . '" aria-pressed="false" aria-label="Show ' . $e($toggleLabel) . '" hidden>Show</button></div>';
    }

    return '<div class="fc-field">'
        . '<label class="fc-label" for="' . $e($id) . '">' . $label . '</label>'
        . $control
        . ($hintHtml !== '' ? '<p class="fc-hint" id="' . $e($id) . '-hint">' . $hintHtml . '</p>' : '')
        . '<p class="fc-field-error" id="' . $e($id) . '-error"' . ($serverError !== '' ? '' : ' hidden') . '>' . $e($serverError) . '</p>'
        . '</div>';
};

return ['e' => $e, 'field' => $field];
