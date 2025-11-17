#!/bin/bash
# WordPress CLI setup script for BrewHQ Kounta development environment

echo "Waiting for WordPress to be ready..."
sleep 10

# Install WordPress
docker-compose exec -T wordpress wp core install \
  --url="http://localhost:8888" \
  --title="BrewHQ Development" \
  --admin_user="admin" \
  --admin_password="admin" \
  --admin_email="admin@example.com" \
  --skip-email \
  --allow-root

echo "WordPress installed successfully!"
echo "Admin URL: http://localhost:8888/wp-admin"
echo "Username: admin"
echo "Password: admin"

# Install and activate WooCommerce
echo "Installing WooCommerce..."
docker-compose exec -T wordpress wp plugin install woocommerce --activate --allow-root

# Activate BrewHQ Kounta plugin
echo "Activating BrewHQ Kounta plugin..."
docker-compose exec -T wordpress wp plugin activate brewhq-kounta --allow-root

echo "Setup complete!"
echo ""
echo "Access your site at: http://localhost:8888"
echo "Access admin at: http://localhost:8888/wp-admin"
echo "Username: admin"
echo "Password: admin"

