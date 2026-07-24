#!/bin/bash
set -e

DB_HOST="${DB_HOST:-mariadb-primary}"
DB_USER="${DB_USER:-openvk}"
DB_PASSWORD="${DB_PASSWORD:-openvk}"
DB_NAME="${DB_NAME:-db}"

cd /opt/chandler/extensions/available/openvk

# Wait for MariaDB
echo "Waiting for MariaDB..."
until mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" --ssl=0 --default-character-set=utf8mb4 -e "SELECT 1" &>/dev/null; do
    sleep 2
done
echo "MariaDB ready."

# Run schema migrations (creates all tables + admin user)
./openvkctl upgrade --no-interaction --quick

# Import deterministic test seed data (idempotent — container recreate must not fail)
echo "Importing seed data..."
if mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" --ssl=0 --default-character-set=utf8mb4 \
  -N -e "SELECT COUNT(*) FROM profiles WHERE id=2" 2>/dev/null | grep -qx '0'; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" --ssl=0 --default-character-set=utf8mb4 < tests/seed-data.sql
  echo "Seed data imported."
else
  echo "Seed data already present, skipping."
fi

# Seed drops UUID triggers then recreates them at the end. If a previous run
# imported profiles but died before CREATE TRIGGER, login breaks (ChandlerTokens.token).
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" --ssl=0 --default-character-set=utf8mb4 -e "
DROP TRIGGER IF EXISTS bfiu_users;
DROP TRIGGER IF EXISTS bfiu_groups;
DROP TRIGGER IF EXISTS bfiu_tokens;
CREATE TRIGGER bfiu_users  BEFORE INSERT ON ChandlerUsers  FOR EACH ROW SET new.id = uuid();
CREATE TRIGGER bfiu_groups BEFORE INSERT ON ChandlerGroups FOR EACH ROW SET new.id = uuid();
CREATE TRIGGER bfiu_tokens BEFORE INSERT ON ChandlerTokens FOR EACH ROW SET new.token = uuid();
" || true

# Ensure longpoll/Imagick lock dir exists (sticky bit if chown is blocked)
mkdir -p /opt/chandler/extensions/available/openvk/tmp
chmod 1777 /opt/chandler/extensions/available/openvk/tmp 2>/dev/null || true

# Start Apache
exec apache2-foreground
