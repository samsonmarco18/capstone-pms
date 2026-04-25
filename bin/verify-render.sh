#!/bin/sh

echo "Checking Render deployment readiness..."
echo ""

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

errors=0

print_status() {
    color="$1"
    label="$2"
    message="$3"
    printf '%b%s%b %s\n' "$color" "$label" "$NC" "$message"
}

echo "Checking required files..."

files="
render.yaml
Procfile
Dockerfile
docker/apache-render-port.sh
bin/migrate-db.sh
bin/migrate-db-postgres.sh
database.sql
database.postgres.sql
config/.env.php
.gitignore
"

for file in $files; do
    if [ -f "$file" ]; then
        print_status "$GREEN" "OK" "$file"
    else
        print_status "$RED" "MISSING" "$file"
        errors=$((errors + 1))
    fi
done

echo ""
echo "Checking runtime directories..."

dirs="
git
git-workspaces
uploads
logs
"

for dir in $dirs; do
    if [ -d "$dir" ]; then
        print_status "$GREEN" "OK" "$dir exists"
    else
        print_status "$YELLOW" "WARN" "$dir will be created at runtime"
    fi
done

echo ""
echo "Checking PHP configuration..."

if command -v php >/dev/null 2>&1; then
    php_version=$(php -v | head -n 1)
    print_status "$GREEN" "OK" "PHP available: $php_version"
else
    print_status "$RED" "MISSING" "PHP not found in PATH"
    errors=$((errors + 1))
fi

echo ""
echo "Checking Git configuration..."

if command -v git >/dev/null 2>&1; then
    git_version=$(git --version)
    print_status "$GREEN" "OK" "Git available: $git_version"

    if [ -d ".git" ]; then
        print_status "$GREEN" "OK" "Git repository initialized"
    else
        print_status "$RED" "MISSING" "Not a git repository"
        errors=$((errors + 1))
    fi
else
    print_status "$RED" "MISSING" "Git not found in PATH"
    errors=$((errors + 1))
fi

echo ""
echo "Checking git remote..."

remote=$(git remote -v 2>/dev/null | grep origin | head -n 1 || true)
if [ -n "$remote" ]; then
    print_status "$GREEN" "OK" "Git remote configured"
    echo "  $remote"
else
    print_status "$YELLOW" "WARN" "No git remote found yet"
fi

echo ""
echo "Checking database schemas..."

if [ -f "database.sql" ]; then
    lines=$(wc -l < database.sql)
    tables=$(grep -c "CREATE TABLE" database.sql || true)
    print_status "$GREEN" "OK" "database.sql found ($tables tables, $lines lines)"
fi

if [ -f "database.postgres.sql" ]; then
    pg_lines=$(wc -l < database.postgres.sql)
    pg_tables=$(grep -c "CREATE TABLE" database.postgres.sql || true)
    print_status "$GREEN" "OK" "database.postgres.sql found ($pg_tables tables, $pg_lines lines)"
fi

echo ""
echo "Configuration Summary:"
echo "======================"
echo "Deployment Platform: Render Blueprint"
echo "Web Runtime: Docker"
echo "Primary Render Database: Postgres"
echo "Database Bootstrap: automatic on container start"
echo "Health Check: /healthz.php"
echo ""

if [ $errors -eq 0 ]; then
    print_status "$GREEN" "READY" "Repository looks ready for Render deployment"
    echo ""
    echo "Next steps:"
    echo "1. Push this branch to GitHub"
    echo "2. Create a Render Blueprint from the repo"
    echo "3. Let the first boot import database.postgres.sql automatically"
    echo "4. Sign in with the seeded admin account and change the password"
else
    print_status "$RED" "FAILED" "Found $errors blocking issue(s)"
    exit 1
fi
