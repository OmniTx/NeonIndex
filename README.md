# NeonIndex

A modern, sleek PHP directory lister with neon-themed UI, admin panel, and file management capabilities.

![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat&logo=php&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat&logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat)

## ✨ Features

- **🎨 Beautiful Themes** - Dark (Neon Cyan) and Light themes with smooth transitions
- **📁 File Browser** - Clean, responsive directory listing with file icons
- **� Admin Panel** - Secure dashboard for managing files and settings
- **📝 README Rendering** - Automatic Markdown parsing for README.md files
- **� Visitor Comments** - Optional comment system for visitors
- **📤 File Upload** - Upload files directly through the browser
- **🗂️ Bulk Operations** - Select and delete/move multiple files at once
- **✏️ File/Folder Creation** - Create new files and directories from admin panel
- **⚙️ Configurable** - All settings stored in `.env` file

## 🚀 Quick Start

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/NeonIndex.git
   cd NeonIndex
   ```

2. **Configure settings**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set your admin password and preferences.

3. **Upload to server**
   
   Upload all files to your web server's public directory.

4. **Access your site**
   - Main page: `https://yourdomain.com/`
   - Admin panel: `https://yourdomain.com/admin.php`

## ⚙️ Configuration

All settings are stored in `.env`:

| Setting | Description | Default |
|---------|-------------|---------|
| `ADMIN_PASSWORD` | Admin login password | `admin123` |
| `SITE_TITLE` | Site title in header | `NeonIndex` |
| `DEFAULT_THEME` | Default theme (`dark`/`light`) | `dark` |
| `README_POSITION` | README placement (`top`/`bottom`) | `bottom` |
| `HIDDEN_FILES` | Files to hide from visitors | `.env,admin.php` |
| `SHOW_COMMENTS` | Enable visitor comments | `true` |

## 📁 File Structure

```
NeonIndex/
├── index.php          # Main directory lister
├── admin.php          # Admin panel
├── .env               # Configuration (not in git)
├── .env.example       # Configuration template
├── .gitignore         # Git ignore rules
├── uploads/           # Files to be listed
│   └── README.md      # Optional readme
└── comments.json      # Visitor comments (auto-generated)
```

## 🔒 Security

- Admin password stored in `.env` (never commit this file!)
- CSRF protection on all forms
- Path traversal prevention
- Hidden files are not accessible to visitors
- Admin panel protected with `noindex, nofollow`

## 📄 License

MIT License - feel free to use and modify!

---

**Made with 💚 by [OmniTx](https://github.com/OmniTx)**
