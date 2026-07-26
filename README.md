# Just A (minimalist) blog
Minimal PHP blog for shared hosting.

## Requirements

- PHP 7.1+ (the production host currently runs PHP 7.4)
- Apache with PHP enabled
- Writable `data/` directory
- Writable `media/` directory
- No MySQL required

## First run

Open:

```text
https://{blog_url}/admin/
```

The setup screen creates the admin username/password and writes `data/config.php`.

## Writing posts

Use `/admin/` to create posts. Supported body markup:

- paragraphs separated by blank lines
- `# Heading` through `###### Heading`
- `- unordered list item`
- `1. ordered list item`
- `> blockquote`
- `***` horizontal rule
- `**bold**`, `*italic*`, and `~~strikethrough~~`
- `[link text](https://example.com)`
- `[Download file](/media/2026/file.pdf)`
- `![Alt text](/media/2026/photo.jpg)`
- `[video:/media/2026/movie.mp4]`
- `[color:red]colored text[/color]`
- `[color:#b42318]custom hex color[/color]`
- inline `` `code` ``
- fenced code blocks with triple backticks
- tables

Post body headings are automatically placed below the post title in the
document hierarchy. Raw HTML is escaped for safety.

## Files

```text
index.php          public archive and posts
feed.php           RSS feed
admin/             browser editor
assets/style.css   public minimalist theme
data/posts/        Markdown post files
media/             uploaded public files
data/config.php    generated on first setup
vendor/            Parsedown 1.8.0 and its MIT license
```
