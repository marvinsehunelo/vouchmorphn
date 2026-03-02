#!/bin/bash
echo "🔧 Fixing permissions for VouchMorph callback directory..."

# Current directory
CALLBACK_DIR="$(pwd)"
echo "Directory: $CALLBACK_DIR"

# Fix ownership - try different common web server users
if id "daemon" &>/dev/null; then
    sudo chown -R daemon:daemon "$CALLBACK_DIR"
    echo "✅ Set owner to daemon:daemon"
elif id "www-data" &>/dev/null; then
    sudo chown -R www-data:www-data "$CALLBACK_DIR"
    echo "✅ Set owner to www-data:www-data"
elif id "nobody" &>/dev/null; then
    sudo chown -R nobody:nobody "$CALLBACK_DIR"
    echo "✅ Set owner to nobody:nobody"
else
    echo "⚠️ Could not find web server user, using current user"
    sudo chown -R $(whoami):$(whoami) "$CALLBACK_DIR"
fi

# Set directory permissions
sudo chmod -R 755 "$CALLBACK_DIR"
echo "✅ Set directory permissions to 755"

# Create and set log file permissions
sudo touch "$CALLBACK_DIR/ttk_debug.log"
sudo chmod 666 "$CALLBACK_DIR/ttk_debug.log"
echo "✅ Created ttk_debug.log with 666 permissions"

# Also fix the parent directory permissions for safety
sudo chmod 755 /opt/lampp/htdocs/vouchmorphn/public/api
sudo chmod 755 /opt/lampp/htdocs/vouchmorphn/public

echo ""
echo "📋 Current permissions:"
ls -la "$CALLBACK_DIR"

echo ""
echo "✅ Done! Now run your TTK tests again."
