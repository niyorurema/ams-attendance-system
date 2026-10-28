#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <backup.sql.gz.gpg>" >&2
  exit 2
fi

backup_file=$1
if [[ ! -f $backup_file || $backup_file != *.sql.gz.gpg ]]; then
  echo "Provide an existing encrypted .sql.gz.gpg backup file." >&2
  exit 2
fi
restore_login_path=${AMS_RESTORE_MYSQL_LOGIN_PATH:-ams-restore}
staging_root=${AMS_RESTORE_STAGING_DIR:-${TMPDIR:-/tmp}}
mkdir -p -- "$staging_root"

checksum_file="${backup_file}.sha256"
if [[ ! -f $checksum_file ]]; then
  echo "Backup checksum file not found: $checksum_file" >&2
  exit 2
fi
(
  cd -- "$(dirname -- "$backup_file")"
  sha256sum --check -- "$(basename -- "$checksum_file")"
)

restore_database="ams_restore_verify_$(date -u +%Y%m%d%H%M%S)_${RANDOM}"
if ! [[ $restore_database =~ ^ams_restore_verify_[A-Za-z0-9_]+$ ]]; then
  echo "Generated restore database name is invalid." >&2
  exit 2
fi

exists=$(mysql --login-path="$restore_login_path" --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${restore_database}'")
if [[ $exists != 0 ]]; then
  echo "Refusing to overwrite existing restore database: $restore_database" >&2
  exit 1
fi

mysql --login-path="$restore_login_path" --execute="CREATE DATABASE \`${restore_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
if ! gpg --batch --decrypt --quiet -- "$backup_file" \
  | gzip --decompress --stdout \
  | mysql --login-path="$restore_login_path" "$restore_database"; then
  echo "Restore failed. The isolated database remains for diagnosis: $restore_database" >&2
  exit 1
fi

for table in migrations tenants users employes pointages; do
  found=$(mysql --login-path="$restore_login_path" --batch --skip-column-names "$restore_database" \
    --execute="SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${restore_database}' AND TABLE_NAME='${table}'")
  if [[ $found != 1 ]]; then
    echo "Expected table '$table' is missing from $restore_database." >&2
    exit 1
  fi
done

if ! mysql --login-path="$restore_login_path" --batch --skip-column-names "$restore_database" \
  --execute='CHECK TABLE migrations, tenants, users, employes, pointages' \
  | awk -F '\t' 'BEGIN { valid = 1 } $4 != "OK" { valid = 0; exit 1 } END { if (NR == 0 || valid == 0) exit 1 }'; then
  echo "Database integrity check reported a non-OK table." >&2
  exit 1
fi

backup_name=$(basename -- "$backup_file")
backup_stem=${backup_name%.sql.gz.gpg}
backup_timestamp=${backup_stem#*-}
assets_file="$(dirname -- "$backup_file")/ams-files-${backup_timestamp}.tar.gz.gpg"
assets_destination=''
if [[ -f $assets_file ]]; then
  assets_checksum="${assets_file}.sha256"
  if [[ ! -f $assets_checksum ]]; then
    echo "Application assets checksum file not found: $assets_checksum" >&2
    exit 2
  fi
  (
    cd -- "$(dirname -- "$assets_file")"
    sha256sum --check -- "$(basename -- "$assets_checksum")"
  )

  assets_destination=$(mktemp -d "${staging_root%/}/ams_restore_files_XXXXXX")
  chmod 700 -- "$assets_destination"
  encrypted_archive=$(mktemp --suffix=.tar.gz "${assets_destination}/.assets.XXXXXX")
  trap 'rm -f -- "$encrypted_archive"' EXIT
  gpg --batch --decrypt --quiet -- "$assets_file" > "$encrypted_archive"
  while IFS= read -r entry; do
    if [[ $entry == /* ]] || [[ "/$entry/" == */../* ]]; then
      echo "Unsafe path in application asset archive: $entry" >&2
      exit 1
    fi
    case "$entry" in
      writable|writable/|writable/uploads|writable/uploads/*|writable/keys|writable/keys/|writable/keys/integration-settings.key) ;;
      *)
        echo "Unexpected path in application asset archive: $entry" >&2
        exit 1
        ;;
    esac
  done < <(tar --list --quoting-style=escape --gzip --file="$encrypted_archive")
  if tar --verbose --quoting-style=escape --list --gzip --file="$encrypted_archive" \
    | awk 'substr($0, 1, 1) !~ /^[d-]$/ { exit 1 }'; then
    tar --extract --gzip --file="$encrypted_archive" --directory="$assets_destination" \
      --no-same-owner --no-same-permissions
  else
    echo "Application asset archive contains a link or special file." >&2
    exit 1
  fi
  rm -f -- "$encrypted_archive"
  if [[ -d $assets_destination/writable/uploads ]]; then
    find "$assets_destination/writable/uploads" -type l -print -quit | grep -q . && {
      echo "Restored uploads contain a symbolic link." >&2
      exit 1
    }
  fi
  if [[ -e $assets_destination/writable/keys/integration-settings.key \
      && ! -s $assets_destination/writable/keys/integration-settings.key ]]; then
    echo "Restored integration-settings key is empty." >&2
    exit 1
  fi
  echo "Encrypted application assets restored into isolated staging: $assets_destination"
else
  echo "No paired application asset archive found; confirm the source backup had no uploads or integration key." >&2
fi

echo "Restore verified in isolated database: $restore_database"
echo "Inspect both isolated restore targets before cleanup; this script never drops databases."
