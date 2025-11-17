# BrewHQ Kounta Development Environment

## Quick Start

### Prerequisites
- Docker Desktop installed and running
- Node.js (v16 or higher)
- npm

### Starting the Development Environment

1. **Start Docker containers:**
   ```bash
   npm run docker:up
   ```
   Or directly with Docker Compose:
   ```bash
   docker-compose up -d
   ```

2. **Access WordPress:**
   - WordPress Site: http://localhost:8888
   - WordPress Admin: http://localhost:8888/wp-admin
   - phpMyAdmin: http://localhost:8080

3. **Initial WordPress Setup:**
   - Visit http://localhost:8888
   - Complete the WordPress installation wizard
   - Default database credentials (already configured):
     - Database: `wordpress`
     - Username: `wordpress`
     - Password: `wordpress`

4. **Install WooCommerce:**
   - Log into WordPress admin
   - Go to Plugins > Add New
   - Search for "WooCommerce"
   - Install and activate

5. **Activate BrewHQ Kounta Plugin:**
   - The plugin is automatically mounted in the container
   - Go to Plugins in WordPress admin
   - Find "BrewHQ Kounta" and activate it

### Useful Commands

```bash
# Start containers
npm run docker:up

# Stop containers
npm run docker:down

# View WordPress logs
npm run docker:logs

# Restart containers
npm run docker:restart

# Stop and remove all data (clean slate)
npm run docker:clean
```

### Database Access

**phpMyAdmin:**
- URL: http://localhost:8080
- Server: `db`
- Username: `root`
- Password: `rootpassword`

### Plugin Development

The plugin directory is mounted as a volume, so any changes you make to the files will be immediately reflected in the WordPress installation.

**Plugin location in container:** `/var/www/html/wp-content/plugins/brewhq-kounta`

### Debugging

WordPress debugging is enabled by default:
- `WP_DEBUG`: true
- `WP_DEBUG_LOG`: true (logs to `/var/www/html/wp-content/debug.log`)
- `WP_DEBUG_DISPLAY`: false
- `SCRIPT_DEBUG`: true

To view debug logs:
```bash
docker-compose exec wordpress cat /var/www/html/wp-content/debug.log
```

### Troubleshooting

**Containers won't start:**
```bash
# Check if ports 8888 or 8080 are already in use
docker ps -a

# Clean up and restart
npm run docker:clean
npm run docker:up
```

**Plugin not showing up:**
```bash
# Restart WordPress container
docker-compose restart wordpress
```

**Database connection issues:**
```bash
# Check database container logs
docker-compose logs db
```

### Network Issues (Proxy)

If you encounter network/proxy issues with npm or wp-env:
```bash
# Remove proxy settings
npm config delete proxy
npm config delete https-proxy
```

## Alternative: wp-env

If you prefer to use `@wordpress/env` instead of Docker Compose:

```bash
# Note: Requires network access to download WordPress
npm run env:start
```

This may fail if you have network/proxy issues. Use Docker Compose method above instead.

