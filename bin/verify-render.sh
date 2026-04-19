#!/bin/bash

# ERNI PMS - Render Deployment Verification
# This script verifies your app is ready for Render deployment

echo "🔍 Checking Render deployment readiness..."
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

errors=0

# Check required files
echo "📋 Checking required files..."

files=(
    "render.yaml"
    "Procfile"
    "bin/build.sh"
    "bin/migrate-db.sh"
    "database.sql"
    "config/.env.php"
    ".gitignore"
)

for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓${NC} $file"
    else
        echo -e "${RED}✗${NC} $file (MISSING)"
        errors=$((errors + 1))
    fi
done

echo ""
echo "🔐 Checking directory permissions..."

dirs=(
    "git"
    "git-workspaces"
    "uploads"
    "logs"
)

for dir in "${dirs[@]}"; do
    if [ -d "$dir" ]; then
        echo -e "${GREEN}✓${NC} $dir exists"
    else
        echo -e "${YELLOW}⚠${NC} $dir will be created during build"
    fi
done

echo ""
echo "📝 Checking PHP configuration..."

# Check if php is available
if command -v php &> /dev/null; then
    php_version=$(php -v | head -n 1)
    echo -e "${GREEN}✓${NC} PHP available: $php_version"
else
    echo -e "${RED}✗${NC} PHP not found in PATH"
    errors=$((errors + 1))
fi

echo ""
echo "🐙 Checking Git configuration..."

if command -v git &> /dev/null; then
    git_version=$(git --version)
    echo -e "${GREEN}✓${NC} Git available: $git_version"
    
    # Check if git repo is initialized
    if [ -d ".git" ]; then
        echo -e "${GREEN}✓${NC} Git repository initialized"
    else
        echo -e "${RED}✗${NC} Not a git repository (run: git init)"
        errors=$((errors + 1))
    fi
else
    echo -e "${RED}✗${NC} Git not found in PATH"
    errors=$((errors + 1))
fi

echo ""
echo "🔗 Checking git remote..."

remote=$(git remote -v 2>/dev/null | grep origin | head -n 1)
if [ ! -z "$remote" ]; then
    echo -e "${GREEN}✓${NC} Git remote configured"
    echo "  $remote"
else
    echo -e "${YELLOW}⚠${NC} No git remote found (needed for Render deployment)"
    echo "  Add with: git remote add origin <your-github-url>"
fi

echo ""
echo "📊 Checking database schema..."

if [ -f "database.sql" ]; then
    lines=$(wc -l < database.sql)
    tables=$(grep -c "CREATE TABLE" database.sql || true)
    echo -e "${GREEN}✓${NC} database.sql found ($tables tables, $lines lines)"
else
    echo -e "${RED}✗${NC} database.sql not found"
    errors=$((errors + 1))
fi

echo ""
echo "📋 Configuration Summary:"
echo "========================"
echo "Deployment Platform: Render"
echo "Database: MySQL"
echo "Repository: GitHub"
echo "Build Command: bash bin/build.sh"
echo "Start Command: php -S 0.0.0.0:\$PORT"
echo ""

if [ $errors -eq 0 ]; then
    echo -e "${GREEN}✅ All checks passed! Ready for Render deployment${NC}"
    echo ""
    echo "Next steps:"
    echo "1. Commit changes: git add . && git commit -m 'Add Render config'"
    echo "2. Push to GitHub: git push origin main"
    echo "3. Go to Render.com and create a new Blueprint"
    echo "4. Select your repository and deploy!"
else
    echo -e "${RED}❌ Found $errors issue(s)${NC}"
    echo ""
    echo "Please fix the issues above before deploying to Render"
    exit 1
fi
