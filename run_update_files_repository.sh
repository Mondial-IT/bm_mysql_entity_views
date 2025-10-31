#!/usr/bin/env bash
set -euo pipefail
echo "==> init_update_files_repository.sh ______________________

 \"copy sites/default/files to a Mondial-it/gh_d11_lam_files.git repository on GitHub.\"

 Copies the relevant sites/default/files to a git repository and pushes it to GitHub.
 Used to provide a fresh Drupal installation with files from an existing instance.
 The files are copied to .files and then pushed to a repository.
 The .files directory can then be used to populate the sites/default/files directory.
 It clears any existing .files directory first AND overwrites the remote repository.

Usage:
from git_root,
  composer update-files-repository

* Ensure you have SSH access to the remote repository.
* Ensure you have write access to the remote repository.
* Ensure the remote repository is empty or you are okay with overwriting its contents.

* Ensure this instance sites/default/files contains the files you want to copy.
"
if [[ ! "${1-}" = "--run" ]]; then
  echo "" >&2
  echo " use with argument --run to execute" >&2
  exit 1
fi

REMOTE_URL="${REMOTE_URL:-git@github.com:Mondial-it/gh_d11_lam_files.git}"
REMOTE_NAME="${REMOTE_NAME:-gh_d11_lam_files.git}"
BRANCH="main"
GIT_NAME="${GIT_NAME:-theCIO}"
GIT_EMAIL="${GIT_EMAIL:-joelbox@users.noreply.github.com}"


echo "==> init_update_files_repository.sh  ______________________"
echo "To provide a fresh Drupal installation with files from an existing instance."
echo -e "This will copy the relevant images from the drupal_root/web/\sites/\default/\files to .files."
echo "and save it as git repository in gitHub."
echo ""
echo "Using remote URL: ${REMOTE_URL} with name: ${REMOTE_NAME} on branch: ${BRANCH}"
echo "Using git user.name: ${GIT_NAME} and user.email: ${GIT_EMAIL}"

if [[ ! -d web ]]; then
  echo "❌ run this from the git_root directory."
  exit 1
fi

echo "-- deleting .files/.git if it exists"
rm -rf .files|| true
echo "-- into .files"
mkdir .files || true
cd .files || exit 1
echo "initializing new git repository"

git init
git config user.name  "$GIT_NAME"
git config user.email "$GIT_EMAIL"
git checkout -b "$BRANCH"


echo "
/config_*/
/css/
/js/
/languages/
/temporary/
/backup_migrate/
/styles/
/translations/
.htaccess
/php/
*.php
" > .gitignore
echo "Created .gitignore"

echo "copy to .files using the .gitignore"
#!/bin/bash
# copy-with-gitignore-cp.sh
# Usage: ./copy-with-gitignore-cp.sh <source> <destination>

SOURCE="../drupal_root/web/sites/default/files"
DEST="."

if [ -z "$SOURCE" ] || [ -z "$DEST" ]; then
  echo "Usage: $0 <source> <destination>"
  exit 1
fi

if [ ! -d "$SOURCE" ]; then
  echo "Source directory does not exist."
  exit 1
fi

mkdir -p "$DEST"

# Load .gitignore patterns
IGNORE_FILE="$DEST/.gitignore"
if [ ! -f "$IGNORE_FILE" ]; then
  echo "No .gitignore found, copying everything..."
  exit 1
fi

# Build grep ignore pattern
PATTERN=$(grep -v '^#' "$IGNORE_FILE" | grep -v '^$' | sed 's|/|\\/|g' | paste -sd'|' -)

# Copy files not matching .gitignore
find "$SOURCE" -type f | grep -Ev "$PATTERN" | while read -r FILE; do
  REL_PATH="${FILE#$SOURCE/}"
  DEST_PATH="$DEST/$REL_PATH"
  mkdir -p "$(dirname "$DEST_PATH")"
  echo "Copying: $FILE"
  cp "$FILE" "$DEST_PATH"
done

# cp -vr ../drupal_root/web/sites/default/files/* .

echo "-- adding selected files to git"
git add -A >null 2>&1
git commit -m "Fresh copy from drupal_root/web/sites/default/files on $(date '+%Y-%m-%d %H:%M:%S')"

git remote add "$REMOTE_NAME" "$REMOTE_URL"
echo "-- pushing $BRANCH to $REMOTE_NAME  --force"
git push -u "$REMOTE_NAME" "$BRANCH" --force
echo "OK"
