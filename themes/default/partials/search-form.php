<?php
/**
 * Search form.
 *
 * @var string      $inputId      Unique input id
 * @var string|null $formClass    Extra CSS class for the form
 * @var string|null $placeholder
 * @var string|null $query        Current query value
 * @var bool|null   $required
 */
$inputId     = $inputId ?? 'search-input';
$formClass   = trim('search-form ' . ($formClass ?? ''));
$placeholder = $placeholder ?? 'Search articles…';
$rawQuery    = $query ?? ($_GET['q'] ?? '');
$queryValue  = is_string($rawQuery) ? $rawQuery : '';
?>
<form method="get" action="<?php echo fcd_e(fcd_url('/search')); ?>" class="<?php echo fcd_e($formClass); ?>" role="search">
    <label class="visually-hidden" for="<?php echo fcd_e($inputId); ?>">Search</label>
    <input type="search" id="<?php echo fcd_e($inputId); ?>" name="q" class="search-form__input" value="<?php echo fcd_e($queryValue); ?>" placeholder="<?php echo fcd_e($placeholder); ?>"<?php echo !empty($required) ? ' required' : ''; ?>>
    <button type="submit" class="search-form__button">
        <svg class="icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
        <span class="visually-hidden">Search</span>
    </button>
</form>
