#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <database> <backup-directory>" >&2
  exit 2
fi

database=$1
backup_directory=$2
recipient=${AMS_BACKUP_GPG_RECIPIENT:-}
if [[ ! $database =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Database name must contain only letters, digits, and underscores." >&2
  exit 2
fi
if [[ -z $recipient ]]; then
  echo "Set AMS_BACKUP_GPG_RECIPIENT to a trusted GPG recipient before backup." >&2
  exit 2
fi
mkdir -p -- "$backup_directory"
timestamp="$(date -u +%Y%m%dT%H%M%S%NZ)-$$-${RANDOM}"
base="${database}-${timestamp}.sql.gz.gpg"
temporary_file=$(mktemp "${backup_directory}/.ams-backup.XXXXXX")
trap 'rm -f -- "$temporary_file"' EXIT

mysqldump --login-path=ams-backup \
  --single-transaction --routines --triggers --events --hex-blob \
  "$database" \
  | gzip -c \
  | gpg --batch --yes --encrypt --recipient "$recipient" --output "$temporary_file"

destination="${backup_directory}/${base}"
if [[ -e $destination ]]; then
  echo "Refusing to overwrite existing backup: $destination" >&2
  exit 1
fi
mv -- "$temporary_file" "$destination"
(
  cd -- "$backup_directory"
  sha256sum "$base" > "${base}.sha256"
)
chmod 600 -- "${backup_directory}/${base}" "${backup_directory}/${base}.sha256"

asset_paths=()
[[ ! -d writable/uploads ]] || asset_paths+=(writable/uploads)
[[ ! -f writable/keys/integration-settings.key ]] || asset_paths+=(writable/keys/integration-settings.key)

if (( ${#asset_paths[@]} > 0 )); then
  if find "${asset_paths[@]}" -type l -print -quit | grep -q .; then
    echo "Refusing to archive application assets containing symbolic links." >&2
    exit 1
  fi
  asset_base="ams-files-${timestamp}.tar.gz.gpg"
  asset_temporary=$(mktemp "${backup_directory}/.ams-files.XXXXXX")
  trap 'rm -f -- "$temporary_file" "$asset_temporary"' EXIT
  tar --create --gzip --file=- "${asset_paths[@]}" \
    | gpg --batch --yes --encrypt --recipient "$recipient" --output "$asset_temporary"
  asset_destination="${backup_directory}/${asset_base}"
  if [[ -e $asset_destination ]]; then
    echo "Refusing to overwrite existing files backup: $asset_destination" >&2
    exit 1
  fi
  mv -- "$asset_temporary" "$asset_destination"
  (
    cd -- "$backup_directory"
    sha256sum "$asset_base" > "${asset_base}.sha256"
  )
  chmod 600 -- "${backup_directory}/${asset_base}" "${backup_directory}/${asset_base}.sha256"
fi

echo "Encrypted database backup created: ${backup_directory}/${base}"
if (( ${#asset_paths[@]} > 0 )); then
  echo "Encrypted application files backup created: ${backup_directory}/${asset_base}"
fi
