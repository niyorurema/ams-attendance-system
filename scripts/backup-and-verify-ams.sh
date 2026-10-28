#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <database> <backup-directory>" >&2
  exit 2
fi

script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
database=$1
backup_directory=$2
output=$(bash "$script_directory/backup-ams.sh" "$database" "$backup_directory")
printf '%s\n' "$output"

backup_file=$(printf '%s\n' "$output" \
  | sed -n 's/^Encrypted database backup created: //p' \
  | tail -n 1)
if [[ -z $backup_file ]]; then
  echo "Backup completed without reporting a verifiable database archive." >&2
  exit 1
fi

bash "$script_directory/verify-ams-backup.sh" "$backup_file"
