# WordPress CLI setup script for BrewHQ Kounta development environment

Write-Host "Waiting for WordPress to be ready..." -ForegroundColor Yellow
Start-Sleep -Seconds 15

# Install WordPress
Write-Host "Installing WordPress..." -ForegroundColor Green
docker-compose exec -T wordpress wp core install `
  --url="http://localhost:8888" `
  --title="BrewHQ Development" `
  --admin_user="admin" `
  --admin_password="admin" `
  --admin_email="admin@example.com" `
  --skip-email `
  --allow-root

Write-Host "WordPress installed successfully!" -ForegroundColor Green

# Install and activate WooCommerce
Write-Host "Installing WooCommerce..." -ForegroundColor Green
docker-compose exec -T wordpress wp plugin install woocommerce --activate --allow-root

# Activate BrewHQ Kounta plugin
Write-Host "Activating BrewHQ Kounta plugin..." -ForegroundColor Green
docker-compose exec -T wordpress wp plugin activate brewhq-kounta --allow-root

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Setup complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Access your site at: http://localhost:8888" -ForegroundColor Yellow
Write-Host "Access admin at: http://localhost:8888/wp-admin" -ForegroundColor Yellow
Write-Host "Username: admin" -ForegroundColor Yellow
Write-Host "Password: admin" -ForegroundColor Yellow
Write-Host ""

