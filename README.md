# blog.trendafilovi.net

Minimal PHP blog for shared hosting.

## Requirements

- PHP 5.6+ recommended
- Apache with PHP enabled
- Writable `data/` directory
- Writable `media/` directory
- No MySQL required

## First run

Open:

```text
https://blog.trendafilovi.net/admin/
```

The setup screen creates the admin username/password and writes `data/config.php`.

## Writing posts

Use `/admin/` to create posts. Supported body markup:

- paragraphs separated by blank lines
- `# Heading`
- `## Heading`
- `- list item`
- `[link text](https://example.com)`
- `[Download file](/media/2026/file.pdf)`
- `![Alt text](/media/2026/photo.jpg)`
- `[video:/media/2026/movie.mp4]`
- inline `` `code` ``
- fenced code blocks with triple backticks

## Files

```text
index.php          public archive and posts
feed.php           RSS feed
admin/             browser editor
assets/style.css   public minimalist theme
data/posts/        Markdown post files
media/             uploaded public files
data/config.php    generated on first setup
```
