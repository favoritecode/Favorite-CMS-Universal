# Content Sanitization & XSS Defense

Cross-Site Scripting (XSS) allows attackers to execute malicious JavaScript in the context of another user's session. Favorite CMS Universal neutralizes XSS using strict input sanitization and contextual output escaping.

---

## 1. Input Sanitization (`ContentSanitizer`)

When authors and contributors submit articles through the dual-mode post editor, content passes through `FavoriteCMS\Services\ContentSanitizer` before being written to the database.

### Sanitization Rules
1. **Forbidden Tags**:
   - `<script>`, `<style>`, `<iframe>` (except explicitly whitelisted video embeds), `<object>`, `<embed>`, `<applet>`, `<meta>`, `<link>`, `<form>`, `<input>`, `<button>`.
2. **Forbidden Attributes**:
   - All inline JavaScript event handlers (e.g. `onload=`, `onclick=`, `onerror=`, `onmouseover=`) are stripped entirely.
3. **Malicious URL Protocols**:
   - Hyperlinks (`href`) and image sources (`src`) starting with `javascript:`, `vbscript:`, or `data:text/html` are sanitized or stripped.
4. **Whitelisted HTML Elements**:
   - Standard formatting: `<p>`, `<br>`, `<strong>`, `<b>`, `<em>`, `<i>`, `<u>`, `<s>`, `<h1>` to `<h6>`.
   - Structural elements: `<blockquote>`, `<pre>`, `<code>`, `<hr>`.
   - Lists: `<ul>`, `<ol>`, `<li>`.
   - Tables: `<table>`, `<thead>`, `<tbody>`, `tr>`, `<th>`, `<td>`.
   - Media: `<img>` (safe image protocols), `<video>` (controls, source), `<audio>`.
   - Safe Links: `<a href="..." target="..." rel="...">`.

---

## 2. Unfiltered HTML Permission

In multi-author environments, standard contributors (`subscriber`, `author`) cannot publish raw JavaScript or unrestricted markup.

Only trusted roles (`super-admin` or accounts with the `unfiltered_html` capability) can publish unrestricted embed codes (e.g. raw advertising scripts, third-party analytics widgets, or custom tracking pixels).

---

## 3. Output Escaping

Whenever database strings (titles, user names, category labels, search terms) are printed in HTML templates, they are passed through `htmlspecialchars()`:

```php
// Standard safe output pattern:
echo htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8');
```

This prevents stored XSS attacks if any unexpected character sequences exist in taxonomy or metadata fields.

