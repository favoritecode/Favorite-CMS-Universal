# Theme Security Best Practices

Because themes directly output markup to visitors, theme authors must prevent Cross-Site Scripting (XSS).

---

## 1. Escaping Output

Every dynamic variable printed into HTML must be escaped:

```php
<!-- SAFE -->
<h1><?php echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8'); ?></h1>

<!-- UNSAFE: NEVER DO THIS -->
<h1><?php echo $post->title; ?></h1>
```

---

## 2. Rendering Rich Post Content Safely

To render HTML formatting authored in the post editor, always use `clean_post_content()`:

```php
<div class="entry-content">
    <?php echo clean_post_content($post->content); ?>
</div>
```
This helper allows safe formatting tags (`<p>`, `<h2>`, `<blockquote>`, `<code>`, `<img>`) while stripping inline event handlers (`onload`, `onerror`) and `javascript:` URIs.

---

## 3. Escaping URL Attributes

When outputting dynamic URLs in `href` or `src`:
```php
<a href="<?php echo htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8'); ?>">
```
Never allow unsanitized protocols.
