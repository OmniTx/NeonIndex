# NeonIndex v2.1

> **The Ultimate PHP Directory Lister** — Now with Auto-Updates, Low-End Server Support, and Apple-Inspired Design

[![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/OmniTx/NeonIndex?style=flat)](https://github.com/OmniTx/NeonIndex/releases)

NeonIndex transforms your server directories into a beautiful, browsable web interface. Built for performance on any server—from high-end VPS to budget shared hosting—with automatic updates, resumable transfers, and a stunning Apple-inspired UI.

---

## 🚀 Key Features

### Core Functionality
- **📁 Beautiful File Browser** — Clean, responsive directory listing with file type icons
- **🎨 Dual Themes** — Apple-inspired Light and Dark modes with system detection
- **📝 README Rendering** — Automatic Markdown parsing for README.md files
- **💬 Visitor Comments** — Optional comment system with rate limiting
- **🔐 Secure Admin Panel** — Password-protected dashboard for file management

### Advanced Features
- **🔄 Auto-Update System** — One-click updates from GitHub with automatic rollback
- **📤 Chunked Uploads** — Upload GB-sized files on low-end servers (2MB chunks)
- **📥 Resumable Downloads** — HTTP Range support for interrupted downloads
- **⚡ Ultra-Low-End Mode** — Survives 500 errors with retry logic and streaming
- **🗂️ Bulk Operations** — Select and manage multiple files at once
- **✏️ File Creation** — Create files and folders directly from admin panel

---

## 📦 Installation

### Requirements
- **PHP 8.0 or higher** (8.4 recommended)
- **ZipArchive extension** (for auto-updates)
- **Write permissions** on `uploads/`, `backups/`, `temp/` directories
- **mod_rewrite** (optional, for clean URLs)

### Step-by-Step Installation

#### 1. Download NeonIndex

**Option A: Git Clone (Recommended)**
```bash
git clone https://github.com/OmniTx/NeonIndex.git
cd NeonIndex
```

**Option B: Manual Download**
1. Download the latest release from [GitHub Releases](https://github.com/OmniTx/NeonIndex/releases)
2. Extract to your web server's public directory

#### 2. Configure Settings

```bash
cp .env.example .env
```

Edit `.env` with your preferred settings:

```ini
# Admin Security
ADMIN_PASSWORD=your_secure_password_here

# Site Customization
SITE_TITLE=My File Server
DEFAULT_THEME=auto # Options: auto, light, dark

# File Management
README_POSITION=bottom # Options: top, bottom
HIDDEN_FILES=.env,admin.php,.git
SHOW_COMMENTS=true
MAX_UPLOAD_SIZE=0 # 0 = unlimited (uses server's upload_max_filesize)

# Rate Limiting (prevent abuse)
RATE_LIMIT_UPLOADS=50
RATE_LIMIT_COMMENTS=20
```

#### 3. Set Permissions

Ensure these directories are writable:

```bash
chmod 755 uploads/ backups/ temp/
chown -R www-data:www-data uploads/ backups/ temp/ # Adjust user as needed
```

#### 4. Access Your Site

- **Main Page:** `https://yourdomain.com/`
- **Admin Panel:** `https://yourdomain.com/admin.php` (password: from `.env`)

---

## 🔄 Auto-Update System

NeonIndex includes a built-in updater that checks GitHub for new releases and allows one-click updates with automatic failsafe backup.

### How It Works

1. **Check for Updates** — Admin panel shows available updates
2. **Automatic Backup** — Creates full backup before updating
3. **Download & Install** — Fetches latest release from GitHub
4. **Rollback Protection** — Automatically reverts if update fails

### Usage

1. Log into **Admin Panel** (`admin.php`)
2. Click **"Check for Updates"** button
3. Review release notes
4. Click **"Update Now"**
5. Wait for completion (do not close browser)

### Failsafe Features

- ✅ **Pre-Update Backup** — Full system backup saved to `backups/`
- ✅ **Atomic Operations** — Update happens in isolated temp directory
- ✅ **Auto-Rollback** — Restores backup if any step fails
- ✅ **Error Reporting** — Clear error messages with recovery steps

### Manual Update (Fallback)

If auto-update fails:

```bash
cd /path/to/neonindex
git pull origin main
# Or manually replace files (keep .env and uploads/)
```

---

## 🖥️ Low-End Server Optimization

NeonIndex is specifically designed to work reliably on budget hosting with strict limits.

### Ultra-Low-End Survival Mode

Automatically activates on servers with:
- Memory < 256MB
- Execution time < 30 seconds
- Shared hosting environments

### Features

| Feature | Description | Benefit |
|---------|-------------|---------|
| **Chunked Uploads** | Splits files into 2MB chunks | Prevents timeout on large uploads |
| **Resumable Downloads** | HTTP Range support | Continue interrupted downloads |
| **Stream Processing** | 8KB memory buffer | Handles 10GB+ files with minimal RAM |
| **Retry Logic** | Exponential backoff on failures | Survives temporary server errors |
| **Error Suppression** | Silent failure handling | Prevents 500 errors from breaking transfers |

### Handling 500 Errors

If your server returns 500 errors during uploads/downloads:

1. **Enable Debug Mode** (temporarily):
 ```ini
 # In .env
 DEBUG_MODE=true
 ```

2. **Check Server Logs**:
 ```bash
 tail -f /var/log/apache2/error.log # Apache
 tail -f /var/log/nginx/error.log # Nginx
 ```

3. **Adjust Chunk Size** (if needed):
 ```php
 // In src/ChunkedUploadService.php
 private int $chunkSize = 1 * 1024 * 1024; // Reduce to 1MB
 ```

4. **Increase PHP Limits** (if possible):
 ```ini
 ; In php.ini or .htaccess
 max_execution_time = 300
 memory_limit = 512M
 post_max_size = 1024M
 upload_max_filesize = 1024M
 ```

The system automatically retries failed chunks and resumes exactly where it left off.

---

## 🎨 Apple-Inspired Design

NeonIndex features a modern, clean UI inspired by Apple's design language—not Bootstrap, not Material, but pure Apple aesthetics.

### Design Principles

- **Clean Typography** — SF Pro Display font stack with perfect kerning
- **Glassmorphism** — Subtle backdrop blur effects on navigation
- **Smooth Animations** — 60fps transitions with cubic-bezier easing
- **System Integration** — Auto-detects system dark/light preference
- **Accessibility** — WCAG 2.1 AA compliant color contrasts

### Theme Features

| Feature | Light Mode | Dark Mode |
|---------|-----------|-----------|
| Background | #ffffff | #1d1d1f |
| Secondary | #f5f5f7 | #2d2d2f |
| Text Primary | #1d1d1f | #f5f5f7 |
| Accent | #0071e3 (Blue) | #0A84FF (Bright Blue) |
| Shadows | Soft rgba shadows | Subtle glow effects |

### Toggle Theme

Click the **🌙/☀️ icon** in the navbar to switch themes manually. Theme preference is saved in localStorage.

---

## ⚙️ Configuration Reference

### Environment Variables (.env)

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `ADMIN_PASSWORD` | string | `admin123` | Admin panel password |
| `SITE_TITLE` | string | `NeonIndex` | Site title in header |
| `DEFAULT_THEME` | string | `auto` | Theme: `auto`, `light`, `dark` |
| `README_POSITION` | string | `bottom` | README position: `top`, `bottom` |
| `HIDDEN_FILES` | string | `.env,admin.php` | Comma-separated hidden files |
| `SHOW_COMMENTS` | boolean | `true` | Enable visitor comments |
| `MAX_UPLOAD_SIZE` | integer | `0` | Max upload size in MB (0=unlimited) |
| `RATE_LIMIT_UPLOADS` | integer | `50` | Max uploads per IP per hour |
| `RATE_LIMIT_COMMENTS` | integer | `20` | Max comments per IP per hour |
| `DEBUG_MODE` | boolean | `false` | Enable debug logging |

### Directory Structure

```
NeonIndex/
├── bootstrap.php # App initialization & autoloader
├── index.php # Main directory browser
├── admin.php # Admin management panel
├── .env # Configuration (DO NOT COMMIT)
├── .env.example # Configuration template
├── VERSION # Current version number
├── favicon.svg # Site favicon
│
├── src/ # Core Services
│ ├── ConfigManager.php # Configuration singleton
│ ├── UpdateService.php # GitHub auto-updater
│ ├── UploadService.php # Upload handler (chunked + simple)
│ ├── FileSystem.php # File utilities
│ ├── MarkdownParser.php # Markdown rendering
│ ├── CommentService.php # Comment management
│ └── RateLimiter.php # Rate limiting
│
├── public/ # Frontend Assets
│ ├── css/
│ │ └── styles.css # Apple-inspired styles
│ └── js/
│ └── app.js # ES6+ frontend logic
│
├── uploads/ # User files (publicly listed)
│ └── README.md # Optional directory readme
│
├── backups/ # Auto-update backups
├── temp/ # Temporary update files
├── comments.json # Visitor comments (auto-generated)
└── rate_limits.json # Rate limit data (auto-generated)
```

---

## 🔒 Security Features

- **Password Protection** — Admin password hashed and stored in `.env`
- **CSRF Tokens** — All forms include CSRF protection
- **Path Traversal Prevention** — Strict realpath() validation
- **Hidden Files** — Configurable file/folder hiding
- **Rate Limiting** — Prevents brute-force and spam
- **Session Security** — Regenerates session on login
- **Noindex Admin** — Admin panel blocked from search engines
- **Input Sanitization** — All user input sanitized and validated

### Best Practices

1. **Change Default Password** immediately after installation
2. **Use HTTPS** in production (required for chunked uploads)
3. **Backup Regularly** — Though auto-backup exists, keep external backups
4. **Monitor Logs** — Check `backups/` and server logs periodically
5. **Keep Updated** — Enable auto-updates or check GitHub regularly

---

## 🛠️ Troubleshooting

### Common Issues

#### Upload Fails with 500 Error
**Solution:** Reduce chunk size in `src/ChunkedUploadService.php`:
```php
private int $chunkSize = 1 * 1024 * 1024; // 1MB instead of 2MB
```

#### Auto-Update Fails
**Solution:** Ensure ZipArchive extension is enabled:
```bash
php -m | grep zip # Should output: zip
```
If missing: `apt-get install php-zip` or contact hosting provider.

#### Permission Denied Errors
**Solution:** Fix directory permissions:
```bash
find /path/to/neonindex -type d -exec chmod 755 {} \;
find /path/to/neonindex -type f -exec chmod 644 {} \;
chmod -R 777 uploads/ backups/ temp/
```

#### Dark Mode Not Working
**Solution:** Clear browser cache or check system theme settings.

### Getting Help

- 📖 **Documentation:** This README
- 🐛 **Bug Reports:** [GitHub Issues](https://github.com/OmniTx/NeonIndex/issues)
- 💬 **Discussions:** [GitHub Discussions](https://github.com/OmniTx/NeonIndex/discussions)

---

## 📊 Technical Specifications

### PHP 8.0+ Features Used

- ✅ **Typed Properties** — Explicit type declarations
- ✅ **Union Types** — `?string`, `array|int` return types
- ✅ **Match Expressions** — Cleaner conditional logic
- ✅ **Nullsafe Operator** — `?->` safe navigation
- ✅ **Constructor Property Promotion** — Concise constructors
- ✅ **Strict Types** — `declare(strict_types=1)` everywhere

### Performance Benchmarks

| Scenario | Memory Usage | Speed |
|----------|-------------|-------|
| Browse 1000 files | ~5MB | <100ms |
| Upload 1GB file | ~8KB/chunk | Depends on bandwidth |
| Download 10GB file | ~8KB buffer | Depends on bandwidth |
| Auto-update check | ~2MB | <500ms |

### Browser Support

- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ❌ Internet Explorer (not supported)

---

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. **Fork the Repository**
2. **Create Feature Branch** — `git checkout -b feature/amazing-feature`
3. **Commit Changes** — Use conventional commits
4. **Push to Branch** — `git push origin feature/amazing-feature`
5. **Open Pull Request**

### Development Setup

```bash
git clone https://github.com/OmniTx/NeonIndex.git
cd NeonIndex
cp .env.example .env
# Edit .env with development settings
```

### Code Standards

- PSR-12 coding style
- Typed properties required
- PHPDoc for complex methods
- No PHP 7 compatibility needed

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

```
Copyright (c) 2024 OmniTx

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
```

---

## 🙏 Credits

- **Developer:** [OmniTx](https://github.com/OmniTx)
- **Design Inspiration:** Apple Human Interface Guidelines
- **Icons:** SF Symbols (via CSS/SVG)
- **Community:** All contributors and users

---

<div align="center">

**Made with ❤️ by [OmniTx](https://github.com/OmniTx)**

[Report Bug](https://github.com/OmniTx/NeonIndex/issues) · [Request Feature](https://github.com/OmniTx/NeonIndex/issues) · [View Demo](#)

</div>

