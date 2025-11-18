# Build & Deployment Guide

## 🚀 Quick Start

### Build the Plugin

```bash
npm run build
```

This creates a clean deployment package in `dist/brewhq-kounta/` with only the necessary plugin files.

### Build and Create ZIP

```bash
npm run build:zip
```

This builds the plugin AND creates a `dist/brewhq-kounta.zip` file ready for upload via WordPress admin.

### Clean Build Directory

```bash
npm run clean
```

Removes the `dist/` directory.

---

## 📦 What Gets Included

The build script includes only these files/directories:

✅ **Included:**
- `brewhq-kounta.php` - Main plugin file
- `LICENSE` - License file
- `Licensing/` - License documentation
- `admin/` - Admin interface (PHP, CSS, JS)
- `assets/` - Frontend assets (CSS, images, JS)
- `includes/` - Core functionality (all PHP classes)

❌ **Excluded:**
- All `.md` files (documentation)
- `node_modules/`
- `dist/`
- `documentation/`
- `docker-compose.yml`
- `package.json` and `package-lock.json`
- `build.js`
- `setup-wordpress.*` scripts
- `.git` files
- OS files (`.DS_Store`, `Thumbs.db`, etc.)

---

## 📋 Build Output

After running `npm run build`, you'll see:

```
🚀 Building BrewHQ Kounta plugin...

🧹 Cleaning previous build...
  ✓ Cleaned

📁 Creating build directory...
  ✓ Created

📦 Copying plugin files...
  ✓ Copied: brewhq-kounta.php
  ✓ Copied: LICENSE
  ✓ Copied: Licensing/GPL.txt
  ✓ Copied: Licensing/README_License.txt
  ✓ Copied: admin/class-kounta-order-logs-page.php
  ... (more files)
  ⊗ Skipping: admin/README.md

✅ Build complete!

📊 Build Summary:
  Location: dist/brewhq-kounta
  Files: 45
  Size: 1.2 MB

📦 Ready to deploy!
  Upload the contents of 'dist/brewhq-kounta' to your WordPress plugins directory.
```

---

## 🚢 Deployment Methods

### Method 1: FTP/SFTP Upload

1. Build the plugin:
   ```bash
   npm run build
   ```

2. Connect to your WordPress site via FTP/SFTP

3. Navigate to `/wp-content/plugins/`

4. Upload the entire `dist/brewhq-kounta/` folder

5. Overwrite existing files when prompted

### Method 2: WordPress Admin Upload

1. Build and create ZIP:
   ```bash
   npm run build:zip
   ```

2. In WordPress admin:
   - Go to **Plugins** → **Add New** → **Upload Plugin**
   - Choose `dist/brewhq-kounta.zip`
   - Click **Install Now**
   - Activate or update the plugin

### Method 3: Direct Copy (Local/Staging)

1. Build the plugin:
   ```bash
   npm run build
   ```

2. Copy to WordPress plugins directory:
   ```bash
   # Windows (PowerShell)
   Copy-Item -Path "dist\brewhq-kounta\*" -Destination "C:\path\to\wordpress\wp-content\plugins\brewhq-kounta\" -Recurse -Force

   # Linux/Mac
   cp -r dist/brewhq-kounta/* /path/to/wordpress/wp-content/plugins/brewhq-kounta/
   ```

---

## ⚠️ Pre-Deployment Checklist

Before deploying to production:

- [ ] **Backup database** (especially custom tables)
- [ ] **Backup current plugin folder** on live site
- [ ] **Test in staging** if available
- [ ] **Verify PHP version** (requires 7.2+)
- [ ] **Check WooCommerce is active**
- [ ] **Review recent changes** in git log

---

## 🔍 Verify Build Contents

To verify what's in the build:

### Windows (PowerShell)
```powershell
Get-ChildItem -Path dist\brewhq-kounta -Recurse -File | Select-Object FullName
```

### Linux/Mac
```bash
find dist/brewhq-kounta -type f
```

### Count Files
```bash
# Windows (PowerShell)
(Get-ChildItem -Path dist\brewhq-kounta -Recurse -File).Count

# Linux/Mac
find dist/brewhq-kounta -type f | wc -l
```

---

## 🛠️ Troubleshooting

### Build fails with "Cannot find module"

Make sure you've installed dependencies:
```bash
npm install
```

### Build includes unwanted files

Check the `EXCLUDE_PATTERNS` array in `build.js` and add patterns as needed.

### ZIP creation fails

The `build:zip` command uses PowerShell on Windows. If you're on Linux/Mac, use:
```bash
npm run build
cd dist
zip -r brewhq-kounta.zip brewhq-kounta/
```

### Permission errors during build

Make sure you have write permissions in the project directory:
```bash
# Windows (PowerShell - Run as Administrator)
icacls . /grant Users:F /t

# Linux/Mac
chmod -R 755 .
```

---

## 📊 Build Script Details

The build script (`build.js`) performs these steps:

1. **Clean** - Removes previous `dist/` directory
2. **Create** - Creates `dist/brewhq-kounta/` directory
3. **Copy** - Copies only included files/directories
4. **Filter** - Skips excluded patterns (documentation, dev files)
5. **Report** - Shows summary (file count, size)

---

## 🔄 Continuous Deployment

For automated deployments, you can integrate the build script into your CI/CD pipeline:

```yaml
# Example GitHub Actions workflow
- name: Build plugin
  run: npm run build

- name: Create release ZIP
  run: npm run build:zip

- name: Upload artifact
  uses: actions/upload-artifact@v3
  with:
    name: brewhq-kounta
    path: dist/brewhq-kounta.zip
```

---

## 📝 Version Management

Before building for production:

1. Update version in `brewhq-kounta.php`:
   ```php
   * Version: 2.0.1
   ```

2. Update version in `package.json`:
   ```json
   "version": "2.0.1"
   ```

3. Tag the release in git:
   ```bash
   git tag -a v2.0.1 -m "Release version 2.0.1"
   git push origin v2.0.1
   ```

---

**Last Updated:** 2024-01-15  
**Build Script Version:** 1.0

