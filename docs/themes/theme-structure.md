# Theme Directory Structure

The recommended file layout for a production-ready Favorite CMS theme:

```
themes/my-theme/
├── theme.json            # Required metadata manifest
│
├── header.php            # Site header component (<head>, nav, brand)
├── footer.php            # Site footer component (credits, scripts)
├── sidebar.php           # Sidebar widgets component
│
├── index.php             # Homepage and main blog feed template
├── single.php            # Single post / article view
├── page.php              # Static page view
├── search.php            # Search results feed view
├── 404.php               # Friendly 404 page template
│
└── assets/
    ├── css/
    │   └── style.css     # Primary stylesheet
    ├── js/
    │   └── main.js       # Accessible navigation toggle
    └── images/           # Default logos and icons
```
